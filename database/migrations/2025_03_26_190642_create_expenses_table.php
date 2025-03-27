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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('日期');
            $table->foreignId('channel_id')->constrained()->comment('渠道ID');
            $table->decimal('amount', 10, 2)->default(0)->comment('消耗金额');
            $table->boolean('is_default')->default(false)->comment('是否为默认值');
            $table->timestamps();

            // 添加联合唯一索引，确保每个渠道在每个日期只有一个消耗记录
            $table->unique(['date', 'channel_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('expenses');
    }
};
