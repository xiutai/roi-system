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
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->longText('error_details')->nullable()->comment('详细错误信息（JSON格式）')->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('error_details');
        });
    }
};
