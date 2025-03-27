<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
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
        'amount',
        'is_default',
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
     * 获取该消耗记录所属的渠道
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * 获取指定日期和渠道的消耗金额
     *
     * @param string $date
     * @param int $channelId
     * @return float
     */
    public static function getExpenseForDateAndChannel($date, $channelId)
    {
        // 只查询指定日期和渠道的消耗
        $expense = self::where('date', $date)
                       ->where('channel_id', $channelId)
                       ->first();
        
        if (!$expense) {
            // 如果没有找到指定日期和渠道的消耗，则使用该渠道的默认消耗
            $defaultExpense = self::where('is_default', true)
                                  ->where('channel_id', $channelId)
                                  ->first();
            
            if ($defaultExpense) {
                return $defaultExpense->amount;
            }
            
            // 如果没有设置默认值，则返回0
            return 0;
        }
        
        return $expense->amount;
    }
}
