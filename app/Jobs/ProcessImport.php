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
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\TransactionsImport;

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
        
        // 打印日志，便于调试
        Log::info('开始处理导入任务', [
            'job_id' => $this->importJob->id,
            'filename' => $this->importJob->original_filename,
            'insert_date' => $this->importJob->insert_date
        ]);
        
        // 设置最大执行时间（防止脚本超时）
        set_time_limit(0);
        
        try {
            // 更新任务状态为处理中
            $this->importJob->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);
            Log::info('已更新任务状态为处理中', ['job_id' => $this->importJob->id]);
            
            // 检查文件名是否存在
            if (empty($this->importJob->filename)) {
                throw new \Exception("导入任务缺少文件名");
            }
            
            // 直接使用完整路径，避免使用Storage Facade
            $filePath = storage_path('app/imports/' . $this->importJob->filename);
            Log::info('处理文件路径', ['path' => $filePath]);
            
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
            Log::info('文件类型', ['extension' => $extension]);
            
            // 计算文件大小
            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                throw new \Exception("无法获取文件大小: {$filePath}");
            }
            
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);
            Log::info('文件大小', ['size_mb' => $fileSizeMB, 'size_bytes' => $fileSize]);
            
            // 检查文件是否过大
            if ($fileSizeMB > 100) {
                Log::warning('文件过大，可能需要较长处理时间', ['size_mb' => $fileSizeMB]);
            }
            
            // 计算总行数
            $totalRows = $this->countFileRows($filePath, $extension);
            $this->importJob->update(['total_rows' => $totalRows]);
            Log::info('文件总行数', ['total_rows' => $totalRows]);
            
            // 处理文件
            if (in_array($extension, ['csv', 'txt'])) {
                // 直接处理CSV，更高效
                $this->processCsvFile($filePath);
            } else {
                // 使用Excel包处理
                DB::disableQueryLog(); // 禁用查询日志以减少内存使用
                
                try {
                    // 绕过Maatwebsite\Excel的Storage Facade依赖，直接使用文件路径
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($filePath);
                    
                    // 获取活动工作表
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestRow = $worksheet->getHighestRow();
                    
                    // 获取列标题
                    $headers = [];
                    $highestColumn = $worksheet->getHighestColumn();
                    $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                    
                    for ($col = 1; $col <= $highestColumnIndex; $col++) {
                        $cell = $worksheet->getCellByColumnAndRow($col, 1);
                        $headers[$col] = $this->ensureCorrectEncoding($cell->getValue());
                    }
                    
                    // 字段映射（与processCsvFile相同逻辑）
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
                    ];
                    
                    // 处理标题编码
                    $encodedHeaders = [];
                    foreach ($headers as $index => $header) {
                        $encodedHeader = $this->ensureCorrectEncoding($header);
                        $encodedHeaders[$index] = $encodedHeader;
                    }
                    
                    // 映射标题字段
                    $headerMap = [];
                    foreach ($encodedHeaders as $index => $header) {
                        $headerToCheck = trim(strtolower($header));
                        
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
                            else if (similar_text($headerToCheck, $key, $percent) && $percent > 70) {
                                if ($percent > $bestScore) {
                                    $bestScore = $percent;
                                    $bestMatch = $value;
                                }
                            }
                        }
                        
                        if ($bestMatch !== null) {
                            $headerMap[$index] = $bestMatch;
                            Log::info('字段模糊匹配', [
                                'original' => $headerToCheck,
                                'matched_to' => $bestMatch,
                                'score' => $bestScore
                            ]);
                        } else {
                            $headerMap[$index] = $headerToCheck;
                        }
                    }
                    
                    // 开始处理数据
                    $insertedRows = 0;
                    $errorRows = 0;
                    $errorDetails = [];
                    
                    // 预加载所有渠道到内存
                    $channels = [];
                    Channel::chunk(500, function ($channelChunk) use (&$channels) {
                        foreach ($channelChunk as $channel) {
                            $channels[$channel->name] = $channel->id;
                        }
                    });
                    
                    // 收集所有记录
                    $allRecords = [];
                    
                    // 从第二行开始处理（跳过标题行）
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $mappedRow = [];
                        
                        // 获取行数据
                        for ($col = 1; $col <= $highestColumnIndex; $col++) {
                            if (isset($headerMap[$col])) {
                                $cell = $worksheet->getCellByColumnAndRow($col, $row);
                                $value = $cell->getValue();
                                // 确保值的编码
                                $cleanValue = $this->ensureCorrectEncoding($value);
                                $mappedRow[$headerMap[$col]] = $cleanValue;
                            }
                        }
                        
                        // 提取必要字段
                        $memberId = trim($mappedRow['member_id'] ?? '');
                        $registrationSource = trim($mappedRow['registration_source'] ?? '');
                        $registrationTime = trim($mappedRow['registration_time'] ?? '');
                        
                        // 数值字段验证和转换
                        $balanceDifference = 0;
                        $rawBalance = $mappedRow['balance_difference'] ?? '0';
                        
                        // 处理可能的数值格式问题
                        $rawBalance = preg_replace('/[^\d.-]/', '', $rawBalance); // 只保留数字、小数点和负号
                        
                        if (is_numeric($rawBalance)) {
                            $balanceDifference = (float)$rawBalance;
                        } else {
                            Log::warning('非数字的充提差额', [
                                'row' => $row,
                                'value' => $rawBalance,
                                'converted' => 0
                            ]);
                        }
                        
                        // 检查必要字段
                        if (empty($registrationSource) || empty($registrationTime)) {
                            $errorRows++;
                            $errorDetails[] = "行 {$row}: 缺少必要字段 " . 
                                (empty($registrationSource) ? '注册来源' : '') . 
                                (empty($registrationSource) && empty($registrationTime) ? '和' : '') . 
                                (empty($registrationTime) ? '注册时间' : '');
                            continue;
                        }
                        
                        // 获取或创建渠道
                        if (!isset($channels[$registrationSource])) {
                            // 创建新渠道
                            $channel = Channel::firstOrCreate(
                                ['name' => $registrationSource],
                                ['description' => '从导入数据自动创建']
                            );
                            $channels[$registrationSource] = $channel->id;
                        }
                        
                        $channelId = $channels[$registrationSource];
                        
                        // 收集所有记录
                        $allRecords[] = [
                            'currency' => $mappedRow['currency'] ?? 'PKR',
                            'member_id' => $memberId,
                            'member_account' => $mappedRow['member_account'] ?? '',
                            'channel_id' => $channelId,
                            'registration_source' => $registrationSource,
                            'registration_time' => $this->transformDate($registrationTime),
                            'balance_difference' => $balanceDifference,
                            'insert_date' => $this->importJob->insert_date,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    
                    // 如果需要替换现有数据（相同insert_date的数据）
                    $replacedRows = 0;
                    if ($this->importJob->is_replacing_existing) {
                        Log::info('删除相同插入日期的数据', ['insert_date' => $this->importJob->insert_date]);
                        $replacedRows = DB::table('transactions')
                            ->where('insert_date', $this->importJob->insert_date)
                            ->delete();
                        Log::info('删除完成', ['replaced_rows' => $replacedRows]);
                        
                        // 更新导入任务的替换记录数
                        $this->importJob->update([
                            'replaced_rows' => $replacedRows
                        ]);
                    }
                    
                    // 批量插入数据
                    if (!empty($allRecords)) {
                        Log::info('开始插入Excel数据', ['count' => count($allRecords)]);
                        
                        // 使用更小的批次插入记录，每批次单独使用事务
                        $batchSize = 3000;
                        $batches = array_chunk($allRecords, $batchSize);
                        $batchInserted = 0;
                        
                        foreach ($batches as $index => $batch) {
                            try {
                                DB::beginTransaction();
                                
                                DB::table('transactions')->insert($batch);
                                $batchInserted += count($batch);
                                
                                DB::commit();
                                
                                // 每批次更新一次插入计数
                                $this->importJob->update([
                                    'inserted_rows' => $batchInserted
                                ]);
                                
                                Log::info('批次插入完成', [
                                    'batch' => $index + 1, 
                                    'total_batches' => count($batches),
                                    'inserted_so_far' => $batchInserted
                                ]);
                                
                            } catch (\Exception $e) {
                                if (DB::transactionLevel() > 0) {
                                    DB::rollBack();
                                }
                                Log::error('批次插入失败', [
                                    'batch' => $index + 1,
                                    'error' => $e->getMessage()
                                ]);
                                // 继续处理下一批次，不中断整个流程
                            }
                        }
                        
                        $insertedRows = $batchInserted;
                        Log::info('Excel数据插入完成', ['total_inserted' => $insertedRows]);
                    }
                    
                    // 更新最终进度
                    $this->importJob->update([
                        'processed_rows' => $highestRow - 1, // 减去标题行
                        'inserted_rows' => $insertedRows,
                        'error_rows' => $errorRows,
                        'replaced_rows' => $replacedRows
                    ]);
                    
                    // 释放内存
                    unset($allRecords);
                    unset($spreadsheet);
                    gc_collect_cycles();
                    
                } catch (\Exception $e) {
                    Log::error('Excel导入异常', [
                        'error' => $e->getMessage(),
                        'file' => $filePath
                    ]);
                    throw new \Exception("Excel导入失败: " . $e->getMessage());
                }
            }
            
            // 更新任务状态为已完成
            $this->importJob->update([
                'status' => 'completed', 
                'completed_at' => now()
            ]);
            
            Log::info('导入任务完成', [
                'job_id' => $this->importJob->id,
                'processed' => $this->importJob->processed_rows,
                'inserted' => $this->importJob->inserted_rows,
                'replaced' => $this->importJob->replaced_rows,
                'duration_minutes' => $this->importJob->started_at->diffInMinutes($this->importJob->completed_at)
            ]);
            
            // 释放内存
            gc_collect_cycles();
            
            // 强制进行完整的垃圾回收，确保释放所有不再使用的内存
            gc_mem_caches();
            
        } catch (\Exception $e) {
            Log::error('导入任务失败', [
                'job_id' => $this->importJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            try {
                // 获取简短的错误消息，限制长度确保不会超出数据库字段
                $shortErrorMessage = mb_substr('导入失败: ' . $e->getMessage(), 0, 200);
                
                // 更新任务状态为失败，只保存简短的错误信息
                $this->importJob->update([
                    'status' => 'failed',
                    'error_message' => $shortErrorMessage,
                    'completed_at' => now()
                ]);
            } catch (\Exception $updateException) {
                // 如果更新失败，记录到日志
                Log::critical('无法更新导入任务状态', [
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
        if (in_array($extension, ['csv', 'txt'])) {
            // 优化的行数计算方法
            Log::info('开始计算文件行数');
            $startTime = microtime(true);
            
            // 对于大文件，使用采样方法估计行数
            $fileSize = filesize($filePath);
            
            if ($fileSize > 50 * 1024 * 1024) { // 大于50MB的文件
                Log::info('文件较大，使用采样估算行数');
                
                // 采样前1MB和最后1MB，计算平均行长度
                $handle = fopen($filePath, "r");
                if (!$handle) {
                    return 0;
                }
                
                // 读取前1MB
                $sampleSize = 1024 * 1024; // 1MB
                $firstSample = fread($handle, $sampleSize);
                $firstLineCount = substr_count($firstSample, "\n");
                
                // 读取最后1MB
                fseek($handle, -$sampleSize, SEEK_END);
                $lastSample = fread($handle, $sampleSize);
                $lastLineCount = substr_count($lastSample, "\n");
                
                fclose($handle);
                
                // 计算平均每MB行数
                $avgLinesPerMB = ($firstLineCount + $lastLineCount) / 2;
                
                // 估计总行数（减去可能的标题行）
                $estimatedLines = (int)($avgLinesPerMB * ($fileSize / (1024 * 1024))) - 1;
                
                $duration = round(microtime(true) - $startTime, 2);
                Log::info('文件行数估算完成', [
                    'estimated_rows' => $estimatedLines,
                    'duration_sec' => $duration,
                    'method' => 'sampling'
                ]);
                
                return max(0, $estimatedLines);
            } else {
                // 对于较小文件，直接计算
                $lineCount = 0;
                $handle = fopen($filePath, "r");
                if ($handle) {
                    while(!feof($handle)) {
                        $line = fgets($handle);
                        if ($line !== false) {
                            $lineCount++;
                        }
                    }
                    fclose($handle);
                }
                
                $duration = round(microtime(true) - $startTime, 2);
                Log::info('文件行数计算完成', [
                    'count' => $lineCount - 1, // 减去标题行
                    'duration_sec' => $duration,
                    'method' => 'direct_count'
                ]);
                
                return max(0, $lineCount - 1); // 减去标题行，确保不为负数
            }
        } else {
            // 对于Excel文件，使用PHP读取
            try {
                $startTime = microtime(true);
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rowCount = $worksheet->getHighestRow() - 1; // 减去标题行
                
                $duration = round(microtime(true) - $startTime, 2);
                Log::info('Excel文件行数计算完成', [
                    'count' => $rowCount,
                    'duration_sec' => $duration
                ]);
                
                return $rowCount;
            } catch (\Exception $e) {
                Log::error('计算Excel文件行数失败', ['error' => $e->getMessage()]);
                return 0;
            }
        }
    }
    
    /**
     * 处理CSV文件导入
     *
     * @param string $filePath
     * @return void
     */
    protected function processCsvFile($filePath)
    {
        Log::info('开始处理CSV文件', ['file' => basename($filePath)]);
        ini_set('memory_limit', '2048M'); // 临时增加内存限制
        
        // 释放不需要的资源
        DB::disableQueryLog();
        gc_enable();
        
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
            
            // 处理标题编码
            $encodedHeaders = [];
            foreach ($headers as $header) {
                $encodedHeader = $this->ensureCorrectEncoding($header);
                $encodedHeaders[] = $encodedHeader;
            }
            
            // 字段映射
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
            ];
            
            // 映射标题字段
            $headerMap = [];
            foreach ($encodedHeaders as $index => $header) {
                $headerToCheck = trim(strtolower($this->ensureCorrectEncoding($header))); // 先确保编码正确并处理特殊格式
                
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
                    else if (similar_text($headerToCheck, $key, $percent) && $percent > 70) {
                        if ($percent > $bestScore) {
                            $bestScore = $percent;
                            $bestMatch = $value;
                        }
                    }
                }
                
                if ($bestMatch !== null) {
                    $headerMap[$index] = $bestMatch;
                    Log::info('字段模糊匹配', [
                        'original' => $headerToCheck,
                        'matched_to' => $bestMatch,
                        'score' => $bestScore
                    ]);
                } else {
                    $headerMap[$index] = $headerToCheck;
                }
            }
            
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
            
            // 准备数据收集
            $processedRows = 0;
            $insertedRows = 0;
            $updatedRows = 0;
            $errorRows = 0;
            $errorDetails = []; // 记录错误详情
            $lastProgressUpdate = microtime(true);
            
            // 预加载所有渠道到内存
            $channels = [];
            Channel::chunk(500, function ($channelChunk) use (&$channels) {
                foreach ($channelChunk as $channel) {
                    $channels[$channel->name] = $channel->id;
                }
            });
            
            // 重置文件指针到标题行之后
            rewind($file);
            fgetcsv($file); // 跳过标题行
            
            // 开始处理数据
            $totalRowsToProcess = $this->importJob->total_rows;
            $rowCounter = 0;
            $lastMemoryCheck = microtime(true);
            $progressUpdateFrequency = 1000; // 改为1000行更新一次进度，减少日志
            
            // 第一步：读取所有记录并收集数据
            $allRecords = []; // 所有记录
            $memberBalances = []; // 会员ID -> 充提差额
            
            Log::info('开始读取CSV数据');
            
            while (($row = fgetcsv($file)) !== false) {
                $processedRows++;
                $rowCounter++;
                
                // 减少进度更新频率，每处理1000行更新一次或超过3秒
                $now = microtime(true);
                if ($processedRows % $progressUpdateFrequency === 0 || $now - $lastProgressUpdate >= 3.0) {
                    $this->importJob->update([
                        'processed_rows' => $processedRows,
                    ]);
                    $lastProgressUpdate = $now;
                    
                    // 只有在读取开始和读取完成才记录日志，减少日志量
                    if ($processedRows === $progressUpdateFrequency || $processedRows >= $totalRowsToProcess) {
                        Log::info('CSV读取进度', [
                            'processed' => $processedRows,
                            'total' => $totalRowsToProcess,
                            'percentage' => round($processedRows / max(1, $totalRowsToProcess) * 100, 2) . '%'
                        ]);
                    }
                }
                
                try {
                    // 确保行数据的数量与标题一致
                    if (count($row) < count($headerMap)) {
                        $errorRows++;
                        $errorDetails[] = "行 {$processedRows}: 字段数量不足 (有" . count($row) . "个，需要" . count($headerMap) . "个)";
                        continue;
                    }
                    
                    // 映射行数据
                    $mappedRow = [];
                    foreach ($row as $index => $value) {
                        if (isset($headerMap[$index])) {
                            // 先确保值的编码和特殊格式处理
                            $cleanValue = $this->ensureCorrectEncoding($value);
                            $mappedRow[$headerMap[$index]] = $cleanValue;
                        }
                    }
                    
                    // 提取必要字段
                    $memberId = trim($mappedRow['member_id'] ?? '');
                    $registrationSource = trim($mappedRow['registration_source'] ?? '');
                    $registrationTime = trim($mappedRow['registration_time'] ?? '');
                    
                    // 数值字段验证和转换
                    $balanceDifference = 0;
                    $rawBalance = $mappedRow['balance_difference'] ?? '0';
                    
                    // 处理可能的数值格式问题
                    $rawBalance = preg_replace('/[^\d.-]/', '', $rawBalance); // 只保留数字、小数点和负号
                    
                    if (is_numeric($rawBalance)) {
                        $balanceDifference = (float)$rawBalance;
                    } else {
                        Log::warning('非数字的充提差额', [
                            'row' => $processedRows,
                            'value' => $rawBalance,
                            'converted' => 0
                        ]);
                    }
                    
                    // 检查必要字段
                    if (empty($registrationSource) || empty($registrationTime)) {
                        $errorRows++;
                        $errorDetails[] = "行 {$processedRows}: 缺少必要字段 " . 
                            (empty($registrationSource) ? '注册来源' : '') . 
                            (empty($registrationSource) && empty($registrationTime) ? '和' : '') . 
                            (empty($registrationTime) ? '注册时间' : '');
                        continue;
                    }
                    
                    // 获取或创建渠道
                    if (!isset($channels[$registrationSource])) {
                        // 创建新渠道
                        $channel = Channel::firstOrCreate(
                            ['name' => $registrationSource],
                            ['description' => '从导入数据自动创建']
                        );
                        $channels[$registrationSource] = $channel->id;
                    }
                    
                    $channelId = $channels[$registrationSource];
                    
                    // 收集会员ID和充提差额
                    if (!empty($memberId)) {
                        if (isset($memberBalances[$memberId])) {
                            $memberBalances[$memberId] += $balanceDifference;
                        } else {
                            $memberBalances[$memberId] = $balanceDifference;
                        }
                    }
                    
                    // 收集所有记录
                    $allRecords[] = [
                        'currency' => $mappedRow['currency'] ?? 'PKR',
                        'member_id' => $memberId,
                        'member_account' => $mappedRow['member_account'] ?? '',
                        'channel_id' => $channelId,
                        'registration_source' => $registrationSource,
                        'registration_time' => $this->transformDate($registrationTime),
                        'balance_difference' => $balanceDifference,
                        'insert_date' => $this->importJob->insert_date,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } catch (\Exception $e) {
                    Log::error('处理CSV行失败', [
                        'row' => $processedRows,
                        'error' => $e->getMessage()
                    ]);
                    $errorRows++;
                    $errorDetails[] = "行 {$processedRows}: 处理异常 - " . $e->getMessage();
                }
            }
            
            // 关闭文件
            fclose($file);
            
            // 记录读取完成
            Log::info('CSV文件读取完成', [
                'total_rows' => $processedRows,
                'error_rows' => $errorRows,
                'records_to_process' => count($allRecords),
                'unique_member_ids' => count($memberBalances)
            ]);
            
            // 记录错误详情（如果有）
            if (!empty($errorDetails)) {
                // 记录到日志
                Log::warning('导入过程中的错误详情', [
                    'error_count' => count($errorDetails),
                    'details' => $errorDetails
                ]);
                
                // 将错误详情保存到数据库中，以便在web界面显示
                $this->importJob->update([
                    'error_details' => json_encode($errorDetails)
                ]);
            }
            
            // 第二步：获取数据库中已存在的会员ID
            Log::info('开始查询现有会员ID');
            $existingMemberIds = [];
            
            // 查询所有已存在的会员ID
            $memberIds = array_keys($memberBalances);
            if (!empty($memberIds)) {
                // 将会员ID分批查询，每批最多1000个ID
                $memberIdBatches = array_chunk($memberIds, 1000);
                
                foreach ($memberIdBatches as $batchIndex => $memberIdBatch) {
                    try {
                        $batchMembers = DB::table('transactions')
                            ->whereIn('member_id', $memberIdBatch)
                            ->select('member_id')
                            ->distinct()
                            ->get();
                        
                        foreach ($batchMembers as $member) {
                            $existingMemberIds[$member->member_id] = true;
                        }
                        
                    } catch (\Exception $e) {
                        Log::error('会员ID批次查询失败', [
                            'batch' => $batchIndex + 1,
                            'error' => $e->getMessage()
                        ]);
                        // 继续处理下一批次，不中断整个流程
                    }
                }
            }
            
            Log::info('现有会员ID查询完成', ['count' => count($existingMemberIds)]);
            
            // 第三步：一次性批量操作
            Log::info('开始数据库批量操作');
            
            // 如果需要替换现有数据（相同insert_date的数据）
            $replacedRows = 0;
            if ($this->importJob->is_replacing_existing) {
                Log::info('删除相同插入日期的数据', ['insert_date' => $this->importJob->insert_date]);
                $replacedRows = DB::table('transactions')
                    ->where('insert_date', $this->importJob->insert_date)
                    ->delete();
                Log::info('删除完成', ['replaced_rows' => $replacedRows]);
                
                // 更新导入任务的替换记录数
                $this->importJob->update([
                    'replaced_rows' => $replacedRows
                ]);
            }
            
            // 直接插入所有记录
            if (!empty($allRecords)) {
                Log::info('开始插入新记录', ['count' => count($allRecords)]);
                
                // 使用更小的批次插入记录，每批次单独使用事务
                $batchSize = 3000; // 从10000改为3000，减小批次大小以避免插入失败
                $batches = array_chunk($allRecords, $batchSize);
                $batchInserted = 0;
                
                foreach ($batches as $index => $batch) {
                    try {
                        DB::beginTransaction();
                        
                        DB::table('transactions')->insert($batch);
                        $batchInserted += count($batch);
                        
                        DB::commit();
                        
                        // 每批次更新一次插入计数
                        $this->importJob->update([
                            'inserted_rows' => $batchInserted
                        ]);
                        
                        Log::info('批次插入完成', [
                            'batch' => $index + 1, 
                            'total_batches' => count($batches),
                            'inserted_so_far' => $batchInserted
                        ]);
                        
                    } catch (\Exception $e) {
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }
                        Log::error('批次插入失败', [
                            'batch' => $index + 1,
                            'error' => $e->getMessage()
                        ]);
                        // 继续处理下一批次，不中断整个流程
                    }
                }
                
                $insertedRows = $batchInserted;
                Log::info('新记录插入完成', ['total_inserted' => $insertedRows]);
            }
            
            // 更新最终进度
            $this->importJob->update([
                'processed_rows' => $processedRows,
                'inserted_rows' => $insertedRows ?? 0,
                'updated_rows' => 0, // 不再更新现有记录
                'error_rows' => $errorRows,
                'replaced_rows' => $replacedRows
            ]);
            
            Log::info('数据导入完成', [
                'inserted' => $insertedRows ?? 0,
                'updated' => 0,
                'errors' => $errorRows,
                'replaced' => $replacedRows
            ]);
            
            // 释放内存
            unset($allRecords);
            unset($memberBalances);
            unset($existingMemberIds);
            gc_collect_cycles();
            
        } catch (\Exception $e) {
            // 回滚任何未提交的事务
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            
            Log::error('处理CSV文件失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            if (isset($file) && is_resource($file)) {
                fclose($file);
            }
            // 恢复内存限制
            ini_restore('memory_limit');
            
            // 彻底清理内存
            unset($allRecords);
            unset($memberBalances);
            unset($existingMemberIds);
            unset($channels);
            unset($headerMap);
            unset($encodedHeaders);
            
            // 强制垃圾回收
            gc_collect_cycles();
            gc_mem_caches();
        }
    }
    
    /**
     * 确保字符串使用正确的UTF-8编码，并处理Excel特殊格式
     *
     * @param mixed $str
     * @return string
     */
    protected function ensureCorrectEncoding($str)
    {
        if (!is_string($str)) {
            return $str;
        }
        
        // 处理Excel导出的特殊格式 ="xxx"
        if (preg_match('/^="(.*)"$/', $str, $matches)) {
            $str = $matches[1];
            // 记录处理过的字符串(调试需要时)
            // Log::debug('处理Excel特殊格式', ['original' => $str, 'processed' => $matches[1]]);
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
     * 转换日期格式
     *
     * @param $value
     * @return string
     */
    protected function transformDate($value)
    {
        if (empty($value)) {
            return now()->format('Y-m-d H:i:s');
        }
        
        // 处理Excel导出的特殊格式 ="xxx"
        if (preg_match('/^="(.*)"$/', $value, $matches)) {
            $value = $matches[1];
        }
        
        // 如果是美式日期格式 (MM/DD/YYYY)，转为 YYYY-MM-DD
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(.*)$/', $value, $matches)) {
            $value = $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . 
                     str_pad($matches[2], 2, '0', STR_PAD_LEFT) . $matches[4];
        }
        
        // 如果是中文日期格式 (YYYY/MM/DD)，转为 YYYY-MM-DD
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})(.*)$/', $value, $matches)) {
            $value = $matches[1] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . 
                     str_pad($matches[3], 2, '0', STR_PAD_LEFT) . $matches[4];
        }
        
        try {
            // 先尝试完整格式
            return Carbon::createFromFormat('Y-m-d H:i:s', $value)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            try {
                // 再尝试日期+时间但不同格式
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // 记录解析失败的日期格式
                Log::warning('日期解析失败，使用当前时间', ['value' => $value]);
                // 兜底，返回当前时间
                return now()->format('Y-m-d H:i:s');
            }
        }
    }
    
    /**
     * 任务失败的处理
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        try {
            // 获取简短的错误消息，限制长度确保不会超出数据库字段
            $shortErrorMessage = mb_substr('导入任务失败: ' . $exception->getMessage(), 0, 200);
            
            // 更新任务状态为失败，只保存简短的错误信息
            $this->importJob->update([
                'status' => 'failed',
                'error_message' => $shortErrorMessage,
                'completed_at' => now(),
            ]);
            
            // 记录完整错误到日志
            Log::error("导入任务失败处理: {$this->importJob->id}", [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
        } catch (\Exception $e) {
            // 如果更新失败日志记录也失败，至少记录一下
            Log::critical('无法更新失败的导入任务状态', [
                'job_id' => $this->importJob->id,
                'error' => $e->getMessage()
            ]);
        }
    }
} 