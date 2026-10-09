<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Yayımlanmış versiyon değiştirilmez; her değişiklik yeni versiyondur (K-15).
        Schema::create('procedure_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained();
            $table->unsignedInteger('version');
            $table->boolean('material_required')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['procedure_id', 'version']);
        });

        Schema::create('procedure_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_version_id')->constrained();
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->unsignedInteger('min_duration_seconds')->default(0);
            // K-02: adımlar arasındaki boşluklar faz süresine dahil mi?
            $table->boolean('include_gaps')->default(false);
            $table->timestamps();

            $table->unique(['procedure_version_id', 'sequence']);
        });

        Schema::create('procedure_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_phase_id')->constrained();
            $table->unsignedSmallInteger('sequence');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('media_path')->nullable();
            $table->timestamps();

            $table->unique(['procedure_phase_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_steps');
        Schema::dropIfExists('procedure_phases');
        Schema::dropIfExists('procedure_versions');
        Schema::dropIfExists('procedures');
    }
};
