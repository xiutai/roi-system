<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Channel;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixChannelIdMappings extends Command
{
    /**
     * 命令的名称和签名
     *
     * @var string
     */
    protected $signature = 'fix:channel-ids {--dry-run : 只显示将要进行的更改，但不实际执行}';

    /**
     * 命令的描述
     *
     * @var string
     */
    protected $description = '修复交易表中的渠道ID，确保它们与渠道表中的ID一致';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle()
    {
        $this->info('开始修复交易表中的渠道ID...');
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('⚠️ 干运行模式：将显示所有更改但不执行');
        }
        
        // 1. 获取所有渠道名称和现有ID映射
        $channels = Channel::all()->pluck('id', 'name')->toArray();
        $this->info('找到 ' . count($channels) . ' 个渠道');
        
        // 2. 获取所有唯一的渠道名称和ID组合
        $uniqueCombinations = DB::table('transactions')
            ->select('registration_source', 'channel_id')
            ->distinct()
            ->get();
            
        $this->info('找到 ' . $uniqueCombinations->count() . ' 个唯一的渠道名称和ID组合');
        
        // 3. 建立映射并识别需要修复的记录
        $mappings = [];
        $toFix = [];
        
        foreach ($uniqueCombinations as $combo) {
            $channelName = $combo->registration_source;
            $oldChannelId = $combo->channel_id;
            
            // 如果此渠道名称存在于channels表中
            if (isset($channels[$channelName])) {
                $correctChannelId = $channels[$channelName];
                
                // 如果现有ID不匹配，需要修复
                if ($oldChannelId != $correctChannelId) {
                    $toFix[] = [
                        'name' => $channelName,
                        'old_id' => $oldChannelId,
                        'new_id' => $correctChannelId
                    ];
                    
                    $mappings[$oldChannelId] = $correctChannelId;
                }
            } else {
                // 渠道不存在，需要创建新渠道
                if (!$dryRun) {
                    $channel = Channel::create([
                        'name' => $channelName,
                        'description' => '由修复脚本自动创建'
                    ]);
                    $newId = $channel->id;
                    $channels[$channelName] = $newId;
                } else {
                    $newId = '[将创建新渠道]';
                }
                
                $toFix[] = [
                    'name' => $channelName,
                    'old_id' => $oldChannelId,
                    'new_id' => $newId,
                    'action' => 'create'
                ];
            }
        }
        
        // 4. 显示将要进行的修复
        if (empty($toFix)) {
            $this->info('✅ 所有渠道ID已经是正确的，无需修复');
            return 0;
        }
        
        $this->info('需要修复以下 ' . count($toFix) . ' 个渠道ID映射:');
        $headers = ['渠道名称', '旧ID', '新ID', '影响记录数'];
        $rows = [];
        
        foreach ($toFix as $fix) {
            $count = DB::table('transactions')
                ->where('registration_source', $fix['name'])
                ->where('channel_id', $fix['old_id'])
                ->count();
                
            $rows[] = [
                $fix['name'],
                $fix['old_id'],
                $fix['new_id'],
                $count
            ];
        }
        
        $this->table($headers, $rows);
        
        if ($dryRun) {
            $this->warn('这是干运行，没有进行实际修改。使用 --no-dry-run 选项执行实际更新。');
            return 0;
        }
        
        // 确认是否继续
        if (!$this->confirm('确定要更新这些渠道ID吗?', true)) {
            $this->info('操作已取消');
            return 0;
        }
        
        // 5. 执行更新
        $this->info('开始更新渠道ID...');
        $totalUpdated = 0;
        
        try {
            DB::beginTransaction();
            
            foreach ($toFix as $fix) {
                if (isset($fix['action']) && $fix['action'] == 'create') {
                    // 如果是新创建的渠道，修复ID
                    $count = DB::table('transactions')
                        ->where('registration_source', $fix['name'])
                        ->where('channel_id', $fix['old_id'])
                        ->update(['channel_id' => $channels[$fix['name']]]);
                } else {
                    // 更新现有渠道ID
                    $count = DB::table('transactions')
                        ->where('registration_source', $fix['name'])
                        ->where('channel_id', $fix['old_id'])
                        ->update(['channel_id' => $fix['new_id']]);
                }
                
                $this->info("已更新 {$fix['name']} 的 {$count} 条记录，从 {$fix['old_id']} 改为 " . 
                    (isset($fix['action']) ? $channels[$fix['name']] : $fix['new_id']));
                    
                $totalUpdated += $count;
            }
            
            DB::commit();
            $this->info("✅ 成功修复 {$totalUpdated} 条记录的渠道ID");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('更新失败: ' . $e->getMessage());
            Log::error('修复渠道ID失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }
} 