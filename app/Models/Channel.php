<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasFactory;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * 获取该渠道的所有消耗记录
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * 获取该渠道的所有ROI计算记录
     */
    public function roiCalculations()
    {
        return $this->hasMany(RoiCalculation::class);
    }

    /**
     * 获取该渠道的所有交易记录
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
    
    /**
     * 获取该渠道的交易数量
     * 
     * @return int
     */
    public function getTransactionCountAttribute()
    {
        return $this->transactions()->count();
    }
    
    /**
     * 获取该渠道的总充提差额
     * 
     * @return float
     */
    public function getTotalBalanceAttribute()
    {
        return $this->transactions()->sum('balance_difference');
    }
}
