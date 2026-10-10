{{--
    İlerleme göstergesi: kargo takip ekranlarındaki gibi noktadan noktaya ilerleyen dikey zaman
    çizelgesi. Kayıt açılışı → fazlar (büyük düğüm) ve altlarında adımlar (küçük nokta) → kapanış.
    Tamamlanan, devam eden, duraklatılan, gelecek ve kayıt kapandığı için yapılmayan faz ve adımlar
    renkle, simgeyle ve metinle ayrılır (renkler theme/_tokens.scss --app-progress-*).

    Her satır (düğüm + metin) kendi düğümünden bir sonraki düğüme giden çizgiyi çizer; çizginin
    biçimi bir sonraki düğümün durumuna göre seçilir: ulaşılmışsa dolu, gelecekse kesikli, kayıt
    kapandığı için yapılmayacaksa soluk.

    Adım bağlantısı Adımlar sekmesindeki adıma, güncel adımınki Şimdi sekmesine gider
    (modules/section-tabs.js). Veri controller'ın yüklediği ilişkilerden gelir; ek sorgu yapılmaz.
--}}
@use('App\Enums\CleaningStatus')
@use('App\Enums\PhaseStatus')
@use('App\Enums\StepStatus')

@php
    // İptal edilen ya da süresi dolan kayıtta tamamlanmamış faz ve adımlar artık yapılmayacak.
    $abandoned = in_array($cleaning->status, [CleaningStatus::Cancelled, CleaningStatus::Expired], true);

    $totalSteps = $cleaning->steps->count();
    $doneSteps = $cleaning->steps->filter(fn ($step) => $step->status === StepStatus::Completed)->count();
    $percent = $totalSteps > 0 ? intdiv($doneSteps * 100, $totalSteps) : 0;

    $phaseState = fn ($phase) => match (true) {
        $phase->status === PhaseStatus::Completed => 'completed',
        $abandoned => 'skipped',
        $phase->status === PhaseStatus::InProgress => 'current',
        default => 'upcoming',
    };
    $stepState = fn ($step) => match (true) {
        $step->status === StepStatus::Completed => 'completed',
        $abandoned => 'skipped',
        default => $step->status->value,
    };
    $endState = match ($cleaning->status) {
        CleaningStatus::Completed => 'completed',
        CleaningStatus::Cancelled => 'cancelled',
        CleaningStatus::Expired => 'expired',
        default => 'upcoming',
    };
    $endTitle = match ($endState) {
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal edildi',
        'expired' => 'Süresi doldu',
        default => 'Tamamlanacak',
    };
    $endIcon = match ($endState) {
        'completed' => 'bi-flag-fill',
        'cancelled' => 'bi-x-lg',
        'expired' => 'bi-hourglass-bottom',
        default => 'bi-flag',
    };

    // Satırların sırası: açılış, her faz başlığı ve adımları, kapanış; her biri [anahtar, durum,
    // ulaşıldı mı]. Başlamış ama kayıt kapandığı için yarıda kalan faz ve adıma da ulaşılmıştır.
    // Her satırın çizgisi bir sonraki satıra göre çizilir.
    $sequence = [['start', 'completed', true]];
    foreach ($cleaning->phases as $phase) {
        $sequence[] = ['phase-'.$phase->id, $phaseState($phase), $phase->started_at !== null];
        foreach ($phase->steps as $step) {
            $sequence[] = ['step-'.$step->id, $stepState($step), $step->started_at !== null];
        }
    }
    $sequence[] = ['end', $endState, $endState === 'completed'];

    $lines = [];
    foreach ($sequence as $index => [$key]) {
        [, $nextState, $nextReached] = $sequence[$index + 1] ?? [null, null, false];
        $lines[$key] = match (true) {
            $nextState === null => null,
            $nextReached => 'done',
            in_array($nextState, ['skipped', 'cancelled', 'expired'], true) => 'skipped',
            default => 'pending',
        };
    }
@endphp

<section id="progress" class="card progress-tracker" aria-labelledby="progress-title">
    <div class="card-header progress-tracker__header">
        <h2 class="card-title" id="progress-title">İlerleme</h2>
        <span class="card-meta progress-tracker__count">
            <strong>{{ $doneSteps }} / {{ $totalSteps }}</strong> adım tamamlandı
        </span>
        <div class="progress progress-tracker__bar" role="progressbar" aria-label="Tamamlanan adımlar"
             aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
             aria-valuetext="{{ $totalSteps }} adımdan {{ $doneSteps }} adım tamamlandı">
            <div class="progress-bar" style="width: {{ $percent }}%"></div>
        </div>
    </div>

    <div class="card-body">
        <ol class="progress-tracker__timeline">
            <li class="progress-row progress-milestone progress-milestone--start progress-milestone--completed progress-row--line-{{ $lines['start'] }}">
                <span class="progress-node progress-milestone__node" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
                <div class="progress-row__body">
                    <p class="progress-milestone__title">Kayıt açıldı</p>
                    <x-datetime :value="$cleaning->created_at" format="d.m.Y H:i" class="progress-row__time" />
                </div>
            </li>

            @foreach ($cleaning->phases as $phase)
                @php
                    $state = $phaseState($phase);
                    $phaseDone = $phase->steps->filter(fn ($step) => $step->status === StepStatus::Completed)->count();
                    $measured = $phaseRows[$phase->id]['measured'];
                    $stateLabel = match ($state) {
                        'skipped' => $phase->started_at ? 'Yarıda kaldı' : 'Yapılmadı',
                        default => $phase->status->label(),
                    };
                @endphp

                <li id="progress-phase-{{ $phase->id }}" class="progress-phase progress-phase--{{ $state }}">
                    <div class="progress-row progress-phase__header progress-row--line-{{ $lines['phase-'.$phase->id] }}">
                        <span class="progress-node progress-phase__node" aria-hidden="true">
                            @if ($state === 'completed')
                                <i class="bi bi-check-lg"></i>
                            @elseif ($state === 'skipped')
                                <i class="bi bi-dash-lg"></i>
                            @else
                                {{ $phase->sequence }}
                            @endif
                        </span>
                        <div class="progress-row__body">
                            <h3 class="progress-phase__title">
                                <span class="progress-phase__sequence">{{ $phase->sequence }}. faz</span>
                                {{ $phase->procedurePhase->name }}
                            </h3>
                            <p class="progress-phase__meta">
                                <span class="progress-phase__state">{{ $stateLabel }}</span>
                                <span class="progress-phase__count">{{ $phaseDone }} / {{ $phase->steps->count() }} adım</span>
                                @if ($state === 'completed' && $measured !== null)
                                    <span class="progress-phase__duration"><span class="visually-hidden">Süre</span> <x-duration :seconds="$measured" /></span>
                                @endif
                            </p>
                            @if ($phase->below_minimum)
                                <p class="progress-phase__deviation">
                                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                    Minimum süre altında kapandı
                                </p>
                            @endif
                        </div>
                    </div>

                    @if ($phase->steps->isNotEmpty())
                        <ol class="progress-phase__steps">
                            @foreach ($phase->steps as $step)
                                @php
                                    $stepStateName = $stepState($step);
                                    $isCurrent = $current !== null && $current->id === $step->id;
                                    $stepLabel = match ($stepStateName) {
                                        'skipped' => $step->started_at ? 'Yarıda kaldı' : 'Yapılmadı',
                                        default => $step->status->label(),
                                    };
                                @endphp

                                <li @class([
                                    'progress-row',
                                    'progress-step',
                                    'progress-step--'.$stepStateName,
                                    'progress-step--current' => $isCurrent,
                                    'progress-row--line-'.$lines['step-'.$step->id],
                                ]) @if ($isCurrent) aria-current="step" @endif>
                                    <span class="progress-node progress-step__node" aria-hidden="true">
                                        @switch ($stepStateName)
                                            @case ('completed')
                                                <i class="bi bi-check"></i>
                                                @break
                                            @case ('paused')
                                                <i class="bi bi-pause-fill"></i>
                                                @break
                                            @case ('skipped')
                                                <i class="bi bi-dash"></i>
                                                @break
                                        @endswitch
                                    </span>
                                    <div class="progress-row__body progress-step__body">
                                        <a href="#step-{{ $step->id }}" class="progress-step__link">
                                            <span class="progress-step__sequence">{{ $step->sequence }}.</span>
                                            {{ $step->procedureStep->title }}
                                        </a>
                                        {{-- Tamamlanan ve bekleyen adımda durum simgeden anlaşılır; metin ekran okuyucu için. --}}
                                        <span @class([
                                            'progress-step__state',
                                            'visually-hidden' => in_array($stepStateName, ['completed', 'pending'], true),
                                        ])>{{ $stepLabel }}</span>
                                        @if ($isCurrent)
                                            <a href="#now" class="progress-step__now">{{ $step->status === StepStatus::Pending ? 'Sıradaki' : 'Şu an' }}</a>
                                        @endif
                                        @if ($stepStateName === 'completed')
                                            <x-datetime :value="$step->completed_at" format="H:i" class="progress-row__time progress-step__time" />
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach

            <li class="progress-row progress-milestone progress-milestone--end progress-milestone--{{ $endState }}">
                <span class="progress-node progress-milestone__node" aria-hidden="true"><i class="bi {{ $endIcon }}"></i></span>
                <div class="progress-row__body">
                    <p class="progress-milestone__title">{{ $endTitle }}</p>
                    @if ($cleaning->closed_at)
                        <x-datetime :value="$cleaning->closed_at" format="d.m.Y H:i" class="progress-row__time" />
                    @endif
                </div>
            </li>
        </ol>
    </div>
</section>
