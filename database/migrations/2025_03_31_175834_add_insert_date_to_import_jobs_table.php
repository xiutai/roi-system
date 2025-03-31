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
            if (!Schema::hasColumn('import_jobs', 'insert_date')) {
                $table->date('insert_date')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('import_jobs', 'is_replacing_existing')) {
                $table->boolean('is_replacing_existing')->default(false)->after('insert_date');
            }
            if (!Schema::hasColumn('import_jobs', 'replaced_rows')) {
                $table->integer('replaced_rows')->default(0)->after('error_rows');
            }
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
            if (Schema::hasColumn('import_jobs', 'insert_date')) {
                $table->dropColumn('insert_date');
            }
            if (Schema::hasColumn('import_jobs', 'is_replacing_existing')) {
                $table->dropColumn('is_replacing_existing');
            }
            if (Schema::hasColumn('import_jobs', 'replaced_rows')) {
                $table->dropColumn('replaced_rows');
            }
        });
    }
};
