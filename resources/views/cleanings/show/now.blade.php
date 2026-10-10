{{--
    "Şimdi" kartı: sahada ilk bakılan yer (R-21–R-25). Güncel adım (çalışan ya da duraklatılmış
    adım, yoksa sıradaki bekleyen adım) ve o an yapılabilecek aksiyonlar. Formlar yalnızca kayıt
    açıksa ve kullanıcı kaydın sahibi ya da adımın görevlisiyse gösterilir (R-44, K-10, K-11).
--}}
@use('App\Enums\CleaningStatus')
@use('App\Enums\StepStatus')

@php
    $row = $current ? $stepRows[$current->id] : null;
    $phaseRow = $currentPhase ? $phaseRows[$currentPhase->id] : null;
    $stateLabel = match ($current?->status) {
        StepStatus::Running => 'Devam eden adım',
        StepStatus::Paused => 'Duraklatılmış adım',
        default => 'Sıradaki adım',
    };
    $closedText = match ($cleaning->status) {
        CleaningStatus::Completed => 'Temizlik tamamlandı ve kayıt kapandı; adımlarda işlem yapılamaz.',
        CleaningStatus::Cancelled => 'Kayıt iptal edildi; adımlarda işlem yapılamaz. Temizliğin tamamlanması gerekiyorsa yeni kayıt açılır.',
        default => 'Kaydın süresi doldu; adımlarda işlem yapılamaz. Temizlik yapılacaksa yeni kayıt açılır.',
    };
@endphp

<section id="now" @class([
    'card',
    'now-card',
    'now-card--'.str_replace('_', '-', $current?->status->value ?? 'none'),
    'now-card--closed' => ! $cleaning->status->isOpen(),
]) aria-labelledby="now-title">
    <div class="card-header">
        <h2 class="card-title" id="now-title">Şimdi</h2>
    </div>

    <div class="card-body">
        @if (! $cleaning->status->isOpen())
            <p class="now-card__closed mb-0">{{ $closedText }}</p>
        @elseif ($current === null)
            <p class="empty-state mb-0">Bekleyen adım yok.</p>
        @else
            <p class="now-card__kicker">
                <span class="now-card__state">{{ $stateLabel }}</span>
                <span class="now-card__phase">{{ $currentPhase->sequence }}. faz: {{ $currentPhase->procedurePhase->name }} · adım {{ $row['position'] }}/{{ $row['phaseStepCount'] }}</span>
            </p>

            <div class="now-card__heading">
                <h3 class="now-card__title">
                    <span class="now-card__sequence">{{ $current->sequence }}.</span>
                    {{ $current->procedureStep->title }}
                </h3>
                <x-status-badge :status="$current->status" class="now-card__status" />
            </div>

            @if (filled($current->procedureStep->description))
                <p class="now-card__description">{{ $current->procedureStep->description }}</p>
            @endif

            <dl class="now-card__facts">
                <div class="now-card__fact now-card__fact--workers">
                    <dt>Görevliler</dt>
                    <dd>{{ $row['assignees'] !== [] ? implode(', ', $row['assignees']) : 'Görevli yok' }}</dd>
                </div>
                @if ($row['times']['started'])
                    <div class="now-card__fact now-card__fact--net">
                        <dt>Çalışılan süre</dt>
                        <dd>@include('cleanings.show.duration', ['seconds' => $row['times']['net'], 'live' => $row['times']['live']['net']])</dd>
                    </div>
                    <div class="now-card__fact now-card__fact--effort">
                        <dt>İnsan eforu</dt>
                        <dd>@include('cleanings.show.duration', ['seconds' => $row['times']['effort'], 'live' => $row['times']['live']['effort']])</dd>
                    </div>
                @endif
                @if ($row['isLastOfPhase'])
                    <div class="now-card__fact now-card__fact--minimum">
                        <dt>Faz minimum süresi</dt>
                        <dd>
                            @if ($phaseRow['minimum'] > 0)
                                <x-duration :seconds="$phaseRow['minimum']" />
                            @else
                                Tanımlı değil
                            @endif
                        </dd>
                    </div>
                    @if ($phaseRow['minimum'] > 0 && $phaseRow['measured'] !== null)
                        <div class="now-card__fact now-card__fact--phase-time">
                            <dt>Faz süresi ({{ $phaseRow['includeGaps'] ? 'brüt' : 'net' }})</dt>
                            <dd>@include('cleanings.show.duration', ['seconds' => $phaseRow['measured'], 'live' => $phaseRow['live']])</dd>
                        </div>
                    @endif
                @endif
            </dl>

            @if ($row['isLastOfPhase'] && $phaseRow['minimum'] > 0)
                <p class="now-card__hint form-text">
                    Bu adım fazın son adımı. Faz süresi minimumun altında kalırsa adımı tamamlarken gerekçe yazmanız gerekir.
                </p>
            @endif

            @if ($materialMissing && $cleaning->status === CleaningStatus::Created)
                <div class="alert alert-warning now-card__alert" role="alert">
                    @if ($missingMaterials->isNotEmpty())
                        Zorunlu malzemeler için lot girilmeden ilk adım başlatılamaz:
                        {{ $missingMaterials->map(fn ($material) => "{$material->code} {$material->name}")->implode(', ') }}.
                    @else
                        Bu prosedürde malzeme zorunlu; en az bir geçerli malzeme girilmeden ilk adım başlatılamaz.
                    @endif
                    <a href="#materials" class="alert-link">Malzemelere git</a>
                </div>
            @endif

            @if ($canOperate)
                @include('cleanings.show.now-actions')
            @else
                <p class="now-card__notice" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Bu adımda görevli değilsiniz. Adımı yalnızca kaydın sahibi ({{ $cleaning->owner->name }}) ve adımın görevlileri yürütebilir.
                    @if ($cancelReasons !== [])
                        Kaydı <a href="#cancel">iptal edebilirsiniz</a>.
                    @endif
                </p>
            @endif

            @include('cleanings.show.media', [
                'media' => $row['media'],
                'alt' => $current->procedureStep->title,
                'preload' => 'metadata',
            ])
        @endif
    </div>
</section>
