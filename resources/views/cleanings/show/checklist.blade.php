{{--
    Kontrol listesi: kayda bağlı prosedür versiyonunun bütün fazları ve adımları (R-03, R-04, R-13).
    Her adım id="step-{id}" taşır; aksiyonlardan sonra sayfa bu adıma döner. Güncel adım vurgulanır.
    Süreler çalışma dilimlerinden hesaplanır: net süre ve insan eforu ayrı gösterilir (R-26).
--}}
@use('App\Enums\PhaseStatus')
@use('App\Enums\StepStatus')

<section id="checklist" class="card checklist" aria-labelledby="checklist-title">
    <div class="card-header">
        <h2 class="card-title" id="checklist-title">Kontrol listesi</h2>
    </div>

    <div class="card-body">
        <ol class="checklist__phases">
            @foreach ($cleaning->phases as $phase)
                @php
                    $phaseRow = $phaseRows[$phase->id];
                @endphp

                <li id="phase-{{ $phase->id }}" class="checklist-phase checklist-phase--{{ str_replace('_', '-', $phase->status->value) }}">
                    <div class="checklist-phase__header">
                        <h3 class="checklist-phase__title">
                            <span class="checklist-phase__sequence">{{ $phase->sequence }}. faz</span>
                            {{ $phase->procedurePhase->name }}
                        </h3>
                        <x-status-badge :status="$phase->status" />
                    </div>

                    <dl class="checklist-phase__facts">
                        <div class="checklist-phase__fact">
                            <dt>Minimum süre</dt>
                            <dd>
                                @if ($phaseRow['minimum'] > 0)
                                    <x-duration :seconds="$phaseRow['minimum']" />
                                @else
                                    Tanımlı değil
                                @endif
                            </dd>
                        </div>
                        <div class="checklist-phase__fact">
                            <dt>Süre ölçümü</dt>
                            <dd>{{ $phaseRow['includeGaps'] ? 'Brüt (adımlar arası boşluklar dahil)' : 'Net (adımlar arası boşluklar sayılmaz)' }}</dd>
                        </div>
                        @if ($phaseRow['measured'] !== null)
                            <div class="checklist-phase__fact checklist-phase__fact--measured">
                                <dt>{{ $phase->status === PhaseStatus::Completed ? 'Ölçülen süre' : 'Şu ana kadarki süre' }}</dt>
                                <dd>@include('cleanings.show.duration', ['seconds' => $phaseRow['measured'], 'live' => $phaseRow['live']])</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($phase->below_minimum)
                        <div class="alert alert-warning checklist-phase__deviation" role="note">
                            <strong>Minimum süre altında kapandı.</strong>
                            Gerekçe: {{ $phase->deviation_reason }}
                        </div>
                    @endif

                    <ol class="checklist-phase__steps">
                        @foreach ($phase->steps as $step)
                            @php
                                $stepRow = $stepRows[$step->id];
                                $isCurrent = $current !== null && $current->id === $step->id;
                            @endphp

                            <li id="step-{{ $step->id }}" @class([
                                'checklist-step',
                                'checklist-step--'.$step->status->value,
                                'checklist-step--current' => $isCurrent,
                            ]) @if ($isCurrent) aria-current="step" @endif>
                                <div class="checklist-step__header">
                                    <span class="checklist-step__sequence">{{ $step->sequence }}.</span>
                                    <h4 class="checklist-step__title">{{ $step->procedureStep->title }}</h4>
                                    <x-status-badge :status="$step->status" />
                                    @if ($isCurrent)
                                        <a href="#now" class="checklist-step__current">Şu anki adım · işlemlere git</a>
                                    @endif
                                </div>

                                @if (filled($step->procedureStep->description))
                                    <p class="checklist-step__description">{{ $step->procedureStep->description }}</p>
                                @endif

                                <dl class="checklist-step__facts">
                                    @if ($step->status !== StepStatus::Completed)
                                        <div class="checklist-step__fact checklist-step__fact--assignees">
                                            <dt>Görevliler</dt>
                                            <dd>{{ $stepRow['assignees'] !== [] ? implode(', ', $stepRow['assignees']) : 'Görevli yok' }}</dd>
                                        </div>
                                    @endif
                                    @if ($stepRow['times']['started'])
                                        <div class="checklist-step__fact checklist-step__fact--worked-by">
                                            <dt>Çalışanlar</dt>
                                            <dd>{{ implode(', ', $stepRow['workedBy']) }}</dd>
                                        </div>
                                        <div class="checklist-step__fact">
                                            <dt>İlk başlangıç</dt>
                                            <dd><x-datetime :value="$step->started_at" format="d.m.Y H:i:s" /></dd>
                                        </div>
                                        <div class="checklist-step__fact">
                                            <dt>Tamamlanma</dt>
                                            <dd><x-datetime :value="$step->completed_at" format="d.m.Y H:i:s" /></dd>
                                        </div>
                                        <div class="checklist-step__fact checklist-step__fact--net">
                                            <dt>Net süre</dt>
                                            <dd>@include('cleanings.show.duration', ['seconds' => $stepRow['times']['net'], 'live' => $stepRow['times']['live']['net']])</dd>
                                        </div>
                                        <div class="checklist-step__fact checklist-step__fact--effort">
                                            <dt>İnsan eforu</dt>
                                            <dd>@include('cleanings.show.duration', ['seconds' => $stepRow['times']['effort'], 'live' => $stepRow['times']['live']['effort']])</dd>
                                        </div>
                                        <div class="checklist-step__fact checklist-step__fact--slices">
                                            <dt>Çalışma dilimi</dt>
                                            <dd>{{ $stepRow['sliceCount'] }}</dd>
                                        </div>
                                    @endif
                                </dl>

                                @if ($stepRow['media'])
                                    <details class="checklist-step__media">
                                        <summary>{{ $stepRow['media']['type'] === 'video' ? 'Videoyu göster' : 'Fotoğrafı göster' }}</summary>
                                        @include('cleanings.show.media', [
                                            'media' => $stepRow['media'],
                                            'alt' => $step->procedureStep->title,
                                            'preload' => 'none',
                                        ])
                                    </details>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </li>
            @endforeach
        </ol>
    </div>
</section>
