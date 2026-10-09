<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Olay zincirlerinin kontrol noktaları (R-46–R-49). Her satır, bir andaki bütün kayıtların
        // zincir başlarını özetler ve bir önceki kontrol noktasının özetini içerir. Aynı satır
        // audit log kanalına da yazılır; o kopya sunucu dışına taşınınca zincirin dış çapası olur.
        // Hesaplama: App\Services\Cleaning\AuditCheckpoints.
        Schema::create('audit_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence')->unique();
            // Kapsanan olaylar: cleaning_events.id <= max_event_id.
            $table->unsignedBigInteger('max_event_id');
            $table->unsignedBigInteger('event_count');
            $table->unsignedInteger('cleaning_count');
            // Önceki kontrol noktasından bu yana zinciri ilerleyen kayıtlar: [[cleaning_id, sequence, hash], ...]
            $table->json('heads');
            $table->char('heads_digest', 64);
            $table->char('previous_digest', 64)->nullable();
            $table->char('digest', 64)->unique();
            $table->timestamp('created_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_checkpoints_no_update BEFORE UPDATE ON audit_checkpoints
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_checkpoints kayıtları değiştirilemez'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_checkpoints_no_delete BEFORE DELETE ON audit_checkpoints
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_checkpoints kayıtları silinemez'
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_checkpoints_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_checkpoints_no_delete');
        }

        Schema::dropIfExists('audit_checkpoints');
    }
};
