<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Malzeme silinmez (kayıtlar ona bağlıdır); kullanımdan kaldırılan malzeme yeni girişlerde
     * seçilemez, geçmiş kayıtlarda görünmeye devam eder (K-13, R-10).
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
