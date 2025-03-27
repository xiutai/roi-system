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
        // 检查exchange_rates表是否存在is_default列，若不存在则添加
        if (!Schema::hasColumn('exchange_rates', 'is_default')) {
            Schema::table('exchange_rates', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->comment('是否为默认值');
            });
        }

        // 检查expenses表是否存在is_default列，若不存在则添加
        if (!Schema::hasColumn('expenses', 'is_default')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->comment('是否为默认值');
            });
        }

        // 检查channels表是否存在description列，若不存在则添加
        if (!Schema::hasColumn('channels', 'description')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->string('description')->nullable()->comment('渠道描述');
            });
        }

        // 检查roi_calculations表是否存在date列，若不存在则添加
        if (!Schema::hasColumn('roi_calculations', 'date')) {
            Schema::table('roi_calculations', function (Blueprint $table) {
                $table->date('date')->comment('计算日期');
                // 添加索引
                $table->index(['channel_id', 'date']);
                $table->unique(['date', 'channel_id', 'day_count']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // 此处不需要回滚，因为删除这些列可能会导致应用不可用
    }
};
