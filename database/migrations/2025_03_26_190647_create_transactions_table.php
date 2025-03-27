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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('currency')->comment('币种');
            $table->string('member_id')->comment('会员ID');
            $table->string('member_account')->comment('会员账号');
            $table->integer('channel_id')->comment('渠道ID');
            $table->string('registration_source')->comment('注册来源');
            $table->dateTime('registration_time')->comment('注册时间');
            $table->decimal('balance_difference', 10, 2)->default(0)->comment('充提差额');
            $table->timestamps();
            
            // 添加索引来提高查询性能
            $table->index('registration_source');
            $table->index('registration_time');
            $table->index(['channel_id', 'registration_time']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transactions');
    }
};
