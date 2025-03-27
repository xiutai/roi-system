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
        Schema::create('roi_calculations', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('计算日期');
            $table->foreignId('channel_id')->constrained()->comment('渠道ID');
            $table->integer('day_count')->comment('天数(1日/2日/3日/...)');
            $table->decimal('cumulative_balance', 15, 2)->default(0)->comment('累计充提差额');
            $table->decimal('exchange_rate', 10, 2)->default(0)->comment('汇率');
            $table->decimal('expense', 10, 2)->default(0)->comment('消耗');
            $table->decimal('roi_percentage', 10, 2)->default(0)->comment('ROI百分比');
            $table->timestamps();
            
            // 添加联合唯一索引，确保每个渠道在每个日期的每个天数统计只有一条记录
            $table->unique(['date', 'channel_id', 'day_count']);
            
            // 添加索引来提高查询性能
            $table->index(['channel_id', 'date']);
            $table->index('day_count');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('roi_calculations');
    }
};
