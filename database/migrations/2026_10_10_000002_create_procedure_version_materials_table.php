<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-13: prosedür versiyonu beklenen malzemeleri listeler; her biri zorunlu ya da isteğe
     * bağlıdır. Liste versiyonla birlikte yayımlanır ve sonra değişmez (K-15).
     * procedure_versions.material_required listeden türetilir (ProcedureVersioning).
     */
    public function up(): void
    {
        Schema::create('procedure_version_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_version_id')->constrained();
            $table->foreignId('material_id')->constrained();
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['procedure_version_id', 'material_id'], 'procedure_version_materials_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_version_materials');
    }
};
