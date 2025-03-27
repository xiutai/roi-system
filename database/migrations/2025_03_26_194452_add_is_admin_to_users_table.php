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
        // 已经在创建用户表时添加了is_admin字段，所以这里不再需要添加
        // 原代码：
        // Schema::table('users', function (Blueprint $table) {
        //     $table->boolean('is_admin')->default(false)->comment('是否为管理员')->after('password');
        // });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // 由于不再添加该字段，回滚也不需要删除
        // 原代码：
        // Schema::table('users', function (Blueprint $table) {
        //     $table->dropColumn('is_admin');
        // });
    }
};
