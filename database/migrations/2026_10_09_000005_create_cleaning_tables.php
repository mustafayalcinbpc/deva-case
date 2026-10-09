<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // K-17: kayıt numarası ve saha defteri referansı için sayaçlar.
        Schema::create('sequence_counters', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedInteger('value');
        });

        Schema::create('cleanings', function (Blueprint $table) {
            $table->id();
            $table->string('record_no')->unique();
            $table->string('field_ref')->nullable()->unique();
            $table->string('type');
            $table->string('status')->index();
            $table->foreignId('facility_id')->constrained();
            $table->foreignId('line_id')->constrained();
            $table->foreignId('machine_id')->constrained();
            $table->foreignId('procedure_version_id')->constrained();
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->text('cancel_note')->nullable();
            $table->timestamps();

            // K-05: bir makinede en fazla bir başlamış kayıt. Kayıt devam ederken
            // makine id'sini, diğer durumlarda NULL alır; unique index NULL'ları saymaz.
            $table->unsignedBigInteger('active_machine_id')->nullable()
                ->storedAs("case when `status` = 'in_progress' then `machine_id` end");
            $table->unique('active_machine_id');
        });

        Schema::create('cleaning_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_id')->constrained();
            $table->foreignId('procedure_phase_id')->constrained();
            $table->unsignedSmallInteger('sequence');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // K-01, K-02: kapanışta ölçülen süre ve minimum süre sapması.
            $table->unsignedInteger('measured_seconds')->nullable();
            $table->boolean('below_minimum')->default(false);
            $table->text('deviation_reason')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_id', 'sequence']);
        });

        Schema::create('cleaning_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_id')->constrained();
            $table->foreignId('cleaning_phase_id')->constrained();
            $table->foreignId('procedure_step_id')->constrained();
            // Temizlik içindeki genel sıra (fazlar arası); adım sırası kuralı buna bakar.
            $table->unsignedSmallInteger('sequence');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_id', 'sequence']);
        });

        // Adımın görevli listesi. Çıkarılan görevli silinmez, removed_at alır.
        Schema::create('cleaning_step_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_step_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->foreignId('removed_by')->nullable()->constrained('users');
            $table->timestamp('removed_at')->nullable();

            $table->unsignedBigInteger('active_user_id')->nullable()
                ->storedAs('case when `removed_at` is null then `user_id` end');
            $table->unique(['cleaning_step_id', 'active_user_id']);
        });

        // K-03, K-04: adımın kesintisiz çalışılan her bölümü bir dilimdir.
        Schema::create('work_slices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_step_id')->constrained();
            $table->timestamp('started_at');
            $table->foreignId('started_by')->constrained('users');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->string('end_reason')->nullable();

            // Bir adımda aynı anda en fazla bir açık dilim.
            $table->unsignedBigInteger('open_step_id')->nullable()
                ->storedAs('case when `ended_at` is null then `cleaning_step_id` end');
            $table->unique('open_step_id');
        });

        Schema::create('work_slice_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_slice_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('ended_at')->nullable();

            // K-07: bir kişi aynı anda en fazla bir açık dilimde bulunur.
            $table->unsignedBigInteger('active_user_id')->nullable()
                ->storedAs('case when `ended_at` is null then `user_id` end');
            $table->unique('active_user_id');
            $table->unique(['work_slice_id', 'user_id']);
        });

        // K-12: yanlış girilen malzeme silinmez, gerekçeyle geçersiz işaretlenir.
        Schema::create('cleaning_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_id')->constrained();
            $table->foreignId('material_id')->constrained();
            $table->string('lot_no');
            $table->date('expiry_date');
            $table->foreignId('added_by')->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_materials');
        Schema::dropIfExists('work_slice_workers');
        Schema::dropIfExists('work_slices');
        Schema::dropIfExists('cleaning_step_assignees');
        Schema::dropIfExists('cleaning_steps');
        Schema::dropIfExists('cleaning_phases');
        Schema::dropIfExists('cleanings');
        Schema::dropIfExists('sequence_counters');
    }
};
