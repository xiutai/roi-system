<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->comment('导入文件名');
            $table->string('original_filename')->comment('原始文件名');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->comment('任务状态');
            $table->unsignedInteger('total_rows')->nullable()->comment('总行数');
            $table->unsignedInteger('processed_rows')->default(0)->comment('已处理行数');
            $table->unsignedInteger('inserted_rows')->default(0)->comment('新增行数');
            $table->unsignedInteger('updated_rows')->default(0)->comment('更新行数');
            $table->unsignedInteger('error_rows')->default(0)->comment('错误行数');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->unsignedInteger('user_id')->nullable()->comment('上传用户ID');
            $table->timestamp('started_at')->nullable()->comment('开始处理时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamps();
            
            // 添加索引提高查询性能
            $table->index('status');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_jobs');
    }
}; 