<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-24: görev planlandığı an görünür, müdahale vakti (scheduled_at) gelene kadar ondan kayıt
     * açılmaz. Üretim iş emri tetikli planda vakit, emir tamamlanınca belli olur (o zamana kadar
     * NULL). Son tarih (due_at) vakit + planın gecikme toleransıdır; vakit belli değilse NULL.
     */
    public function up(): void
    {
        Schema::table('cleaning_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('tolerance_hours')->default(4)->after('interval_days');
        });

        Schema::table('cleaning_tasks', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('work_order_id');
            $table->timestamp('due_at')->nullable()->change();
        });

        // Önceki görevlerde son tarih aynı zamanda müdahale vaktiydi.
        DB::table('cleaning_tasks')->update(['scheduled_at' => DB::raw('due_at')]);
    }

    public function down(): void
    {
        DB::table('cleaning_tasks')->whereNull('due_at')->update(['due_at' => DB::raw('created_at')]);

        Schema::table('cleaning_tasks', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
            $table->timestamp('due_at')->nullable(false)->change();
        });

        Schema::table('cleaning_plans', function (Blueprint $table) {
            $table->dropColumn('tolerance_hours');
        });
    }
};
