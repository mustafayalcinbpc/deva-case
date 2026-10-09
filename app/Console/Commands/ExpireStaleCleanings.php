<?php

namespace App\Console\Commands;

use App\Services\Cleaning\CleaningWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cleanings:expire-stale')]
#[Description('Süresi içinde ilk adımı başlatılmayan temizlik kayıtlarını "süresi doldu" durumuna alır (K-06).')]
final class ExpireStaleCleanings extends Command
{
    public function handle(CleaningWorkflow $workflow): int
    {
        $expired = $workflow->expireStale();

        $this->info("Süresi dolan kayıt: {$expired}");

        return self::SUCCESS;
    }
}
