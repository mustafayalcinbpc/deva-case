<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-23: yönetici açık görevi gerekçe yazarak iptal eder (ör. makine bakımda, plan dışı
     * temizlik zaten yapıldı). Kim ve neden iptal ettiği görevde kalır; planın etkin görevi boşalır.
     */
    public function up(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('closed_at')->constrained('users');
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancel_reason');
        });
    }
};
