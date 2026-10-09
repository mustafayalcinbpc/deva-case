<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tanım değişiklik günlüğü (R-49): tesis, hat, makine, prosedür, malzeme, iş emri ve
        // kullanıcı tanımlarında kim, ne zaman, neyi değiştirdi. cleaning_events gibi yalnızca
        // eklenir; satırlar trigger'larla korunur.
        Schema::create('definition_changes', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            // NULL: değişikliği sistem yaptı (konsol, seed).
            $table->foreignId('actor_id')->nullable()->constrained('users');
            // Değişen tanım (DefinitionChange::SUBJECT_TYPES) ve değişiklik anındaki kısa adı.
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label');
            // Değişikliğin geçmişinde göründüğü tanım: çoğunlukla kendisi; versiyon, faz ve adım için prosedür.
            $table->string('root_type', 40);
            $table->unsignedBigInteger('root_id');
            $table->string('action', 40);
            // {alan: {old, new, old_label?, new_label?}}; şifre ve oturum anahtarı hiç yazılmaz.
            $table->json('fields')->nullable();

            $table->index(['occurred_at', 'id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['root_type', 'root_id']);
            $table->index('action');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER definition_changes_no_update BEFORE UPDATE ON definition_changes
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'definition_changes kayıtları değiştirilemez'
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER definition_changes_no_delete BEFORE DELETE ON definition_changes
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'definition_changes kayıtları silinemez'
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS definition_changes_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS definition_changes_no_delete');
        }

        Schema::dropIfExists('definition_changes');
    }
};
