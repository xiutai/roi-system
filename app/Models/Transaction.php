<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Transaction extends Model
{
    use HasFactory;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'currency',
        'member_id',
        'member_account',
        'channel_id',
        'registration_source',
        'registration_time',
        'balance_difference',
    ];

    /**
     * 应该被转换为日期的属性
     *
     * @var array
     */
    protected $dates = [
        'registration_time',
    ];

    /**
     * 获取该交易记录所属的渠道
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * 获取指定日期和渠道的总充提差额
     *
     * @param string $date
     * @param int|null $channelId
     * @return float
     */
    public static function getTotalBalanceForDateAndChannel($date, $channelId = null)
    {
        $startDate = Carbon::parse($date)->startOfDay();
        $endDate = Carbon::parse($date)->endOfDay();
        
        $query = self::whereBetween('registration_time', [$startDate, $endDate]);
        
        if ($channelId) {
            $query->where('channel_id', $channelId);
        }
        
        return $query->sum('balance_difference');
    }

    /**
     * 获取指定日期范围和渠道的累计充提差额
     *
     * @param string $startDate
     * @param string $endDate
     * @param int|null $channelId
     * @return float
     */
    public static function getCumulativeBalanceForDateRangeAndChannel($startDate, $endDate, $channelId = null)
    {
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($endDate)->endOfDay();
        
        $query = self::whereBetween('registration_time', [$startDate, $endDate]);
        
        if ($channelId) {
            $query->where('channel_id', $channelId);
        }
        
        return $query->sum('balance_difference');
    }

    /**
     * 批量获取多个日期范围和渠道的累计充提差额
     * 
     * @param array $dateRanges 格式: [['start' => '2023-01-01', 'end' => '2023-01-05', 'channel_id' => 1], ...]
     * @return array 格式: ['2023-01-01_2023-01-05_1' => 值, ...]
     */
    public static function getBatchCumulativeBalances(array $dateRanges)
    {
        if (empty($dateRanges)) {
            return [];
        }
        
        $results = [];
        $channelGroups = [];
        
        // 将请求按渠道分组，减少查询次数
        foreach ($dateRanges as $range) {
            $startDate = $range['start'];
            $endDate = $range['end'];
            $channelId = $range['channel_id'];
            
            $key = "{$startDate}_{$endDate}_{$channelId}";
            $results[$key] = 0; // 初始化结果
            
            if (!isset($channelGroups[$channelId])) {
                $channelGroups[$channelId] = [];
            }
            
            $channelGroups[$channelId][] = [
                'start' => Carbon::parse($startDate)->startOfDay(),
                'end' => Carbon::parse($endDate)->endOfDay(),
                'key' => $key
            ];
        }
        
        // 对每个渠道执行一次查询，获取所有日期范围的数据
        foreach ($channelGroups as $channelId => $ranges) {
            // 获取该渠道的所有交易记录
            $minDate = min(array_map(function($range) {
                return $range['start'];
            }, $ranges));
            
            $maxDate = max(array_map(function($range) {
                return $range['end'];
            }, $ranges));
            
            // 获取该日期范围内的所有交易记录
            $transactions = self::where('channel_id', $channelId)
                ->whereBetween('registration_time', [$minDate, $maxDate])
                ->select('registration_time', 'balance_difference')
                ->get();
            
            // 计算每个日期范围的累计余额
            foreach ($ranges as $range) {
                $startDate = $range['start'];
                $endDate = $range['end'];
                $key = $range['key'];
                
                $sum = $transactions->filter(function($transaction) use ($startDate, $endDate) {
                    $regTime = $transaction->registration_time;
                    return $regTime >= $startDate && $regTime <= $endDate;
                })->sum('balance_difference');
                
                $results[$key] = $sum;
            }
        }
        
        return $results;
    }
}
