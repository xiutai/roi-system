<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ImportJob;
use Illuminate\Support\Facades\Storage;

class DeleteImportJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:delete {id : The ID of the import job to delete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '删除指定ID的导入任务';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $id = $this->argument('id');
        
        try {
            $importJob = ImportJob::findOrFail($id);
            
            $this->info("找到导入任务 #{$id}:");
            $this->line("文件名: {$importJob->original_filename}");
            $this->line("状态: {$importJob->status}");
            $this->line("处理行数: {$importJob->processed_rows} / {$importJob->total_rows}");
            
            if (!$this->confirm('确认要删除此导入任务吗?')) {
                $this->info('操作已取消');
                return 0;
            }
            
            // 删除文件
            $filePath = storage_path('app/imports/' . $importJob->filename);
            if (file_exists($filePath)) {
                unlink($filePath);
                $this->info("已删除文件: {$importJob->filename}");
            } else {
                $this->warn("文件不存在: {$importJob->filename}");
            }
            
            // 删除数据库记录
            $importJob->delete();
            $this->info("导入任务 #{$id} 已成功删除");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("删除导入任务失败: {$e->getMessage()}");
            return 1;
        }
    }
} 