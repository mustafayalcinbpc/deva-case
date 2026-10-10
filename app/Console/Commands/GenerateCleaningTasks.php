<?php

namespace App\Console\Commands;

use App\Services\Planning\CleaningTaskGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cleaning:generate-tasks')]
#[Description('Periyodik temizlik planlarından zamanı gelen görevleri açar ve geciken görevleri yöneticilere bildirir (K-20, K-23).')]
final class GenerateCleaningTasks extends Command
{
    public function handle(CleaningTaskGenerator $generator): int
    {
        $opened = $generator->generateDue(now());
        $notified = $generator->notifyOverdue(now());

        $this->info("Açılan görev: {$opened}");
        $this->info("Gecikme bildirilen görev: {$notified}");

        return self::SUCCESS;
    }
}
