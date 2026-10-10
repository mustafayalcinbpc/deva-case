<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-20, K-21, K-23: temizlik planı yapılması gereken temizliği görev olarak üretir; kayıt,
     * görevden açılır. Görev kayıt değildir: ileri tarihlidir ve K-06'daki süre dolumu ona işlemez.
     * Bir planın aynı anda tek açık görevi olur (open_plan_id: açık ya da kayda bağlı görevde
     * plan id'si, aksi halde NULL; unique index). Bir görevden aynı anda tek kayıt açılır.
     */
    public function up(): void
    {
        Schema::create('cleaning_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained();
            $table->string('kind');
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_task_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cleaning_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained();
            $table->foreignId('cleaning_plan_id')->constrained();
            $table->string('source');
            $table->foreignId('trigger_work_order_id')->nullable()->constrained('work_orders');
            $table->foreignId('work_order_id')->nullable()->constrained();
            $table->timestamp('due_at');
            $table->string('status')->index();
            $table->unsignedBigInteger('open_plan_id')->nullable()->unique();
            $table->unsignedBigInteger('cleaning_id')->nullable()->unique();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('cleanings', function (Blueprint $table) {
            $table->foreignId('cleaning_task_id')->nullable()->after('work_order_id')->constrained();
        });

        Schema::table('cleaning_tasks', function (Blueprint $table) {
            $table->foreign('cleaning_id')->references('id')->on('cleanings');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $table) {
            $table->dropForeign(['cleaning_id']);
        });

        Schema::table('cleanings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cleaning_task_id');
        });

        Schema::dropIfExists('cleaning_tasks');
        Schema::dropIfExists('cleaning_plans');
    }
};
