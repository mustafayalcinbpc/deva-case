<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Değiştirilemez olay kaydı (R-46–R-49). Her olay, aynı kaydın bir önceki
        // olayının hash'ini içerir; araya kayıt eklenmesi ya da bir olayın
        // değiştirilmesi zinciri bozar.
        Schema::create('cleaning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_id')->constrained();
            $table->unsignedInteger('sequence');
            $table->string('type');
            // NULL: işlemi sistem yaptı (ör. süre dolumu).
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->unique(['cleaning_id', 'sequence']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cleaning_events_no_update BEFORE UPDATE ON cleaning_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cleaning_events kayıtları değiştirilemez'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cleaning_events_no_delete BEFORE DELETE ON cleaning_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cleaning_events kayıtları silinemez'
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS cleaning_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS cleaning_events_no_delete');
        }

        Schema::dropIfExists('cleaning_events');
    }
};
