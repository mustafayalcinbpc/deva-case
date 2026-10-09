<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->string('code');
            $table->string('name');
            $table->timestamps();

            $table->unique(['facility_id', 'code']);
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained();
            // K-18: her makinenin tek geçerli prosedürü var.
            $table->foreignId('procedure_id')->nullable()->constrained();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['line_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
        Schema::dropIfExists('lines');
        Schema::dropIfExists('facilities');
    }
};
