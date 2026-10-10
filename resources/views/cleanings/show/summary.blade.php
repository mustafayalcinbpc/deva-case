{{--
    Kaydın özeti: R-45'teki soruların cevapları (nerede, kim, hangi prosedür versiyonu, hangi iş
    emri, ne zaman, ne kadar sürede, ne kadar eforla). Net süre, brüt süre ve insan eforu ayrı
    ayrı ve açıklamalarıyla gösterilir (R-26, K-02).
--}}
@use('App\Enums\CleaningStatus')

@php
    $version = $cleaning->procedureVersion;
    $closedLabel = match ($cleaning->status) {
        CleaningStatus::Completed => 'Tamamlanma',
        CleaningStatus::Cancelled => 'İptal zamanı',
        CleaningStatus::Expired => 'Süre dolumu',
        default => 'Kapanış',
    };
    $expiredEvent = $cleaning->status === CleaningStatus::Expired
        ? $cleaning->events->firstWhere('type', 'cleaning.expired')
        : null;
@endphp

<section id="summary" class="card cleaning-summary" aria-labelledby="summary-title">
    <div class="card-header">
        <h2 class="card-title" id="summary-title">Özet</h2>
    </div>

    <div class="card-body">
        <dl class="cleaning-summary__list">
            <div class="cleaning-summary__item">
                <dt>Kayıt no</dt>
                <dd class="record-no">{{ $cleaning->record_no }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Saha defteri referansı</dt>
                <dd>
                    @if ($cleaning->field_ref)
                        <span class="record-no">{{ $cleaning->field_ref }}</span>
                    @elseif ($cleaning->type->hasFieldReference())
                        <span class="cleaning-summary__empty">İlk adım başlatıldığında üretilir</span>
                    @else
                        <span class="cleaning-summary__empty">Yok (plansız müdahale saha defterine işlenmez)</span>
                    @endif
                </dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Tür</dt>
                <dd>{{ $cleaning->type->label() }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Durum</dt>
                <dd><x-status-badge :status="$cleaning->status" /></dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Tesis</dt>
                <dd>{{ $cleaning->facility->code }} — {{ $cleaning->facility->name }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Hat</dt>
                <dd>{{ $cleaning->line->code }} — {{ $cleaning->line->name }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Makine</dt>
                <dd>{{ $cleaning->machine->code }} — {{ $cleaning->machine->name }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Sorumlu</dt>
                <dd>{{ $cleaning->owner->name }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Prosedür</dt>
                <dd>
                    {{ $version->procedure->code }} — {{ $version->procedure->name }}
                    <span class="cleaning-summary__version">versiyon {{ $version->version }}</span>
                </dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Malzeme</dt>
                <dd>{{ $version->material_required ? 'Zorunlu' : 'Zorunlu değil' }}</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Üretim iş emri</dt>
                <dd>
                    @if ($cleaning->workOrder)
                        <span class="record-no">{{ $cleaning->workOrder->code }}</span>
                        @if (filled($cleaning->workOrder->description))
                            — {{ $cleaning->workOrder->description }}
                        @endif
                    @else
                        <span class="cleaning-summary__empty">—</span>
                    @endif
                </dd>
            </div>
            {{-- K-21: kayıt bir görevden açıldıysa görevin nedeni ve son tarihi. --}}
            <div class="cleaning-summary__item cleaning-summary__task">
                <dt>Görev</dt>
                <dd>
                    @if ($cleaning->task)
                        {{ $cleaning->task->source->label() }}
                        @if ($cleaning->task->triggerWorkOrder)
                            · <span class="record-no">{{ $cleaning->task->triggerWorkOrder->code }}</span>
                        @endif
                        <span class="cleaning-summary__version">son tarih <x-datetime :value="$cleaning->task->due_at" /></span>
                    @else
                        <span class="cleaning-summary__empty">Görevsiz açıldı</span>
                    @endif
                </dd>
            </div>
            <div class="cleaning-summary__item cleaning-summary__item--wide">
                <dt>Açıklama</dt>
                {{-- Tek satırda: açıklama satır sonlarını korur (white-space: pre-line). --}}
                <dd class="cleaning-summary__notes">@if (filled($cleaning->notes)){{ $cleaning->notes }}@else<span class="cleaning-summary__empty">—</span>@endif</dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Açılış</dt>
                <dd><x-datetime :value="$cleaning->created_at" format="d.m.Y H:i:s" /></dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>Başlangıç</dt>
                <dd><x-datetime :value="$cleaning->started_at" format="d.m.Y H:i:s" /></dd>
            </div>
            <div class="cleaning-summary__item">
                <dt>{{ $closedLabel }}</dt>
                <dd><x-datetime :value="$cleaning->closed_at" format="d.m.Y H:i:s" /></dd>
            </div>
        </dl>

        <h3 class="cleaning-summary__subtitle">Süre ve efor</h3>
        <dl class="cleaning-summary__times">
            <div class="cleaning-summary__time cleaning-summary__time--net">
                <dt>Net çalışma süresi</dt>
                <dd class="cleaning-summary__value">
                    @if ($totals['started'])
                        @include('cleanings.show.duration', ['seconds' => $totals['net'], 'live' => $totals['live']['net']])
                    @else
                        <span class="cleaning-summary__empty">—</span>
                    @endif
                </dd>
                <dd class="cleaning-summary__hint">Adımlarda gerçekten çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz.</dd>
            </div>
            <div class="cleaning-summary__time cleaning-summary__time--gross">
                <dt>Brüt süre</dt>
                <dd class="cleaning-summary__value">
                    @if ($totals['started'])
                        @include('cleanings.show.duration', ['seconds' => $totals['gross'], 'live' => $totals['live']['gross']])
                    @else
                        <span class="cleaning-summary__empty">—</span>
                    @endif
                </dd>
                <dd class="cleaning-summary__hint">İlk adımın başlangıcından son çalışmanın bitişine kadar geçen süre; aradaki boşluklar dahil.</dd>
            </div>
            <div class="cleaning-summary__time cleaning-summary__time--effort">
                <dt>İnsan eforu</dt>
                <dd class="cleaning-summary__value">
                    @if ($totals['started'])
                        @include('cleanings.show.duration', ['seconds' => $totals['effort'], 'live' => $totals['live']['effort']])
                    @else
                        <span class="cleaning-summary__empty">—</span>
                    @endif
                </dd>
                <dd class="cleaning-summary__hint">Harcanan toplam emek: her çalışma diliminde süre × çalışan kişi sayısı.</dd>
            </div>
        </dl>

        @if ($cleaning->status === CleaningStatus::Cancelled)
            <div class="cleaning-summary__closure cleaning-summary__closure--cancelled">
                <h3 class="cleaning-summary__subtitle">İptal</h3>
                <dl class="cleaning-summary__list">
                    <div class="cleaning-summary__item">
                        <dt>İptal eden</dt>
                        <dd>{{ $users->get($cleaning->cancelled_by)?->name ?? '—' }}</dd>
                    </div>
                    <div class="cleaning-summary__item">
                        <dt>Zaman</dt>
                        <dd><x-datetime :value="$cleaning->closed_at" format="d.m.Y H:i:s" /></dd>
                    </div>
                    <div class="cleaning-summary__item">
                        <dt>Gerekçe</dt>
                        <dd>{{ $cleaning->cancel_reason?->label() ?? '—' }}</dd>
                    </div>
                    <div class="cleaning-summary__item cleaning-summary__item--wide">
                        <dt>Açıklama</dt>
                        <dd class="cleaning-summary__notes">{{ $cleaning->cancel_note }}</dd>
                    </div>
                </dl>
            </div>
        @elseif ($cleaning->status === CleaningStatus::Expired)
            <div class="cleaning-summary__closure cleaning-summary__closure--expired">
                <h3 class="cleaning-summary__subtitle">Süresi doldu</h3>
                <p class="mb-0">
                    <x-datetime :value="$cleaning->closed_at" format="d.m.Y H:i:s" /> itibarıyla sistem tarafından kapatıldı:
                    @if (isset($expiredEvent?->payload['stale_after_minutes']))
                        açıldıktan sonra {{ $expiredEvent->payload['stale_after_minutes'] }} dakika içinde ilk adım başlatılmadı.
                    @else
                        ilk adım süresi içinde başlatılmadı.
                    @endif
                </p>
            </div>
        @endif
    </div>
</section>
