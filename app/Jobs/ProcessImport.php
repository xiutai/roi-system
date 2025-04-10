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
use PhpOffice\PhpSpreadsheet\IOFactory;

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
                    // 设置自定义临时目录
                    $tempDir = storage_path('app/temp/' . uniqid('excel_'));
                    if (!file_exists($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }
                    // 通过PHP环境变量设置临时目录
                    putenv("TMPDIR={$tempDir}");
                    // 记录临时目录设置
                    Log::info('设置临时目录', ['tempDir' => $tempDir]);
                    
                    // 绕过Maatwebsite\Excel的Storage Facade依赖，直接使用文件路径
                    $reader = IOFactory::createReaderForFile($filePath);
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($filePath);
                    
                    // 获取活动工作表
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestRow = $worksheet->getHighestRow();
                    
                    // 获取列标题
                    $headers = [];
                    $encodedHeaders = [];
                    $highestColumn = $worksheet->getHighestColumn();
                    $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                    
                    Log::info('Excel表格信息', [
                        'highest_column' => $highestColumn,
                        'highest_column_index' => $highestColumnIndex,
                        'highest_row' => $highestRow
                    ]);
                    
                    // 原始表头数据收集
                    $rawHeaders = [];
                    for ($col = 1; $col <= $highestColumnIndex; $col++) {
                        $cell = $worksheet->getCellByColumnAndRow($col, 1);
                        $rawValue = $cell->getValue();
                        $rawHeaders[$col] = [
                            'raw_value' => $rawValue,
                            'data_type' => $cell->getDataType(),
                            'formatted' => $cell->getFormattedValue()
                        ];
                    }
                    Log::info('Excel原始表头详情', ['raw_headers' => $rawHeaders]);
                    
                    // 获取表头值，处理公式和特殊格式
                    for ($col = 1; $col <= $highestColumnIndex; $col++) {
                        $cell = $worksheet->getCellByColumnAndRow($col, 1);
                        $value = $cell->getValue();
                        
                        // 处理公式单元格
                        if ($cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                            try {
                                $value = $cell->getCalculatedValue();
                            } catch (\Exception $e) {
                                $value = $cell->getFormattedValue();
                            }
                        }
                        
                        // 处理="xxx"格式
                        if (is_string($value) && preg_match('/^="(.*)"$/', $value, $matches)) {
                            $value = $matches[1];
                        } elseif (is_string($value) && substr($value, 0, 1) === '=') {
                            $value = substr($value, 1);
                        }
                        
                        $encodedValue = $this->ensureCorrectEncoding($value);
                        $headers[$col] = $encodedValue;
                        $encodedHeaders[$col] = $encodedValue;
                    }
                    
                    // 记录处理后的表头
                    Log::info('Excel处理后的表头', ['headers' => $headers]);
                    
                    // 读取第一行数据进行检查
                    if ($highestRow > 1) {
                        $checkRow = [];
                        for ($col = 1; $col <= $highestColumnIndex; $col++) {
                            $cell = $worksheet->getCellByColumnAndRow($col, 2);
                            $value = $cell->getValue();
                            $dataType = $cell->getDataType();
                            $checkRow[$col] = [
                                'value' => $value,
                                'type' => $dataType
                            ];
                        }
                        Log::info('Excel第二行数据', ['row' => $checkRow]);
                    }
                    
                    // 检查必要字段是否存在
                    $requiredFields = ['registration_source', 'registration_time'];
                    $missingFields = [];
                    
                    foreach ($requiredFields as $field) {
                        if (!in_array($field, $headers)) {
                            $missingFields[] = $field;
                        }
                    }
                    
                    if (!empty($missingFields)) {
                        Log::error('缺少必要字段', [
                            'missing_fields' => $missingFields,
                            'headers' => $headers,
                            'required_fields' => $requiredFields
                        ]);
                        throw new \Exception("缺少必要字段: " . implode(", ", $missingFields));
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
                    $processedRows = 0; // 初始化处理行数计数器
                    for ($row = 2; $row <= $highestRow; $row++) {
                        // 更新进度
                        $processedRows++;
                        if ($processedRows % 100 == 0) {
                            $this->importJob->update(['processed_rows' => $processedRows]);
                        }
                        
                        $rowData = [];
                        for ($col = 1; $col <= $highestColumnIndex; $col++) {
                                $cell = $worksheet->getCellByColumnAndRow($col, $row);
                            
                            // 获取单元格的值，处理公式单元格
                            if ($cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                                // 尝试获取公式计算结果
                                try {
                                    $value = $cell->getCalculatedValue();
                                } catch (\Exception $e) {
                                    // 如果计算公式值失败，则获取原始公式字符串并清理
                                $value = $cell->getValue();
                                    // 如果是="xxx"这种格式，提取出引号中的内容
                                    if (preg_match('/^="(.*)"$/', $value, $matches)) {
                                        $value = $matches[1];
                                    } elseif (substr($value, 0, 1) == '=') {
                                        // 如果以=开头但不是="xxx"格式，则去掉=号
                                        $value = substr($value, 1);
                                    }
                                }
                            } else {
                                $value = $cell->getValue();
                            }
                            
                            if (isset($headers[$col])) {
                                $fieldName = $headers[$col];
                                
                                // 对日期字段特殊处理
                                if ($fieldName == 'registration_time' && $value) {
                                    $value = $this->transformDate($value);
                                }
                                
                                $rowData[$fieldName] = $value;
                            }
                        }
                        
                        // 提取必要字段
                        $memberId = trim($rowData['member_id'] ?? '');
                        $registrationSource = trim($rowData['registration_source'] ?? '');
                        $registrationTime = trim($rowData['registration_time'] ?? '');
                        
                        // 如果注册来源为空，设置为"无来源"
                        if (empty($registrationSource)) {
                            $registrationSource = '无来源';
                            Log::info('注册来源为空，已设置为默认值', [
                                'row' => $processedRows,
                                'member_id' => $memberId,
                                'default_source' => $registrationSource
                            ]);
                        }
                        
                        // 检查必要字段
                        if (empty($registrationTime)) {
                            Log::warning('缺少注册时间的行数据', [
                                'row' => $processedRows,
                                'data' => $rowData
                            ]);
                            continue;
                        }
                        
                        // 数值字段验证和转换
                        $balanceDifference = 0;
                        $rawBalance = $rowData['balance_difference'] ?? '0';
                        
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
                            'currency' => $rowData['currency'] ?? 'PKR',
                            'member_id' => $memberId,
                            'member_account' => $rowData['member_account'] ?? '',
                            'channel_id' => $channelId,
                            'registration_source' => $registrationSource,
                            'registration_time' => $rowData['registration_time'],
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
                        'processed_rows' => $processedRows,
                        'inserted_rows' => $insertedRows ?? 0,
                        'error_rows' => $processedRows - ($insertedRows ?? 0),
                        'replaced_rows' => $replacedRows ?? 0
                    ]);
                    
                    // 释放内存
                    unset($allRecords);
                    unset($spreadsheet);
                    gc_collect_cycles();
                    
                    // 手动清理临时目录
                    Log::info('开始清理PhpSpreadsheet临时目录', ['tempDir' => $tempDir]);
                    $this->safeRemoveDirectory($tempDir);
                    
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
            
            // 完成后清理临时目录
            $tempDir = storage_path('app/temp');
            if (file_exists($tempDir) && is_dir($tempDir)) {
                // 遍历临时目录
                foreach (glob($tempDir . '/excel_*') as $dir) {
                    if (is_dir($dir)) {
                        // 使用安全删除方法清理
                        $this->safeRemoveDirectory($dir);
                    }
                }
            }
            
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
            // 使用精确的行数计算方法
            Log::info('开始计算文件行数');
            $startTime = microtime(true);
            
            // 无论文件大小，都使用精确计数
            $count = $this->estimateCsvRowCount($filePath);
                
                $duration = round(microtime(true) - $startTime, 2);
                Log::info('文件行数计算完成', [
                'count' => $count,
                    'duration_sec' => $duration,
                    'method' => 'direct_count'
                ]);
                
            return $count;
        } else {
            // 对于Excel文件，使用PHP读取
            try {
                $startTime = microtime(true);
                $reader = IOFactory::createReaderForFile($filePath);
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
            
            // 检查必要字段是否存在，只需要注册时间
            $requiredFields = ['registration_time'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headerMap)) {
                    $missingFields[] = $field;
                }
            }
            
            // 如果缺少必要字段，尝试识别
            if (!empty($missingFields)) {
                Log::warning('缺少必要字段(注册时间)，CSV文件处理失败', [
                    'missing_fields' => $missingFields
                ]);
                throw new \Exception("CSV文件缺少必要字段(注册时间)");
            }
            
            // 预加载所有渠道到内存
            $channels = [];
            Channel::chunk(500, function ($channelChunk) use (&$channels) {
                foreach ($channelChunk as $channel) {
                    $channels[$channel->name] = $channel->id;
                }
            });
            
            // 初始化统计变量
            $rowCount = 0;
            $insertedCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            $insertedRows = 0;
            $allRecords = [];
            
            // 设置CSV文件总行数（估计值，用于进度计算）
            $totalRows = $this->estimateCsvRowCount($filePath);
                    $this->importJob->update([
                'total_rows' => $totalRows
            ]);
            
            // 设置开始处理时间
            $this->importJob->update([
                'status' => 'processing',
                'started_at' => now()
            ]);
            
            // 处理数据行
            while (($row = fgetcsv($file)) !== false) {
                $rowCount++;
                
                // 处理行中可能的空值
                $row = array_map(function($val) {
                    return $val === '' ? null : $val;
                }, $row);
                
                // 如果是空行就跳过
                if (empty(array_filter($row, function($val) { return $val !== null; }))) {
                    $skippedCount++;
                        continue;
                    }
                    
                try {
                    // 映射CSV列到字段
                    $mappedRow = [];
                    foreach ($row as $index => $value) {
                        if (isset($headerMap[$index])) {
                            $fieldName = $headerMap[$index];
                            $mappedRow[$fieldName] = $value;
                        }
                    }
                    
                    // 提取必要字段
                    $memberId = trim($mappedRow['member_id'] ?? '');
                    $registrationSource = trim($mappedRow['registration_source'] ?? '');
                    $registrationTime = trim($mappedRow['registration_time'] ?? '');
                    
                    // 如果注册来源为空，设置为"无来源"
                    if (empty($registrationSource)) {
                        $registrationSource = '无来源';
                        // 删除注册来源为空的详细日志
                        // Log::info('注册来源为空，已设置为默认值', [
                        //     'row' => $rowCount,
                        //     'member_id' => $memberId,
                        //     'default_source' => $registrationSource
                        // ]);
                    }
                    
                    // 检查注册时间
                    if (empty($registrationTime)) {
                        Log::warning('缺少注册时间的行数据，已跳过', [
                            'row' => $rowCount
                        ]);
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
                    } else {
                        // 删除详细的非数字充提差额日志
                        // Log::warning('非数字的充提差额', [
                        //     'row' => $rowCount,
                        //     'value' => $rawBalance,
                        //     'converted' => 0
                        // ]);
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
                        $insertedCount += $this->batchInsertRecords($allRecords);
                        $insertedRows += count($allRecords);
                        $allRecords = []; // 清空数组
                        
                        // 更新进度
                        $this->importJob->update([
                            'processed_rows' => $rowCount,
                            'inserted_rows' => $insertedRows
                        ]);
                    }
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error('处理行数据失败', [
                        'row' => $rowCount,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // 减少进度日志频率，每50000行记录一次
                if ($rowCount % 50000 === 0) {
                    Log::info('CSV处理进度', [
                        'processed' => $rowCount,
                        'inserted' => $insertedRows
                    ]);
                }
            }
            
            // 插入剩余记录
            if (!empty($allRecords)) {
                $insertedCount += $this->batchInsertRecords($allRecords);
                $insertedRows += count($allRecords);
            }
            
            // 关闭文件
            fclose($file);
            
            // 更新导入任务状态
            $this->importJob->update([
                'status' => 'completed',
                'processed_rows' => $rowCount,
                'inserted_rows' => $insertedRows,
                'error_rows' => $rowCount - $insertedRows - $skippedCount,
                'completed_at' => now()
            ]);
            
            Log::info('CSV导入完成', [
                'total_rows' => $rowCount,
                'inserted_rows' => $insertedRows,
                'skipped_rows' => $skippedCount,
                'time_taken' => round(microtime(true) - $startTime, 2) . 's'
            ]);
            
            return true;
        } catch (\Exception $e) {
            // 更新导入任务状态为失败
                $this->importJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now()
            ]);
            
            Log::error('CSV导入失败', [
                'error' => $e->getMessage(),
                'file' => basename($filePath)
            ]);
            
            throw $e;
        }
    }
    
    /**
     * 批量插入记录
     */
    protected function batchInsertRecords($records)
    {
        try {
            DB::beginTransaction();
            DB::table('transactions')->insert($records);
            DB::commit();
            return count($records);
                    } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('批量插入记录失败', [
                'error' => $e->getMessage(),
                'count' => count($records)
            ]);
            return 0;
        }
    }

    /**
     * 估算CSV文件行数
     */
    protected function estimateCsvRowCount($filePath)
    {
        try {
            // 使用更准确的计数方法
            $lineCount = 0;
            $handle = fopen($filePath, 'r');
            
            if (!$handle) {
                Log::warning('无法打开CSV文件进行行数计算', ['file' => basename($filePath)]);
                return 0;
            }
            
            // 读取标题行，不计入总行数
            $headers = fgetcsv($handle);
            if ($headers === false) {
                Log::warning('CSV文件为空或格式错误', ['file' => basename($filePath)]);
                fclose($handle);
                return 0;
            }
            
            // 逐行计数
            while (($data = fgetcsv($handle)) !== FALSE) {
                // 跳过空行
                if (empty(array_filter($data))) {
                    continue;
                }
                $lineCount++;
            }
            
            fclose($handle);
            
            // 记录准确的行数
            Log::info('精确计算CSV文件行数', [
                'file' => basename($filePath),
                'exact_line_count' => $lineCount
            ]);
            
            return $lineCount;
        } catch (\Exception $e) {
            Log::warning('计算CSV行数失败', ['error' => $e->getMessage()]);
            return 0; // 出错时返回0，而不是估计值
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

    protected function processExcel()
    {
        $startTime = microtime(true);
        Log::info('开始处理Excel文件', [
            'file_path' => $this->importJob->file_path,
            'import_job_id' => $this->importJob->id
        ]);
        
        try {
            $filePath = storage_path('app/' . $this->importJob->file_path);
            
            Log::info('准备导入Excel文件', ['file' => basename($filePath)]);
            
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // 获取最大行和列
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
            
            Log::info('Excel文件信息', [
                'highest_row' => $highestRow,
                'highest_column' => $highestColumn,
                'highest_column_index' => $highestColumnIndex
            ]);
            
            // 获取列标题
            $headers = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $worksheet->getCellByColumnAndRow($col, 1);
                $value = $cell->getValue();
                
                // 处理公式单元格
                if ($cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                    try {
                        $value = $cell->getCalculatedValue();
                    } catch (\Exception $e) {
                        $value = $cell->getFormattedValue();
                    }
                }
                
                // 处理="xxx"格式
                if (is_string($value) && preg_match('/^="(.*)"$/', $value, $matches)) {
                    $value = $matches[1];
                } elseif (is_string($value) && substr($value, 0, 1) === '=') {
                    $value = substr($value, 1);
                }
                
                $headers[$col] = $this->ensureCorrectEncoding($value);
            }
            
            // 使用通用的mapHeaders方法处理表头
            $headerMap = $this->mapHeaders($headers);
            
            // 检查必要字段是否存在，只有注册时间是必须的
            $requiredFields = ['registration_time'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headerMap)) {
                    $missingFields[] = $field;
                }
            }
            
            // 如果缺少必要字段，尝试通过内容识别
            if (!empty($missingFields)) {
                Log::warning('缺少必要字段(注册时间)，尝试通过内容识别', [
                    'missing_fields' => $missingFields,
                    'headers' => $headers,
                    'mapped_headers' => $headerMap
                ]);
                
                try {
                    // 检查数据行，找出可能的日期列和来源列
                    if ($highestRow > 1) {
                        $timeColumnFound = false;
                        
                        for ($col = 1; $col <= $highestColumnIndex; $col++) {
                            // 已经映射则跳过
                            if (isset($headerMap[$col]) && in_array($headerMap[$col], $requiredFields)) {
                                if ($headerMap[$col] == 'registration_time') {
                                    $timeColumnFound = true;
                                }
                                continue;
                            }
                            
                            // 检查2-5行数据，识别列类型
                            $isDateColumn = false;
                            
                            for ($row = 2; $row <= min(5, $highestRow); $row++) {
                                $cell = $worksheet->getCellByColumnAndRow($col, $row);
                                $value = $cell->getValue();
                                
                                // 处理公式
                                if ($cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                                    try {
                                        $value = $cell->getCalculatedValue();
                                    } catch (\Exception $e) {
                                        $value = $cell->getFormattedValue();
                                    }
                                }
                                
                                // 处理="xxx"格式
                                if (is_string($value) && preg_match('/^="(.*)"$/', $value, $matches)) {
                                    $value = $matches[1];
                                } elseif (is_string($value) && substr($value, 0, 1) === '=') {
                                    $value = substr($value, 1);
                                }
                                
                                // 日期检测
                                if (!$timeColumnFound && !$isDateColumn && is_string($value)) {
                                    $datePatterns = [
                                        '/^\d{4}-\d{1,2}-\d{1,2}/', // YYYY-MM-DD
                                        '/^\d{4}\/\d{1,2}\/\d{1,2}/', // YYYY/MM/DD
                                        '/^\d{1,2}\/\d{1,2}\/\d{4}/', // MM/DD/YYYY
                                        '/^\d{1,2}-\d{1,2}-\d{4}/' // MM-DD-YYYY
                                    ];
                                    
                                    foreach ($datePatterns as $pattern) {
                                        if (preg_match($pattern, $value)) {
                                            $isDateColumn = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // 根据检测结果分配列
                            if (!$timeColumnFound && $isDateColumn && in_array('registration_time', $missingFields)) {
                                $headerMap[$col] = 'registration_time';
                                $timeColumnFound = true;
                                Log::info('根据内容识别注册时间列', ['column' => $col]);
                            }
                        }
                        
                        // 更新缺失字段列表
                        $missingFields = [];
                        foreach ($requiredFields as $field) {
                            if (!in_array($field, $headerMap)) {
                                $missingFields[] = $field;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('根据内容识别字段失败', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // 如果仍然缺少必要字段，则报错
            if (!empty($missingFields)) {
                Log::error('最终缺少必要字段', [
                    'missing_fields' => $missingFields,
                    'headers' => $headers,
                    'mapped_headers' => $headerMap
                ]);
                throw new \Exception("缺少必要字段(注册时间): " . implode(", ", $missingFields));
            }
            
            // 收集所有记录
            $allRecords = [];
            $processedRows = 0;
            $rows = []; // 初始化行数组
            
            // 将Excel数据转为行数组
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cell = $worksheet->getCellByColumnAndRow($col, $row);
                    $value = $cell->getValue();
                    
                    // 处理公式单元格
                    if ($cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                        try {
                            $value = $cell->getCalculatedValue();
                        } catch (\Exception $e) {
                            $value = $cell->getFormattedValue();
                        }
                    }
                    
                    // 处理="xxx"格式
                    if (is_string($value) && preg_match('/^="(.*)"$/', $value, $matches)) {
                        $value = $matches[1];
                    } elseif (is_string($value) && substr($value, 0, 1) === '=') {
                        $value = substr($value, 1);
                    }
                    
                    // 记录列值
                    if (isset($headerMap[$col])) {
                        $fieldName = $headerMap[$col];
                        $rowData[$fieldName] = $value;
                    }
                }
                $rows[] = $rowData;
            }
            
            Log::info('Excel数据读取完成', [
                'total_rows' => count($rows)
            ]);
            
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
            if (!empty($rows)) {
                Log::info('开始插入Excel数据', ['count' => count($rows)]);
                
                // 使用更小的批次插入记录，每批次单独使用事务
                $batchSize = 3000;
                $batches = array_chunk($rows, $batchSize);
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
            } else {
                Log::warning('没有有效的记录可插入', ['processed_rows' => $processedRows]);
            }
            
            // 更新最终进度
            $this->importJob->update([
                'processed_rows' => $processedRows,
                'inserted_rows' => $insertedRows ?? 0,
                'error_rows' => $processedRows - ($insertedRows ?? 0),
                'replaced_rows' => $replacedRows ?? 0
            ]);
            
            // 释放内存
            unset($rows);
            unset($allRecords);
            gc_collect_cycles();
            
        } catch (\Exception $e) {
            Log::error('Excel导入异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * 检查值是否类似日期
     */
    protected function looksLikeDate($value)
    {
        if (empty($value)) {
            return false;
        }
        
        // 数值不可能是日期
        if (is_numeric($value) && !preg_match('/^\d{8,14}$/', $value)) {
            return false;
        }
        
        // 常见日期格式检测
        $datePatterns = [
            '/^\d{4}-\d{1,2}-\d{1,2}/', // YYYY-MM-DD
            '/^\d{4}\/\d{1,2}\/\d{1,2}/', // YYYY/MM/DD
            '/^\d{1,2}\/\d{1,2}\/\d{4}/', // MM/DD/YYYY
            '/^\d{1,2}-\d{1,2}-\d{4}/' // MM-DD-YYYY
        ];
        
        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        // 尝试使用Carbon解析
        try {
            $date = Carbon::parse($value);
            return $date && $date->year >= 2000 && $date->year <= 2030;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 检查值是否可能是注册来源
     */
    protected function looksLikeSource($value)
    {
        if (empty($value)) {
            return false;
        }
        
        // 数字可能是ID，但不太可能是来源
        if (is_numeric($value)) {
            return false;
        }
        
        // 长度太短的不太可能是来源
        if (strlen(trim($value)) < 2) {
            return false;
        }
        
        // 常见来源关键词
        $sourceKeywords = ['渠道', '来源', '注册', 'channel', 'source', 'reg', 'platform'];
        foreach ($sourceKeywords as $keyword) {
            if (stripos($value, $keyword) !== false) {
                return true;
            }
        }
        
        // 长度合适且不包含日期特征的字符串可能是来源
        if (strlen(trim($value)) < 30 && !$this->looksLikeDate($value)) {
            return true;
        }
        
        return false;
    }
} 