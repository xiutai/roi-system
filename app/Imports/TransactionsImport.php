<?php

namespace App\Imports;

use App\Models\Transaction;
use App\Models\Channel;
use App\Models\ImportJob;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class TransactionsImport implements ToModel, WithHeadingRow, WithValidation, WithCustomCsvSettings, WithBatchInserts, WithChunkReading
{
    // 缓存渠道ID，避免重复查询
    protected $channelCache = [];
    
    // 批次缓存，用于批量插入
    protected $batchTransactions = [];
    protected $batchSize = 1000;
    protected $currentBatchSize = 0;
    
    // 进度追踪
    protected $importJob;
    protected $processedRows = 0;
    protected $insertedRows = 0;
    protected $updatedRows = 0;
    protected $errorRows = 0;
    
    // 现有会员ID缓存
    protected $existingMemberIds = [];
    
    // 构造函数，预加载所有渠道数据
    public function __construct(ImportJob $importJob = null)
    {
        $this->importJob = $importJob;
        
        // 预加载所有渠道信息到缓存中
        $this->loadChannels();
        
        // 预加载所有会员ID
        $this->existingMemberIds = Transaction::pluck('member_id', 'member_id')->toArray();
        
        Log::info('TransactionsImport初始化', [
            'channels_cached' => count($this->channelCache),
            'members_cached' => count($this->existingMemberIds)
        ]);
    }
    
    /**
     * 预加载所有渠道到缓存
     */
    protected function loadChannels()
    {
        // 从数据库中获取所有渠道并按名称索引
        $channels = Channel::all();
        foreach ($channels as $channel) {
            $this->channelCache[$channel->name] = $channel->id;
        }
    }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // 跟踪进度
        $this->processedRows++;
        
        // 每100行更新一次进度
        if ($this->importJob && $this->processedRows % 100 === 0) {
            $this->updateProgress();
            
            // 每1000行记录一次日志
            if ($this->processedRows % 1000 === 0) {
                Log::info('Excel导入进度', [
                    'processed' => $this->processedRows,
                    'inserted' => $this->insertedRows,
                    'updated' => $this->updatedRows
                ]);
            }
        }
        
        try {
            // 强制对字段名进行UTF-8编码转换，处理可能的编码问题
            $encodedRow = [];
            foreach ($row as $key => $value) {
                // 确保键名为有效的UTF-8字符串
                $encodedKey = $this->ensureCorrectEncoding($key);
                // 确保值为有效的UTF-8字符串
                $encodedValue = $this->ensureCorrectEncoding($value);
                $encodedRow[$encodedKey] = $encodedValue;
            }
            
            // 支持中文字段名映射到系统字段名
            $fieldMap = [
                // 原始字段 => 系统字段
                'bi_zhong' => 'bi_zhong',
                'hui_yuan_id' => 'hui_yuan_id',
                'hui_yuan_zhang_hao' => 'hui_yuan_zhang_hao',
                'zhu_ce_lai_yuan' => 'zhu_ce_lai_yuan',
                'zhu_ce_shi_jian' => 'zhu_ce_shi_jian',
                'zong_chong_ti_cha_e' => 'zong_chong_ti_cha_e',
                
                // 中文字段映射
                '币种' => 'bi_zhong',
                '会员ID' => 'hui_yuan_id',
                '会员账号' => 'hui_yuan_zhang_hao',
                '渠道ID' => 'channel_id_custom', // 保留映射但不使用
                '注册来源' => 'zhu_ce_lai_yuan',
                '注册时间' => 'zhu_ce_shi_jian',
                '总充提差额' => 'zong_chong_ti_cha_e',
            ];
            
            // 将中文字段映射到系统字段
            $mappedRow = [];
            foreach ($encodedRow as $key => $value) {
                if (isset($fieldMap[$key])) {
                    $mappedRow[$fieldMap[$key]] = $value;
                } else {
                    $mappedRow[$key] = $value;
                }
            }
            
            // 查找注册来源字段 - 优先从中文字段获取
            $registrationSource = $mappedRow['zhu_ce_lai_yuan'] ?? null;
            $registrationTime = $mappedRow['zhu_ce_shi_jian'] ?? null;
            $memberId = $mappedRow['hui_yuan_id'] ?? null;
            
            // 检查必要字段是否存在
            if (empty($registrationSource) || empty($registrationTime)) {
                $this->errorRows++;
                return null;
            }
    
            // 获取或创建渠道，并确保使用channels表中的id
            $channelName = $registrationSource;
            if (!isset($this->channelCache[$channelName])) {
                // 先查询，如果不存在再创建，减少数据库操作
                $channel = Channel::firstOrCreate(
                    ['name' => $channelName],
                    ['description' => '从导入数据自动创建']
                );
                $this->channelCache[$channelName] = $channel->id;
            }
            
            // 始终使用channels表中的id
            $channelId = $this->channelCache[$channelName];
            
            // 检查是否已存在此会员ID的记录
            if (!empty($memberId) && isset($this->existingMemberIds[$memberId])) {
                // 更新现有记录
                $this->updatedRows++;
                Transaction::where('member_id', $memberId)->update([
                    'balance_difference' => DB::raw('balance_difference + ' . (is_numeric($mappedRow['zong_chong_ti_cha_e'] ?? 0) ? $mappedRow['zong_chong_ti_cha_e'] : 0))
                ]);
                return null; // 不创建新记录
            }
            
            // 为新记录
            $this->insertedRows++;
            if (!empty($memberId)) {
                $this->existingMemberIds[$memberId] = true; // 添加到缓存中
            }
    
            // 创建交易模型
            return new Transaction([
                'currency' => $mappedRow['bi_zhong'] ?? 'PKR',
                'member_id' => $memberId,
                'member_account' => $mappedRow['hui_yuan_zhang_hao'] ?? '',
                'channel_id' => $channelId,
                'registration_source' => $channelName,
                'registration_time' => $this->transformDate($registrationTime),
                'balance_difference' => is_numeric($mappedRow['zong_chong_ti_cha_e'] ?? 0) ? $mappedRow['zong_chong_ti_cha_e'] : 0,
            ]);
        } catch (\Exception $e) {
            Log::error('处理Excel行数据失败', [
                'error' => $e->getMessage(),
                'row' => $this->processedRows
            ]);
            $this->errorRows++;
            return null;
        }
    }

    /**
     * 确保字符串使用正确的UTF-8编码
     *
     * @param mixed $str
     * @return string
     */
    private function ensureCorrectEncoding($str)
    {
        if (!is_string($str)) {
            return $str;
        }
        
        // 处理Excel导出的特殊格式 ="xxx"
        if (preg_match('/^="(.*)"$/', $str, $matches)) {
            $str = $matches[1];
            // 对于处理过的字符串记录日志（仅在调试需要时启用）
            Log::debug('处理Excel特殊格式', ['original' => $str, 'processed' => $matches[1]]);
        }
        
        // 处理可能的双重引号
        if (strpos($str, '""') !== false) {
            $str = str_replace('""', '"', $str);
        }
        
        // 尝试转换编码
        if (!mb_check_encoding($str, 'UTF-8')) {
            $encodings = ['GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'Windows-1252'];
            
            foreach ($encodings as $encoding) {
                if (mb_check_encoding($str, $encoding)) {
                    return mb_convert_encoding($str, 'UTF-8', $encoding);
                }
            }
            
            // 如果无法确定编码，强制转换为UTF-8
            return mb_convert_encoding($str, 'UTF-8', 'auto');
        }
        
        return $str;
    }

    /**
     * 验证导入数据
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // 支持两种字段名验证
            '*.zhu_ce_lai_yuan' => 'nullable|string',
            '*.注册来源' => 'nullable|string',
            '*.zhu_ce_shi_jian' => 'nullable',
            '*.注册时间' => 'nullable',
            // 至少有一组字段存在
            '*' => 'required_without_all:zhu_ce_lai_yuan,注册来源,zhu_ce_shi_jian,注册时间',
        ];
    }

    /**
     * 转换日期格式
     *
     * @param $value
     * @return \Carbon\Carbon|null
     */
    private function transformDate($value)
    {
        if (empty($value)) {
            return now();
        }
        
        try {
            // 先尝试完整格式
            return Carbon::createFromFormat('Y-m-d H:i:s', $value);
        } catch (\Exception $e) {
            try {
                // 再尝试日期+时间但不同格式
                return Carbon::parse($value);
            } catch (\Exception $e) {
                // 兜底，返回当前时间
                return now();
            }
        }
    }
    
    /**
     * 自定义CSV设置
     *
     * @return array
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'escape_character' => '\\',
            'contiguous' => false,
            'input_encoding' => 'UTF-8',
        ];
    }

    /**
     * 批量插入大小
     * 
     * @return int
     */
    public function batchSize(): int
    {
        return 1000; // 每批插入1000条记录
    }

    /**
     * 分块读取大小
     * 
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000; // 每次读取1000条记录
    }
    
    /**
     * 更新导入任务进度
     */
    protected function updateProgress()
    {
        if ($this->importJob) {
            $this->importJob->update([
                'processed_rows' => $this->processedRows,
                'inserted_rows' => $this->insertedRows,
                'updated_rows' => $this->updatedRows,
                'error_rows' => $this->errorRows
            ]);
        }
    }
} 