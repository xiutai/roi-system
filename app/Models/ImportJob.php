<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class ImportJob extends Model
{
    use HasFactory;

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'filename',
        'original_filename',
        'status',
        'total_rows',
        'processed_rows',
        'inserted_rows',
        'updated_rows',
        'error_rows',
        'replaced_rows',
        'error_message',
        'error_details',
        'user_id',
        'insert_date',
        'is_replacing_existing',
        'started_at',
        'completed_at',
    ];

    /**
     * 应该被转换为日期的属性
     *
     * @var array
     */
    protected $dates = [
        'started_at',
        'completed_at',
        'insert_date',
    ];

    /**
     * 获取关联的用户
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 获取进度百分比
     * 
     * @return int
     */
    public function getProgressPercentageAttribute()
    {
        if ($this->total_rows > 0) {
            return min(100, round(($this->processed_rows / $this->total_rows) * 100));
        }
        return 0;
    }

    /**
     * 任务是否已完成
     * 
     * @return bool
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * 任务是否正在处理中
     * 
     * @return bool
     */
    public function isProcessing()
    {
        return $this->status === 'processing';
    }

    /**
     * 任务是否已失败
     * 
     * @return bool
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * 任务是否处于等待状态
     * 
     * @return bool
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * 将错误详情JSON转换为数组
     * 
     * @return array
     */
    public function getErrorDetailsArrayAttribute()
    {
        if (empty($this->error_details)) {
            return [];
        }
        
        try {
            return json_decode($this->error_details, true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
} 