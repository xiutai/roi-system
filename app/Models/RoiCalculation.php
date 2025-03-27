<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoiCalculation extends Model
{
    use HasFactory;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'channel_id',
        'day_count',
        'cumulative_balance',
        'exchange_rate',
        'expense',
        'roi_percentage',
    ];

    /**
     * 应该被转换为日期的属性
     *
     * @var array
     */
    protected $dates = [
        'date',
    ];

    /**
     * 获取该ROI计算记录所属的渠道
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * 计算ROI
     *
     * @param string $date 计算的日期
     * @param int $channelId 渠道ID
     * @param int $dayCount 天数（1日/2日/3日...）
     * @return void
     */
    public static function calculateRoi($date, $channelId, $dayCount)
    {
        // 获取起始日期（从哪一天开始累计）
        $startDate = Carbon::parse($date)->subDays($dayCount - 1)->format('Y-m-d');
        
        // 获取起始日期的汇率
        $exchangeRate = ExchangeRate::getRateForDate($startDate);
        
        // 获取起始日期的消耗数据
        // 注意：这里使用的是起始日期的消耗，不是计算日期的消耗
        $expense = Expense::getExpenseForDateAndChannel($startDate, $channelId);
        
        // 获取从起始日期到计算日期的累计充提差额
        $cumulativeBalance = Transaction::getCumulativeBalanceForDateRangeAndChannel($startDate, $date, $channelId);
        
        // 计算ROI百分比
        $roiPercentage = 0;
        if ($expense > 0 && $exchangeRate > 0) {
            $roiPercentage = (($cumulativeBalance / $exchangeRate) / $expense) * 100;
        }
        
        // 创建或更新ROI计算记录
        self::updateOrCreate(
            [
                'date' => $date,
                'channel_id' => $channelId,
                'day_count' => $dayCount,
            ],
            [
                'cumulative_balance' => $cumulativeBalance,
                'exchange_rate' => $exchangeRate,
                'expense' => $expense,
                'roi_percentage' => $roiPercentage,
            ]
        );
    }

    /**
     * 批量计算指定日期和渠道的所有天数的ROI
     *
     * @param string $date 计算的日期
     * @param int $channelId 渠道ID
     * @param int $maxDays 最大天数，默认为40天
     * @return void
     */
    public static function calculateAllRoisForDateAndChannel($date, $channelId, $maxDays = 40)
    {
        for ($i = 1; $i <= $maxDays; $i++) {
            self::calculateRoi($date, $channelId, $i);
        }
    }
    
    /**
     * 优化版的批量计算ROI
     * 
     * @param array $dates 日期数组
     * @param array $channelIds 渠道ID数组
     * @param int $maxDays 最大天数
     * @return int 处理的记录数
     */
    public static function batchCalculateRois(array $dates, array $channelIds, $maxDays = 40)
    {
        // 清除所有指定日期和渠道的ROI记录
        self::whereIn('date', $dates)
            ->whereIn('channel_id', $channelIds)
            ->delete();
        
        // 预加载所有需要的消耗数据
        $startDates = [];
        foreach ($dates as $date) {
            for ($i = 1; $i <= $maxDays; $i++) {
                $startDate = Carbon::parse($date)->subDays($i - 1)->format('Y-m-d');
                $startDates[] = $startDate;
            }
        }
        $startDates = array_unique($startDates);
        
        // 批量获取消耗数据
        $expenseData = [];
        $expenses = Expense::whereIn('date', $startDates)
            ->whereIn('channel_id', $channelIds)
            ->get();
            
        foreach ($expenses as $expense) {
            $dateStr = $expense->date->format('Y-m-d');
            $expenseData[$dateStr][$expense->channel_id] = $expense->amount;
        }
        
        // 获取默认消耗数据
        $defaultExpenses = Expense::where('is_default', true)
            ->whereIn('channel_id', $channelIds)
            ->get();
            
        foreach ($defaultExpenses as $defaultExpense) {
            $expenseData['default'][$defaultExpense->channel_id] = $defaultExpense->amount;
        }
        
        // 批量获取汇率数据
        $rateData = [];
        $rates = ExchangeRate::whereIn('date', $startDates)->get();
        
        foreach ($rates as $rate) {
            $dateStr = $rate->date->format('Y-m-d');
            $rateData[$dateStr] = $rate->rate;
        }
        
        // 获取默认汇率
        $defaultRate = ExchangeRate::where('is_default', true)->first();
        $defaultRateValue = $defaultRate ? $defaultRate->rate : 0;
        
        // 准备批量查询累计充提差额所需的数据
        $balanceQueries = [];
        foreach ($dates as $date) {
            foreach ($channelIds as $channelId) {
                for ($dayCount = 1; $dayCount <= $maxDays; $dayCount++) {
                    $startDate = Carbon::parse($date)->subDays($dayCount - 1)->format('Y-m-d');
                    $balanceQueries[] = [
                        'start' => $startDate,
                        'end' => $date,
                        'channel_id' => $channelId,
                        'day_count' => $dayCount,
                        'date' => $date
                    ];
                }
            }
        }
        
        // 批量获取累计充提差额
        $balanceResults = Transaction::getBatchCumulativeBalances($balanceQueries);
        
        // 批量生成ROI计算结果
        $batchData = [];
        $now = now();
        $processedCount = 0;
        $batchSize = 1000;
        
        foreach ($balanceQueries as $query) {
            $date = $query['date'];
            $channelId = $query['channel_id'];
            $dayCount = $query['day_count'];
            $startDate = $query['start'];
            
            // 生成查询键
            $balanceKey = "{$startDate}_{$date}_{$channelId}";
            
            // 获取消耗
            $expense = $expenseData[$startDate][$channelId] ?? 
                     ($expenseData['default'][$channelId] ?? 0);
            
            // 获取汇率
            $exchangeRate = $rateData[$startDate] ?? $defaultRateValue;
            
            // 获取累计充提差额
            $cumulativeBalance = $balanceResults[$balanceKey] ?? 0;
            
            // 计算ROI百分比
            $roiPercentage = 0;
            if ($expense > 0 && $exchangeRate > 0) {
                $roiPercentage = (($cumulativeBalance / $exchangeRate) / $expense) * 100;
            }
            
            // 准备批量插入的数据
            $batchData[] = [
                'date' => $date,
                'channel_id' => $channelId,
                'day_count' => $dayCount,
                'cumulative_balance' => $cumulativeBalance,
                'exchange_rate' => $exchangeRate,
                'expense' => $expense,
                'roi_percentage' => $roiPercentage,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            
            // 达到批处理大小则插入数据
            if (count($batchData) >= $batchSize) {
                self::insert($batchData);
                $processedCount += count($batchData);
                $batchData = [];
            }
        }
        
        // 插入剩余数据
        if (!empty($batchData)) {
            self::insert($batchData);
            $processedCount += count($batchData);
        }
        
        return $processedCount;
    }
}
