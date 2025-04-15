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
        
        // 保留关键日志
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
            
            // 验证文件格式
            if (!in_array($extension, ['csv', 'txt'])) {
                throw new \Exception("不支持的文件格式: {$extension}，只支持CSV或TXT格式");
            }
            
            // 计算文件大小
            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                throw new \Exception("无法获取文件大小: {$filePath}");
            }
            
            // 计算总行数
            $totalRows = $this->countFileRows($filePath, $extension);
            $this->importJob->update(['total_rows' => $totalRows]);
            
            // 处理CSV文件
            $this->processCsvFile($filePath);
            
        } catch (\Exception $e) {
            // 捕获异常，标记任务失败
            Log::error('导入任务处理异常', [
                'job_id' => $this->importJob->id,
                'error' => $e->getMessage()
            ]);
            
            try {
                $this->importJob->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 200),
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
        
        // 内存和执行时间设置
        ini_set('memory_limit', '4096M'); // 增加内存限制到4GB
        set_time_limit(0); // 禁用时间限制
        
        // 优化MySQL会话设置，提高导入性能
        DB::statement('SET session wait_timeout=28800'); // 增加会话超时时间
        DB::statement('SET session interactive_timeout=28800');
        DB::statement('SET session net_read_timeout=3600');
        DB::statement('SET session net_write_timeout=3600');
        
        // 禁用查询日志以减少内存使用
        DB::disableQueryLog();
        
        // 开启垃圾回收
        gc_enable();
        
        // 检查和创建必要的索引，以优化导入性能
        $this->ensureRequiredIndexes();
        
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
            $updatedCount = 0;
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
                    
                    if (empty($registrationSource)) {
                        $registrationSource = '无来源';
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
                    }
                    // 获取或创建渠道
                    if (!isset($channels[$registrationSource])) {
                        try {
                            // 确保渠道名称使用正确的编码 - 加强转换方式
                            $originalSource = $registrationSource;
                            $encodedSource = $this->ensureCorrectEncoding($registrationSource);
                            
                            // 检查编码转换前后是否有变化
                            $sourceChanged = ($encodedSource !== $originalSource);
                            
                            
                            // 使用转换后的渠道名称
                            $registrationSource = $encodedSource;
                            
                            // 再次检查已存在的渠道映射
                            if (isset($channels[$registrationSource])) {
                            }
                            else {
                                // 先按名称查找是否已存在该渠道
                                $existingChannel = Channel::where('name', $registrationSource)->first();
                                
                                if ($existingChannel) {
                                    // 如果已存在，直接使用
                                    $channels[$registrationSource] = $existingChannel->id;
                                } else {
                                    // 创建新渠道，使用转换后的名称
                                    $channel = new Channel();
                                    $channel->name = $registrationSource;
                                    $channel->description = '从导入数据自动创建';
                                    $channel->save();
                                    $channels[$registrationSource] = $channel->id;
                                }
                            }
                        } catch (\Exception $e) {
                            // 记录详细错误信息
                            Log::error('渠道创建异常', [
                                'source' => $registrationSource ?? 'unknown',
                                'hex' => isset($registrationSource) ? bin2hex($registrationSource) : '',
                                'error' => $e->getMessage(),
                                'error_code' => $e->getCode(),
                                'error_trace' => array_slice($e->getTrace(), 0, 3)
                            ]);
                            
                            // 遇到错误时使用默认渠道
                            $defaultChannelName = '默认渠道';
                            if (!isset($channels[$defaultChannelName])) {
                                // 查找或创建默认渠道
                                $defaultChannel = Channel::firstOrCreate(
                                    ['name' => $defaultChannelName],
                                    ['description' => '导入数据默认渠道']
                                );
                                $channels[$defaultChannelName] = $defaultChannel->id;
                                
                            }
                            
                            // 将错误的渠道映射到默认渠道
                            $channels[$registrationSource] = $channels[$defaultChannelName];
                            
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
                    
                    // 检查会员ID
                    if (empty($record['member_id'])) {
                        $skippedCount++;
                        continue;
                    }
                    
                    // 确保member_id是字符串类型
                    $record['member_id'] = (string)$record['member_id'];
                    
                    $allRecords[] = $record;
                    
                    // 每3000行批量插入一次
                    if (count($allRecords) >= 5000) { // 增加批量处理大小到5000
                        $result = $this->batchInsertRecords($allRecords);
                        $insertedCount += $result['inserted'];
                        $updatedCount += $result['updated'];
                        $insertedRows = $insertedCount + $updatedCount;
                        $allRecords = []; // 清空数组
                        
                        // 更新进度，但减少更新频率 - 每15000行才更新一次数据库
                        if ($rowCount % 15000 === 0) {
                            $this->importJob->update([
                                'processed_rows' => $rowCount,
                                'inserted_rows' => $insertedCount,
                                'updated_rows' => $updatedCount
                            ]);
                            
                            // 强制回收内存
                            gc_collect_cycles();
                        }
                    }
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    // 增强错误日志
                    Log::error('处理行数据失败', [
                        'row' => $rowCount,
                        'error' => $e->getMessage(),
                        'error_code' => $e->getCode(),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                        'error_trace' => array_slice($e->getTrace(), 0, 3)  // 只记录前3个堆栈信息，避免日志过大
                    ]);
                    
                    // 如果是渠道创建错误，记录额外信息
                    if (stripos($e->getMessage(), 'channels') !== false) {
                        Log::error('疑似编码问题导致渠道创建失败', [
                            'row' => $rowCount,
                            'source' => $registrationSource ?? '未知',
                            'mysql_charset' => DB::select('SHOW VARIABLES LIKE "character_set%"'),
                            'php_charset' => mb_list_encodings()
                        ]);
                    }
                }
                
                // 减少进度日志频率，每50000行记录一次
                if ($rowCount % 50000 === 0) {
                    Log::info('CSV处理进度', [
                        'processed' => $rowCount,
                        'inserted' => $insertedRows
                    ]);
                }
            }
            
            // 处理剩余的记录
            if (count($allRecords) > 0) {
                $result = $this->batchInsertRecords($allRecords);
                $insertedCount += $result['inserted'];
                $updatedCount += $result['updated'];
                $insertedRows = $insertedCount + $updatedCount;
            }
            
            // 关闭文件
            fclose($file);
            
            // 使用统计的计数而不是重新查询数据库
            $newRows = $insertedCount;
            $updatedRows = $updatedCount;
            
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
                'updated_rows' => $updatedRows,
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
     * 批量处理记录 - 根据日期和会员ID判断插入或更新
     * 规则：
     * 1. 如果数据库中已存在相同insert_date和member_id的记录，则更新该记录
     * 2. 如果不存在对应的insert_date和member_id组合，则插入新记录
     * 3. 不同insert_date的记录永远不会被更新，即使member_id相同
     * 
     * @param array $records 要处理的记录数组
     * @return array 包含插入和更新记录数的数组 ['inserted' => 数量, 'updated' => 数量]
     */
    protected function batchInsertRecords($records)
    {
        if (empty($records)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        try {
            DB::beginTransaction();
            
            $insertedCount = 0;
            $updatedCount = 0;
            $batchSize = 1000; // 增加批量处理大小
            
            // 这是所有导入记录的目标日期
            $targetDate = $this->importJob->insert_date;
            
            // 先清理记录中可能的重复项 - 使用引用直接修改数组值
            $uniqueRecords = [];
            
            foreach ($records as $record) {
                if (!isset($record['insert_date']) || !isset($record['member_id'])) {
                    continue;
                }
                
                // 标准化处理会员ID: 移除空格并确保为字符串类型
                $memberId = (string)trim($record['member_id']);
                $record['member_id'] = $memberId;
                
                // 确保所有记录使用正确的目标日期
                $record['insert_date'] = $targetDate;
                
                // 创建唯一键
                $key = $targetDate . '_' . $memberId;
                
                // 只保留相同组合的最后一条记录
                $uniqueRecords[$key] = $record;
            }
            
            // 如果没有有效记录，提前返回
            if (empty($uniqueRecords)) {
                return ['inserted' => 0, 'updated' => 0];
            }
            
            // 提取所有会员ID，去重
            $memberIds = [];
            foreach ($uniqueRecords as $key => $record) {
                $memberIds[] = $record['member_id'];
            }
            $memberIds = array_values(array_unique($memberIds));
            
            // 分批查询已存在的记录，减少单次查询负担
            $existingMemberIds = [];
            foreach (array_chunk($memberIds, 5000) as $memberIdChunk) {
                $existingRecords = DB::table('transactions')
                    ->where('insert_date', $targetDate)
                    ->whereIn('member_id', $memberIdChunk)
                    ->select('id', 'member_id')
                    ->get();
                
                foreach ($existingRecords as $record) {
                    $memberId = (string)trim($record->member_id);
                    $existingMemberIds[$memberId] = $record->id;
                }
            }
            
            // 分拣记录 - 直接使用引用优化内存
            $toInsert = [];
            $toUpdate = [];
            
            foreach ($uniqueRecords as $key => $record) {
                $memberId = $record['member_id']; // 已经标准化过的会员ID
                
                if (array_key_exists($memberId, $existingMemberIds)) {
                    $record['existing_id'] = $existingMemberIds[$memberId];
                    $toUpdate[] = $record;
                } else {
                    $toInsert[] = $record;
                }
            }
            
            // 清理不再需要的数据以释放内存
            unset($uniqueRecords);
            unset($memberIds);
            unset($existingMemberIds);
            
            // 批量插入新记录
            if (!empty($toInsert)) {
                // 使用单一SQL语句批量插入 - 适用于较大批量的插入
                $insertChunks = array_chunk($toInsert, $batchSize);
                
                foreach ($insertChunks as $chunk) {
                    // 构建INSERT语句的VALUES部分
                    $values = [];
                    $now = now()->format('Y-m-d H:i:s');
                    
                    foreach ($chunk as $record) {
                        $values[] = "(" .
                            DB::connection()->getPdo()->quote($record['currency']) . "," .
                            DB::connection()->getPdo()->quote($record['member_id']) . "," .
                            DB::connection()->getPdo()->quote($record['member_account']) . "," .
                            (int)$record['channel_id'] . "," .
                            DB::connection()->getPdo()->quote($record['registration_source']) . "," .
                            DB::connection()->getPdo()->quote($record['registration_time']) . "," .
                            (float)$record['balance_difference'] . "," .
                            DB::connection()->getPdo()->quote($record['insert_date']) . "," .
                            DB::connection()->getPdo()->quote($now) . "," .
                            DB::connection()->getPdo()->quote($now) . ")";
                    }
                    
                    if (!empty($values)) {
                        try {
                            // 使用原生SQL进行批量插入
                            $sql = "INSERT INTO transactions 
                                    (currency, member_id, member_account, channel_id, 
                                     registration_source, registration_time, balance_difference, 
                                     insert_date, created_at, updated_at) 
                                    VALUES " . implode(',', $values);
                            
                            DB::statement($sql);
                        } catch (\Exception $e) {
                            // 如果原生SQL插入失败，回退到Laravel的方法
                            Log::warning('原生SQL批量插入失败，使用Laravel方法', [
                                'error' => $e->getMessage()
                            ]);
                            DB::table('transactions')->insert($chunk);
                        }
                    }
                }
                
                $insertedCount = count($toInsert);
            }
            
            // 批量更新已存在的记录 - 使用分块减少循环次数
            if (!empty($toUpdate)) {
                $updateCount = 0;
                $recordsToUpdateInBatch = [];
                
                foreach ($toUpdate as $index => $record) {
                    if (isset($record['existing_id'])) {
                        $recordId = $record['existing_id'];
                        
                        // 只保留需要的字段以减少数据量
                        $recordsToUpdateInBatch[] = [
                            'id' => $recordId,
                            'currency' => $record['currency'],
                            'member_account' => $record['member_account'],
                            'channel_id' => $record['channel_id'],
                            'registration_source' => $record['registration_source'],
                            'registration_time' => $record['registration_time'],
                            'balance_difference' => $record['balance_difference'],
                            'updated_at' => now()
                        ];
                        
                        $updateCount++;
                        
                        // 每积累batchSize条记录执行一次批量更新
                        if ($updateCount % $batchSize === 0) {
                            $this->performBatchUpdate($recordsToUpdateInBatch);
                            $recordsToUpdateInBatch = []; // 清空数组准备下一批
                        }
                    }
                }
                
                // 处理剩余的更新记录
                if (!empty($recordsToUpdateInBatch)) {
                    $this->performBatchUpdate($recordsToUpdateInBatch);
                }
                
                $updatedCount = count($toUpdate);
            }
            
            // 提交事务
            DB::commit();
            
            // 返回包含插入和更新记录数的数组
            return [
                'inserted' => $insertedCount, 
                'updated' => $updatedCount
            ];
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
     * 执行批量更新操作 - 高效版
     * 
     * @param array $records 要更新的记录数组
     * @return void
     */
    protected function performBatchUpdate($records)
    {
        if (empty($records)) {
            return;
        }
        
        // 使用更大的批量大小
        $chunks = array_chunk($records, 200);
        
        foreach ($chunks as $chunk) {
            if (empty($chunk)) {
                continue;
            }
            
            // 构建更高效的批量更新查询
            $updateValues = [];
            $ids = [];
            
            foreach ($chunk as $record) {
                $ids[] = $record['id'];
                
                // 对每个记录构建完整的更新值集合
                $updateValues[] = "(" . 
                    $record['id'] . "," .
                    DB::connection()->getPdo()->quote($record['currency']) . "," . 
                    DB::connection()->getPdo()->quote($record['member_account']) . "," . 
                    DB::connection()->getPdo()->quote($record['channel_id']) . "," . 
                    DB::connection()->getPdo()->quote($record['registration_source']) . "," . 
                    DB::connection()->getPdo()->quote($record['registration_time']) . "," . 
                    DB::connection()->getPdo()->quote($record['balance_difference']) . ")";
            }
            
            // 没有记录需要更新
            if (empty($updateValues)) {
                continue;
            }
            
            // 使用单一高效SQL - ON DUPLICATE KEY UPDATE代替CASE WHEN
            try {
                // 用临时表方式批量更新 - 对于MySQL 5.7+效率更高
                $tempTable = 'temp_update_' . mt_rand(10000, 99999);
                
                // 创建临时表
                DB::statement("CREATE TEMPORARY TABLE {$tempTable} (
                    id INT NOT NULL,
                    currency VARCHAR(10),
                    member_account VARCHAR(100),
                    channel_id INT,
                    registration_source VARCHAR(255),
                    registration_time VARCHAR(50),
                    balance_difference DECIMAL(15,2)
                )");
                
                // 插入数据到临时表
                $insertSql = "INSERT INTO {$tempTable} (id, currency, member_account, channel_id, registration_source, registration_time, balance_difference) VALUES " . implode(',', $updateValues);
                DB::statement($insertSql);
                
                // 从临时表更新主表
                $updateSql = "UPDATE transactions t, {$tempTable} tt 
                              SET t.currency = tt.currency,
                                  t.member_account = tt.member_account,
                                  t.channel_id = tt.channel_id,
                                  t.registration_source = tt.registration_source,
                                  t.registration_time = tt.registration_time,
                                  t.balance_difference = tt.balance_difference,
                                  t.updated_at = NOW()
                              WHERE t.id = tt.id";
                DB::statement($updateSql);
                
                // 删除临时表
                DB::statement("DROP TEMPORARY TABLE IF EXISTS {$tempTable}");
                
            } catch (\Exception $e) {
                // 如果临时表方法失败，回退到传统方法
                Log::warning('高效批量更新失败，使用传统方法', ['error' => $e->getMessage()]);
                
                // 传统直接更新方法
                $updateStmt = "UPDATE transactions 
                               SET currency = CASE id ";
                
                foreach ($chunk as $record) {
                    $id = $record['id'];
                    $updateStmt .= " WHEN {$id} THEN " . DB::connection()->getPdo()->quote($record['currency']);
                }
                
                $updateStmt .= " ELSE currency END,
                               member_account = CASE id ";
                               
                foreach ($chunk as $record) {
                    $id = $record['id'];
                    $updateStmt .= " WHEN {$id} THEN " . DB::connection()->getPdo()->quote($record['member_account']);
                }
                
                $updateStmt .= " ELSE member_account END,
                               channel_id = CASE id ";
                               
                foreach ($chunk as $record) {
                    $id = $record['id'];
                    $updateStmt .= " WHEN {$id} THEN " . DB::connection()->getPdo()->quote($record['channel_id']);
                }
                
                $updateStmt .= " ELSE channel_id END,
                               registration_source = CASE id ";
                               
                foreach ($chunk as $record) {
                    $id = $record['id'];
                    $updateStmt .= " WHEN {$id} THEN " . DB::connection()->getPdo()->quote($record['registration_source']);
                }
                
                $updateStmt .= " ELSE registration_source END,
                               registration_time = CASE id ";
                               
                foreach ($chunk as $record) {
                    $id = $record['id'];
                    $updateStmt .= " WHEN {$id} THEN " . DB::connection()->getPdo()->quote($record['registration_time']);
                }
                
                $updateStmt .= " ELSE registration_time END,
                               balance_difference = CASE id ";
                               
                foreach ($chunk as $record) {
                    $id = $record['id'];
                    $updateStmt .= " WHEN {$id} THEN " . DB::connection()->getPdo()->quote($record['balance_difference']);
                }
                
                $updateStmt .= " ELSE balance_difference END,
                               updated_at = NOW()
                               WHERE id IN (" . implode(',', $ids) . ")";
                
                DB::statement($updateStmt);
            }
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
            
            // 确保标头类型正确
            $headerToCheck = (string)$header;
            
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
        
        // 确保是字符串
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
            // 修复：如果不是字符串，转为字符串
            return (string)$str;
        }
        
        // 处理Excel导出的特殊格式 ="xxx"
        if (preg_match('/^="(.*)"$/', $str, $matches)) {
            $str = $matches[1];
        }
        
        // 处理可能的双重引号
        if (strpos($str, '""') !== false) {
            $str = str_replace('""', '"', $str);
        }
        
        // 检测当前编码
        $detectedEncoding = mb_detect_encoding($str, ['UTF-8', 'GBK', 'GB2312', 'CP936', 'GB18030'], true);
        
        // 转换编码 - 优先处理中文Windows编码
        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            // 明确指定CP936/GBK/GB2312转UTF-8，避免自动检测错误
            if (in_array($detectedEncoding, ['CP936', 'GBK', 'GB2312', 'GB18030'])) {
                $converted = mb_convert_encoding($str, 'UTF-8', $detectedEncoding);
                return $converted;
            }
            
            // 其他编码使用自动检测
            return mb_convert_encoding($str, 'UTF-8', $detectedEncoding);
        }
        
        // 如果检测不到编码但不是UTF-8，尝试通用转换
        if (!mb_check_encoding($str, 'UTF-8')) {
            // 按可能性顺序尝试编码
            $possibleEncodings = ['CP936', 'GBK', 'GB2312', 'GB18030', 'ISO-8859-1', 'Windows-1252'];
            
            foreach ($possibleEncodings as $encoding) {
                $converted = mb_convert_encoding($str, 'UTF-8', $encoding);
                // 如果转换后变成了有效的UTF-8，则使用该结果
                if (mb_check_encoding($converted, 'UTF-8') && $converted !== $str) {
                    return $converted;
                }
            }
            
            // 最后尝试自动检测
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
            // 尝试重新从数据库加载导入任务，以防$this->importJob为null
            if (!$this->importJob) {
                $this->importJob = ImportJob::find($this->importJobId);
            }
            
            // 只有当导入任务存在时才更新
            if ($this->importJob) {
                // 获取简短的错误消息，限制长度确保不会超出数据库字段
                $shortErrorMessage = mb_substr('导入任务失败: ' . $exception->getMessage(), 0, 200);
                
                // 更新任务状态为失败，只保存简短的错误信息
                $this->importJob->update([
                    'status' => 'failed',
                    'error_message' => $shortErrorMessage,
                    'completed_at' => now(),
                ]);
            } else {
                // 如果找不到导入任务，记录错误
                Log::error("找不到导入任务记录以更新失败状态", [
                    'job_id' => $this->importJobId ?? 'unknown',
                    'error' => $exception->getMessage()
                ]);
            }
            
            // 记录完整错误到日志
            Log::error("导入任务失败", [
                'job_id' => $this->importJobId ?? 'unknown',
                'error' => $exception->getMessage()
            ]);
        } catch (\Exception $e) {
            // 如果更新失败日志记录也失败，至少记录一下
            Log::critical('无法更新失败的导入任务状态', [
                'job_id' => $this->importJobId ?? 'unknown',
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

    /**
     * 检查和创建必要的索引，以优化导入性能
     */
    protected function ensureRequiredIndexes()
    {
        try {
            // 检查transactions表是否存在必要的索引
            $indexExists = false;
            
            // 检查insert_date和member_id组合索引是否存在
            $indexes = DB::select("SHOW INDEXES FROM transactions WHERE Key_name = 'transactions_insert_date_member_id_index'");
            $indexExists = !empty($indexes);
            
            if (!$indexExists) {
                // 记录索引不存在，但不在导入过程中创建
                Log::info('Insert_date和member_id的索引不存在，建议单独创建以提高性能');
                
                // 索引不存在，仍继续处理导入，不阻塞流程
            } else {
                Log::info('Insert_date和member_id的组合索引已存在，将提高导入性能');
            }
        } catch (\Exception $e) {
            // 如果检查索引失败，记录错误但继续执行导入
            Log::warning('检查索引时出错，继续处理', ['error' => $e->getMessage()]);
        }
    }
}