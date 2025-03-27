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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('日期');
            $table->decimal('rate', 10, 2)->default(0)->comment('汇率');
            $table->boolean('is_default')->default(false)->comment('是否为默认值');
            $table->timestamps();

            // 添加唯一索引，确保每个日期只有一个汇率记录
            $table->unique('date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('exchange_rates');
    }
};
