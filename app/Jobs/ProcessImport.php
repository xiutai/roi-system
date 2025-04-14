<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ImportJob;
use App\Models\Transaction;
use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    
    // 注意: 不使用SerializesModels，我们手动指定序列化和反序列化逻辑

    /**
     * 导入作业ID
     *
     * @var int
     */
    protected $importJobId;
    
    /**
     * 导入作业实例（运行时）
     *
     * @var \App\Models\ImportJob|null
     */
    protected $importJob = null;

    /**
     * 失败前的尝试次数
     *
     * @var int
     */
    public $tries = 3;

    /**
     * 任务超时时间(秒)
     *
     * @var int
     */
    public $timeout = 7200; // 2小时
    
    /**
     * 重试之间的延迟时间(秒)
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * 创建一个新的任务实例
     *
     * @param  \App\Models\ImportJob  $importJob
     * @return void
     */
    public function __construct(ImportJob $importJob)
    {
        $this->importJobId = $importJob->id;
        // 禁用按内存使用自动重启的队列工作器
        $this->onQueue('imports');
    }

    /**
     * 执行任务
     *
     * @return void
     */
    public function handle()
    {
        // 从数据库加载导入任务
        $this->importJob = ImportJob::findOrFail($this->importJobId);
        
        // 保留关键日志，删除冗余日志
        Log::info('开始处理导入任务', [
            'job_id' => $this->importJob->id,
            'filename' => $this->importJob->original_filename
        ]);
        
        // 设置最大执行时间（防止脚本超时）
        set_time_limit(0);
        
        try {
            // 更新任务状态为处理中
            $this->importJob->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);
            
            // 检查文件名是否存在
            if (empty($this->importJob->filename)) {
                throw new \Exception("导入任务缺少文件名");
            }
            
            // 直接使用完整路径，避免使用Storage Facade
            $filePath = storage_path('app/imports/' . $this->importJob->filename);
            
            // 检查文件是否存在
            if (!file_exists($filePath)) {
                throw new \Exception("文件不存在: {$filePath}");
            }
            
            // 检查文件是否可读
            if (!is_readable($filePath)) {
                throw new \Exception("文件不可读: {$filePath}，请检查权限");
            }
            
            // 获取文件扩展名
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            // 检查文件是否为支持的格式
            if (!in_array($extension, ['csv', 'txt'])) {
                throw new \Exception("不支持的文件格式: {$extension}，仅支持CSV格式");
            }
            
            // 计算文件大小
            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                throw new \Exception("无法获取文件大小: {$filePath}");
            }
            
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);
            
            // 计算总行数
            $totalRows = $this->countFileRows($filePath, $extension);
            $this->importJob->update(['total_rows' => $totalRows]);
            
            // 处理CSV文件
            $this->processCsvFile($filePath);
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error('导入任务失败', [
                'job_id' => $this->importJob->id,
                'error' => $errorMessage
            ]);
            
            // 更新导入任务状态为失败
            try {
                $this->importJob->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'completed_at' => now()
                ]);
            } catch (\Exception $updateException) {
                Log::error('更新导入任务状态失败', [
                    'job_id' => $this->importJob->id,
                    'error' => $updateException->getMessage()
                ]);
            }
            
            // 重新抛出异常，允许队列系统处理
            throw $e;
        }
    }
    
    /**
     * 计算文件总行数
     *
     * @param string $filePath
     * @param string $extension
     * @return int
     */
    protected function countFileRows($filePath, $extension)
    {
        // 对于CSV文件，使用更快的逐行计数
        if (in_array($extension, ['csv', 'txt'])) {
            $count = 0;
            
            try {
                $startTime = microtime(true);
                
                $file = fopen($filePath, 'r');
                if (!$file) {
                    return 0;
                }
                
                // 读取标题行，不计入总行数
                fgetcsv($file);
                
                // 计算数据行数
                while (fgetcsv($file) !== FALSE) {
                    $count++;
                }
                
                fclose($file);
                
                $duration = round(microtime(true) - $startTime, 2);
                
                return $count;
            } catch (\Exception $e) {
                return 0;
            }
        }
        
        // 对于不支持的格式，返回0
        return 0;
    }
    
    /**
     * 处理CSV文件导入
     *
     * @param string $filePath
     * @return void
     */
    protected function processCsvFile($filePath)
    {
        // 保留关键日志，删除多余日志
        Log::info('处理CSV文件', ['file' => basename($filePath)]);
        ini_set('memory_limit', '2048M'); // 临时增加内存限制
        
        // 释放不需要的资源
        DB::disableQueryLog();
        gc_enable();
        
        // 记录开始时间
        $startTime = microtime(true);
        
        try {
            // 打开CSV文件
            $file = fopen($filePath, 'r');
            if (!$file) {
                throw new \Exception("无法打开文件: {$filePath}");
            }
            
            // 读取标题行
            $headers = fgetcsv($file);
            if (!$headers) {
                throw new \Exception("无法读取CSV标题行");
            }
            
            // 将标题行转为字段映射
            $headerMap = $this->mapHeaders($headers);
            
            // 检查必要字段是否存在
            $requiredFields = ['registration_source', 'registration_time'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headerMap)) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                throw new \Exception("缺少必要字段: " . implode(", ", $missingFields));
            }
            
            // 预加载所有渠道到内存
            $channels = [];
            Channel::chunk(500, function ($channelChunk) use (&$channels) {
                foreach ($channelChunk as $channel) {
                    $channels[$channel->name] = $channel->id;
                }
            });
            
            // 如果需要替换现有数据（相同insert_date的数据）
            if ($this->importJob->is_replacing_existing) {
                DB::table('transactions')
                    ->where('insert_date', $this->importJob->insert_date)
                    ->delete();
            }
            
            // 处理数据行
            $rowCount = 0;
            $insertedRows = 0;
            $skippedCount = 0;
            $allRecords = [];
            
            // 设置处理进度
            $this->importJob->update([
                'processed_rows' => $rowCount,
                'inserted_rows' => $insertedRows
            ]);
            
            // 逐行读取CSV数据
            while (($row = fgetcsv($file)) !== FALSE) {
                $rowCount++;
                
                // 每100行更新一次进度
                if ($rowCount % 100 == 0) {
                    $this->importJob->update(['processed_rows' => $rowCount]);
                }
                
                try {
                    // 将CSV行数据映射为字段数组
                    $mappedRow = [];
                    foreach ($headerMap as $colIndex => $fieldName) {
                        if (isset($row[$colIndex])) {
                            $value = $row[$colIndex];
                            
                            // 处理可能的日期格式
                            if ($fieldName == 'registration_time' && !empty($value)) {
                                $value = $this->transformDate($value);
                            }
                            
                            $mappedRow[$fieldName] = $value;
                        }
                    }
                    
                    // 提取必要字段
                    $memberId = trim($mappedRow['member_id'] ?? '');
                    $registrationSource = trim($mappedRow['registration_source'] ?? '');
                    $registrationTime = trim($mappedRow['registration_time'] ?? '');
                    
                    // 如果注册来源为空，设置为默认值
                    if (empty($registrationSource)) {
                        $registrationSource = '无来源';
                    }
                    
                    // 检查必要字段
                    if (empty($registrationTime)) {
                        $skippedCount++;
                        continue;
                    }
                    
                    // 数值字段验证和转换
                    $balanceDifference = 0;
                    $rawBalance = $mappedRow['balance_difference'] ?? '0';
                    
                    // 处理特殊字符等
                    if (is_string($rawBalance)) {
                    $rawBalance = preg_replace('/[^\d.-]/', '', $rawBalance); // 只保留数字、小数点和负号
                    }
                    
                    if (is_numeric($rawBalance)) {
                        $balanceDifference = (float)$rawBalance;
                    }
                    
                    // 获取或创建渠道
                    if (!isset($channels[$registrationSource])) {
                        // 确保渠道名称使用正确的编码
                        $encodedSource = $this->ensureCorrectEncoding($registrationSource);
                        $registrationSource = $encodedSource;
                        
                        // 检查是否已存在该渠道
                        $existingChannel = Channel::where('name', $registrationSource)->first();
                        
                        if ($existingChannel) {
                            $channels[$registrationSource] = $existingChannel->id;
                        } else {
                            // 创建新渠道
                            $channel = new Channel();
                            $channel->name = $registrationSource;
                            $channel->description = '从导入数据自动创建';
                            $channel->save();
                            
                            $channels[$registrationSource] = $channel->id;
                        }
                    }
                    
                    $channelId = $channels[$registrationSource];
                    
                    // 构建记录
                    $record = [
                        'currency' => $mappedRow['currency'] ?? 'PKR',
                        'member_id' => $memberId,
                        'member_account' => $mappedRow['member_account'] ?? '',
                        'channel_id' => $channelId,
                        'registration_source' => $registrationSource,
                        'registration_time' => $registrationTime,
                        'balance_difference' => $balanceDifference,
                        'insert_date' => $this->importJob->insert_date,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    
                    $allRecords[] = $record;
                    
                    // 每1000行批量插入一次
                    if (count($allRecords) >= 1000) {
                        $insertCount = $this->batchInsertRecords($allRecords);
                        $insertedRows += $insertCount;
                        $allRecords = []; // 清空数组
                        
                        // 更新进度
                        $this->importJob->update([
                            'processed_rows' => $rowCount,
                            'inserted_rows' => $insertedRows
                        ]);
                    }
                    
                } catch (\Exception $e) {
                    $skippedCount++;
                    // 记录错误但继续处理
                    Log::error('处理行数据失败', [
                        'row' => $rowCount,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // 减少进度日志频率
                if ($rowCount % 10000 === 0) {
                    Log::info('CSV处理进度', [
                        'processed' => $rowCount,
                        'inserted' => $insertedRows
                    ]);
                }
            }
            
            // 插入剩余记录
            if (!empty($allRecords)) {
                $batchResult = $this->batchInsertRecords($allRecords);
                $insertedRows += $batchResult;
            }
            
            // 关闭文件
            fclose($file);
            
            // 获取更新和新增的记录数
            $updatedRows = DB::table('transactions')
                ->where('insert_date', $this->importJob->insert_date)
                ->where('updated_at', '>', $this->importJob->started_at)
                ->count();
            
            $newRows = $insertedRows - $updatedRows;
            if ($newRows < 0) $newRows = 0;
            
            // 更新导入任务状态
            $this->importJob->update([
                'status' => 'completed',
                'processed_rows' => $rowCount,
                'inserted_rows' => $newRows,
                'updated_rows' => $updatedRows,
                'error_rows' => $rowCount - $insertedRows - $skippedCount,
                'completed_at' => now()
            ]);
            
            Log::info('CSV导入完成', [
                'total_rows' => $rowCount,
                'inserted_rows' => $newRows,
                'updated_rows' => $updatedRows
            ]);
            
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * 批量插入记录 - 修改为UPSERT逻辑
     * 如果insert_date和member_id组合已存在，则更新记录
     * 如果不存在，则插入新记录
     */
    protected function batchInsertRecords($records)
    {
        if (empty($records)) {
            return 0;
        }

        try {
            DB::beginTransaction();
            
            $insertedCount = 0;
            $updatedCount = 0;
            $batchSize = 300;
            
            // 根据insert_date分组记录
            $recordsByDate = [];
            foreach ($records as $record) {
                $date = $record['insert_date'];
                if (!isset($recordsByDate[$date])) {
                    $recordsByDate[$date] = [];
                }
                $recordsByDate[$date][] = $record;
            }
            
            // 按日期处理记录，确保只检查同一天内的重复
            foreach ($recordsByDate as $date => $dateRecords) {
                // 获取这个日期所有的member_id
                $memberIds = array_map(function($record) {
                    return $record['member_id'];
                }, $dateRecords);
                
                // 查询当前日期已存在的记录
                $existingRecords = DB::table('transactions')
                    ->where('insert_date', $date)
                    ->whereIn('member_id', $memberIds)
                    ->select('member_id')
                    ->get()
                    ->pluck('member_id')
                    ->toArray();
                
                // 将已存在的记录转换为关联数组，方便快速查找
                $existingMemberIds = array_flip($existingRecords);
                
                // 分拣当前日期的数据为更新或插入
                $toInsert = [];
                $toUpdate = [];
                
                foreach ($dateRecords as $record) {
                    if (isset($existingMemberIds[$record['member_id']])) {
                        $toUpdate[] = $record;
                    } else {
                        $toInsert[] = $record;
                    }
                }
                
                // 批量插入新记录
                if (!empty($toInsert)) {
                    foreach (array_chunk($toInsert, $batchSize) as $batch) {
                        DB::table('transactions')->insert($batch);
                    }
                    $insertedCount += count($toInsert);
                }
                
                // 批量更新现有记录
                if (!empty($toUpdate)) {
                    foreach ($toUpdate as $record) {
                        DB::table('transactions')
                            ->where('insert_date', $record['insert_date'])
                            ->where('member_id', $record['member_id'])
                            ->update([
                                'currency' => $record['currency'],
                                'member_account' => $record['member_account'],
                                'channel_id' => $record['channel_id'],
                                'registration_source' => $record['registration_source'],
                                'registration_time' => $record['registration_time'],
                                'balance_difference' => $record['balance_difference'],
                                'updated_at' => now()
                            ]);
                    }
                    $updatedCount += count($toUpdate);
                }
            }
            
            // 提交事务
            DB::commit();
            
            // 只保留必要的总结日志
            Log::info('批量处理完成', [
                'inserted' => $insertedCount,
                'updated' => $updatedCount
            ]);
            
            return $insertedCount + $updatedCount;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            
            Log::error('批量插入/更新记录失败', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * 映射标题行到系统字段
     *
     * @param array $headers 原始标题行
     * @return array 映射后的标题数组 [列索引 => 系统字段名]
     */
    protected function mapHeaders($headers)
    {
        // 字段映射定义（与processCsvFile中相同）
        $fieldMap = [
            // 原始字段 => 系统字段
            'bi_zhong' => 'currency',
            'hui_yuan_id' => 'member_id',
            'hui_yuan_zhang_hao' => 'member_account',
            'zhu_ce_lai_yuan' => 'registration_source',
            'zhu_ce_shi_jian' => 'registration_time',
            'zong_chong_ti_cha_e' => 'balance_difference',
            
            // 中文字段映射
            '币种' => 'currency',
            '会员id' => 'member_id',
            '会员ID' => 'member_id',
            '会员账号' => 'member_account',
            '渠道ID' => 'channel_id_custom', // 保留映射但不使用
            '渠道id' => 'channel_id_custom', // 保留映射但不使用
            '注册来源' => 'registration_source',
            '注册时间' => 'registration_time',
            '总充提差额' => 'balance_difference',
            
            // 英文字段映射
            'currency' => 'currency',
            'member_id' => 'member_id',
            'memberid' => 'member_id',
            'member_account' => 'member_account',
            'memberaccount' => 'member_account',
            'channel_id' => 'channel_id_custom',
            'channelid' => 'channel_id_custom',
            'registration_source' => 'registration_source',
            'registrationsource' => 'registration_source',
            'registration_time' => 'registration_time',
            'registrationtime' => 'registration_time',
            'balance_difference' => 'balance_difference',
            'balancedifference' => 'balance_difference',
            
            // 增加更多可能的变体
            'bizhong' => 'currency',
            'bi zhong' => 'currency',
            'bizh' => 'currency',
            'cur' => 'currency',
            
            'member' => 'member_id',
            'mid' => 'member_id',
            'huiyuanid' => 'member_id',
            'huiyuan' => 'member_id',
            'hui yuan id' => 'member_id',
            
            'account' => 'member_account',
            'acct' => 'member_account',
            'acc' => 'member_account',
            'memberacc' => 'member_account',
            'user' => 'member_account',
            'username' => 'member_account',
            'huiyuanzhanghao' => 'member_account',
            'zhanghao' => 'member_account',
            
            'source' => 'registration_source',
            'reg source' => 'registration_source',
            'regsource' => 'registration_source',
            'signup source' => 'registration_source',
            'channel' => 'registration_source',
            'channel source' => 'registration_source',
            'laiyuan' => 'registration_source',
            'zhuce' => 'registration_source',
            'zhucelaiyuan' => 'registration_source',
            'qudao' => 'registration_source',
            
            'time' => 'registration_time',
            'date' => 'registration_time',
            'regtime' => 'registration_time',
            'reg time' => 'registration_time',
            'registration' => 'registration_time',
            'signup' => 'registration_time',
            'signup time' => 'registration_time',
            'regdate' => 'registration_time',
            'reg date' => 'registration_time',
            'zhucedate' => 'registration_time',
            'zhuceshijian' => 'registration_time',
            'zhuce shijian' => 'registration_time',
            'shijian' => 'registration_time',
            
            'balance' => 'balance_difference',
            'diff' => 'balance_difference',
            'difference' => 'balance_difference',
            'bal' => 'balance_difference',
            'balance diff' => 'balance_difference',
            'chongtichae' => 'balance_difference',
            'chongti' => 'balance_difference',
            'chae' => 'balance_difference',
            'chaer' => 'balance_difference',
            'balance_diff' => 'balance_difference',
        ];
        
        // 处理标题编码
        $encodedHeaders = [];
        foreach ($headers as $index => $header) {
            $encodedHeader = $this->ensureCorrectEncoding($header);
            $encodedHeaders[$index] = $encodedHeader;
        }
        
        // 标准化表头，移除特殊字符并转为小写
        $normalizedHeaders = [];
        foreach ($encodedHeaders as $index => $header) {
            // 规范化处理：去除空格、特殊字符，转小写
            $normalizedHeader = $this->normalizeHeader($header);
            $normalizedHeaders[$index] = $normalizedHeader;
        }
        
        // 映射标题字段
        $headerMap = [];
        foreach ($normalizedHeaders as $index => $header) {
            if (empty($header)) {
                continue;
            }
            
            $headerToCheck = $header; // 已经标准化过的表头
            
            if (isset($fieldMap[$headerToCheck])) {
                $headerMap[$index] = $fieldMap[$headerToCheck];
                continue;
            }
            
            // 尝试模糊匹配
            $bestMatch = null;
            $bestScore = 0;
            foreach ($fieldMap as $key => $value) {
                // 精确包含
                if (strpos($headerToCheck, $key) !== false) {
                    $score = strlen($key);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $value;
                    }
                }
                // 模糊匹配 - 允许一些差异
                else if (similar_text($headerToCheck, $key, $percent) && $percent > 60) { // 降低阈值到60%
                    if ($percent > $bestScore) {
                        $bestScore = $percent;
                        $bestMatch = $value;
                    }
                }
            }
            
            if ($bestMatch !== null) {
                $headerMap[$index] = $bestMatch;
            } else {
                // 无法匹配，保留原始名称
                $headerMap[$index] = $headerToCheck;
                Log::warning('无法匹配的字段', ['header' => $headerToCheck]);
            }
        }
        
        // 记录所有标题字段及其映射
        Log::info('表头字段映射结果', [
            'mapped_headers' => array_values(array_filter($headerMap))
        ]);
        
        return $headerMap;
    }

    /**
     * 标准化表头字段
     * 
     * @param string $header 原始表头
     * @return string 标准化后的表头
     */
    protected function normalizeHeader($header)
    {
        if (empty($header)) {
            return '';
        }
        
        // 转为字符串
        $header = (string)$header;
        
        // 移除特殊字符，只保留字母、数字和下划线
        $normalized = preg_replace('/[^\p{L}\p{N}_]/u', '', $header);
        
        // 转为小写
        $normalized = mb_strtolower($normalized, 'UTF-8');
        
        return $normalized;
    }
    
    /**
     * 确保字符串使用正确的编码
     *
     * @param mixed $str 输入字符串
     * @return mixed 编码修正后的字符串
     */
    protected function ensureCorrectEncoding($str)
    {
        if (!is_string($str)) {
            return $str;
        }
        
        // 如果已经是UTF-8，直接返回
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        
        // 尝试将常见编码转换为UTF-8
        $encodings = ['GBK', 'GB2312', 'BIG5', 'ASCII', 'ISO-8859-1', 'UTF-16'];
        
        foreach ($encodings as $encoding) {
            if (mb_check_encoding($str, $encoding)) {
                $converted = mb_convert_encoding($str, 'UTF-8', $encoding);
                return $converted;
            }
        }
        
        // 如果无法确定编码，尝试强制转换
        $forced = mb_convert_encoding($str, 'UTF-8', 'auto');
        
        // 最后清理一下字符串确保没有无效字符
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $forced);
        
        return $cleaned;
    }
    
    /**
     * 转换日期格式
     *
     * @param string $value 输入日期字符串
     * @return string 标准化的日期时间字符串
     */
    protected function transformDate($value)
    {
        if (empty($value)) {
            return now()->format('Y-m-d H:i:s');
        }
        
        // 如果已经是完整的日期时间格式，则返回
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        
        try {
            // 尝试使用Carbon解析日期
            $date = Carbon::parse($value);
            
            // 确保日期在合理范围内
            if ($date->year < 2000 || $date->year > 2050) {
                return now()->format('Y-m-d H:i:s');
            }
            
            // 返回标准化的日期时间
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // 如果解析失败，返回当前时间
            return now()->format('Y-m-d H:i:s');
        }
    }
    
    /**
     * 处理任务失败
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        try {
            // 更新导入任务状态为失败
            $this->importJob = ImportJob::find($this->importJobId);
            
            if ($this->importJob) {
                $errorMessage = mb_substr('导入失败: ' . $exception->getMessage(), 0, 200);
                
                $this->importJob->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'completed_at' => now()
                ]);
                
                // 记录失败信息
                Log::error("导入任务失败: ID {$this->importJob->id}", [
                    'error' => $exception->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            // 如果更新失败，记录错误
            Log::critical('无法更新失败的导入任务状态', [
                'job_id' => $this->importJobId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 安全删除目录，即使目录不为空
     * 
     * @param string $dir 要删除的目录路径
     * @return bool 是否成功删除
     */
    protected function safeRemoveDirectory($dir) 
    {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        try {
            // 遍历目录中的所有项目
            foreach (scandir($dir) as $item) {
                if ($item == '.' || $item == '..') {
                    continue;
                }
                
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                
                if (is_dir($path)) {
                    // 递归删除子目录
                    $this->safeRemoveDirectory($path);
                } else {
                    // 删除文件
                    try {
                        unlink($path);
                    } catch (\Exception $e) {
                        Log::warning('无法删除文件: ' . $path . ' - ' . $e->getMessage());
                    }
                }
            }
            
            // 删除空目录
            try {
                return rmdir($dir);
            } catch (\Exception $e) {
                Log::warning('无法删除目录: ' . $dir . ' - ' . $e->getMessage());
                return false;
            }
        } catch (\Exception $e) {
            Log::warning('目录删除过程中出错: ' . $dir . ' - ' . $e->getMessage());
            return false;
        }
    }
} 