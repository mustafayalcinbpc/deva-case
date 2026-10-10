<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-19: üretim iş emrinin durumu ve zamanları (gerçekte ERP'den gelir). "Tamamlandı"ya geçiş
     * temizlik planlarının tetiğidir (K-20).
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('product')->nullable()->after('description');
            $table->string('status')->default('planned')->after('product')->index();
            $table->timestamp('planned_start_at')->nullable()->after('status');
            $table->timestamp('planned_end_at')->nullable()->after('planned_start_at');
            $table->timestamp('completed_at')->nullable()->after('planned_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['product', 'status', 'planned_start_at', 'planned_end_at', 'completed_at']);
        });
    }
};
