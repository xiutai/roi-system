<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ClearAllData extends Command
{
    /**
     * 命令名称和签名
     *
     * @var string
     */
    protected $signature = 'data:clear {--force : 强制执行不需要确认}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '清空所有业务数据表（交易、ROI计算、渠道、消耗、汇率等）';

    /**
     * 需要清空的表
     *
     * @var array
     */
    protected $tables = [
        'transactions',
        'roi_calculations',
        'expenses',
        'exchange_rates',
        'channels',
    ];

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('此操作将清空所有业务数据，且无法恢复！确定要继续吗？')) {
            $this->info('操作已取消');
            return 0;
        }

        $this->info('开始清空数据...');
        
        // 暂时禁用外键约束
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        try {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    $count = DB::table($table)->count();
                    DB::table($table)->truncate();
                    $this->info("已清空表 {$table}，共删除 {$count} 条记录");
                    Log::info("已清空表 {$table}，共删除 {$count} 条记录");
                } else {
                    $this->warn("表 {$table} 不存在，已跳过");
                }
            }
            
            $this->info('所有数据已成功清空！');
            Log::info('所有数据已成功清空！');
        } catch (\Exception $e) {
            $this->error('清空数据时出错: ' . $e->getMessage());
            Log::error('清空数据时出错: ' . $e->getMessage());
            return 1;
        } finally {
            // 重新启用外键约束
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        
        return 0;
    }
} 