<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'rate',
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
     * 获取指定日期的汇率
     *
     * @param string $date
     * @return float
     */
    public static function getRateForDate($date)
    {
        $rate = self::where('date', $date)->first();
        
        if (!$rate) {
            // 如果没有找到指定日期的汇率，则使用默认值
            $defaultRate = self::where('is_default', true)->first();
            
            if ($defaultRate) {
                return $defaultRate->rate;
            }
            
            // 如果没有设置默认值，则返回0
            return 0;
        }
        
        return $rate->rate;
    }
}
