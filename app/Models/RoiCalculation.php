<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $startDate = Carbon::parse($date)->format('Y-m-d');
        
        // 计算应该使用哪一天的数据来计算ROI
        // N日ROI需要使用(注册日期+N)这一天上传的数据
        $dataDate = Carbon::parse($date)->addDays($dayCount)->format('Y-m-d');
        
        // 检查是否存在该日期的数据
        $hasData = Transaction::where('insert_date', $dataDate)->exists();
        
        // 如果不存在该日期的数据且不是40日后的ROI，则不计算
        if (!$hasData && $dayCount < 40) {
            return;
        }
        
        // 如果是40日后的ROI，则使用最新一天的数据
        if ($dayCount >= 40) {
            $dataDate = Transaction::max('insert_date');
            if (!$dataDate) {
                return; // 如果没有任何数据，则不计算
            }
            $dataDate = Carbon::parse($dataDate)->format('Y-m-d');
        }
        
        // 获取起始日期的汇率
        $exchangeRate = ExchangeRate::getRateForDate($startDate);
        
        // 获取起始日期的消耗数据
        $expense = Expense::getExpenseForDateAndChannel($startDate, $channelId);
        
        // 获取指定日期数据中，startDate注册的用户截至dataDate的累计充提差额
        $cumulativeBalance = Transaction::where('insert_date', $dataDate)
            ->where('channel_id', $channelId)
            ->whereDate('registration_time', $startDate)
            ->sum('balance_difference');
        
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
        
        // 获取系统中存在的数据日期
        $existingDataDates = Transaction::select(DB::raw('DISTINCT DATE(insert_date) as date_only'))
            ->get()
            ->pluck('date_only')
            ->flip()
            ->toArray();

        Log::info('存在的数据日期: ' . print_r(array_keys($existingDataDates), true));
        
        // 对于40日后的ROI，使用最新一天的数据
        $latestDataDate = Transaction::max('insert_date');
        $latestDataDate = $latestDataDate ? Carbon::parse($latestDataDate)->format('Y-m-d') : null;
        
        Log::info('最新数据日期: ' . $latestDataDate);
        
        // 批量获取消耗数据和汇率数据
        $startDates = $dates; // 使用计算日期作为起始日期
        
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
        
        // 输出消耗和汇率数据用于调试
        Log::info('消耗数据: ' . print_r($expenseData, true));
        Log::info('汇率数据: ' . print_r($rateData, true));
        Log::info('默认汇率: ' . $defaultRateValue);
        
        // 批量生成ROI计算结果
        $batchData = [];
        $now = now();
        $processedCount = 0;
        $batchSize = 1000;
        
        foreach ($dates as $date) {
            foreach ($channelIds as $channelId) {
                // 先获取当日的消耗和汇率，用于所有天数的计算
            // 获取消耗
                $expense = isset($expenseData[$date][$channelId]) ? $expenseData[$date][$channelId] :
                         (isset($expenseData['default'][$channelId]) ? $expenseData['default'][$channelId] : 0);
            
            // 获取汇率
                $exchangeRate = isset($rateData[$date]) ? $rateData[$date] : $defaultRateValue;
                
                Log::info("处理日期: {$date}, 渠道: {$channelId}, 消耗: {$expense}, 汇率: {$exchangeRate}");
                
                // 只有当有消耗和汇率时才计算ROI
                if ($expense <= 0 || $exchangeRate <= 0) {
                    Log::warning("跳过计算，消耗或汇率为0: {$date}, 渠道: {$channelId}");
                    continue;
                }

                // 计算当日ROI (day_count = 1)
                // 当日ROI：使用当天的数据计算当天注册用户的充提差额
                $dataDate = $date;
                if (!array_key_exists($dataDate, $existingDataDates)) {
                    Log::info("无当日数据: {$dataDate}, 日期: {$date}, 渠道: {$channelId}, ROI设为0");
                    $currentDateBalance = 0;
                    $currentDayRoiPercentage = 0;
                } else {
                    $currentDateBalance = Transaction::where('insert_date', $dataDate)
                        ->where('channel_id', $channelId)
                        ->whereDate('registration_time', $date)
                        ->sum('balance_difference');
                    
                    // 修复公式：直接使用(余额/汇率)/消费*100计算真实ROI百分比
                    $currentDayRoiPercentage = 0;
                    if ($expense > 0 && $exchangeRate > 0) {
                        $currentDayRoiPercentage = ($currentDateBalance / $exchangeRate) / $expense * 100; // 移除乘以10的修正因子
                    }
                }
                
                Log::info("当日ROI计算 - 日期: {$date}, 渠道: {$channelId}, 数据日期: {$dataDate}, 余额: {$currentDateBalance}, ROI: {$currentDayRoiPercentage}%");
                
                // 保存当日ROI（day_count = 1）
                $batchData[] = [
                    'date' => $date,
                    'channel_id' => $channelId,
                    'day_count' => 1,
                    'cumulative_balance' => $currentDateBalance,
                    'exchange_rate' => $exchangeRate,
                    'expense' => $expense,
                    'roi_percentage' => $currentDayRoiPercentage,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $processedCount++;
                
                // 多日ROI计算（从day_count = 2开始）
                for ($dayCount = 2; $dayCount <= $maxDays; $dayCount++) {
                    // 新算法：N日ROI使用(注册日期+N-1)天的数据
                    // N日ROI需要使用注册日期后第N天上传的数据
                    $dataDate = Carbon::parse($date)->addDays($dayCount - 1)->format('Y-m-d');
                    
                    // 检查该数据日期是否存在
                    if (!array_key_exists($dataDate, $existingDataDates)) {
                        // 如果是40日及以后的ROI，使用最新一天的数据
                        if ($dayCount >= 40 && $latestDataDate) {
                            $dataDate = $latestDataDate;
                            Log::info("使用最新数据日期计算40日后ROI: {$dataDate}, 日期: {$date}, 渠道: {$channelId}");
                        } else {
                            // 对于其他天数，如果没有对应日期的数据，ROI为0
                            Log::info("无数据日期: {$dataDate}, 日期: {$date}, 渠道: {$channelId}, 天数: {$dayCount}, ROI设为0");
                            $batchData[] = [
                                'date' => $date,
                                'channel_id' => $channelId,
                                'day_count' => $dayCount,
                                'cumulative_balance' => 0,
                                'exchange_rate' => $exchangeRate,
                                'expense' => $expense,
                                'roi_percentage' => 0,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            $processedCount++;
                            continue;
                        }
                    }
                    
                    // 获取该渠道在该起始日期注册的用户在数据日期的充提差额总和
                    $cumulativeBalance = Transaction::where('insert_date', $dataDate)
                        ->where('channel_id', $channelId)
                        ->whereDate('registration_time', $date)
                        ->sum('balance_difference');
            
                    // 计算ROI百分比 - 确保使用正确的计算方式
                    $roiPercentage = 0;
                    if ($expense > 0 && $exchangeRate > 0) {
                        $roiPercentage = ($cumulativeBalance / $exchangeRate) / $expense * 100; // 移除乘以10的修正因子
                    }
                    
                    Log::info("多日ROI计算 - 天数: {$dayCount}, 日期: {$date}, 渠道: {$channelId}, 数据日期: {$dataDate}, 余额: {$cumulativeBalance}, ROI: {$roiPercentage}%");
            
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
            
                    $processedCount++;
                    
                    // 批量插入数据，避免一次性插入太多
            if (count($batchData) >= $batchSize) {
                self::insert($batchData);
                $batchData = [];
                    }
                }
            }
        }
        
        // 插入剩余的数据
        if (!empty($batchData)) {
            self::insert($batchData);
        }
        
        return $processedCount;
    }
}
