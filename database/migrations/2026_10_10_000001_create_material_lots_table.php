<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-14: son kullanma tarihi lotun özelliğidir; lot bir kez tanımlanır (gerçekte depo/ERP),
     * operatör kayıtta yalnızca lotu seçer. Kayıttaki lot no ve SKT, seçildiği andaki kopyadır:
     * lot sonradan düzeltilse de geçmiş kayıt değişmez (R-13). Eski satırlarda lot bağlantısı yoktur.
     */
    public function up(): void
    {
        Schema::create('material_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained();
            $table->string('lot_no');
            $table->date('expiry_date');
            $table->date('received_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['material_id', 'lot_no']);
        });

        Schema::table('cleaning_materials', function (Blueprint $table) {
            $table->foreignId('material_lot_id')->nullable()->after('material_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_lot_id');
        });

        Schema::dropIfExists('material_lots');
    }
};
