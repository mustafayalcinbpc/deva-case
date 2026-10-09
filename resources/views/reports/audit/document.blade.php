{{--
    Denetim raporunun gövdesi; yazdırılabilir sayfa (show) ve PDF (pdf) aynı gövdeyi kullanır.
    dompdf flex/grid desteklemediği için yerleşim tablolarla yapılır. R-45'teki soruların
    cevapları (1–13) ve R-46–R-49 için olay zincirinin tamamı, hash'leri ve doğrulama sonucu.
--}}
@use('App\Enums\CleaningStatus')

@php
    $timezone = config('app.display_timezone');
    $at = fn ($value) => $value ? $value->copy()->setTimezone($timezone)->format('d.m.Y H:i:s') : '—';
    $version = $cleaning->procedureVersion;
    $closedLabel = match ($cleaning->status) {
        CleaningStatus::Completed => 'Tamamlanma',
        CleaningStatus::Cancelled => 'İptal zamanı',
        CleaningStatus::Expired => 'Süre dolumu',
        default => 'Kapanış',
    };
    $facts = [
        ['Kayıt no', $cleaning->record_no],
        ['Saha defteri referansı', $cleaning->field_ref ?? ($cleaning->type->hasFieldReference() ? 'Henüz üretilmedi (ilk adımda üretilir)' : 'Yok (plansız müdahale)')],
        ['Tür', $cleaning->type->label()],
        ['Durum', $cleaning->status->label()],
        ['Tesis', "{$cleaning->facility->code} — {$cleaning->facility->name}"],
        ['Hat', "{$cleaning->line->code} — {$cleaning->line->name}"],
        ['Makine', "{$cleaning->machine->code} — {$cleaning->machine->name}"],
        ['Sorumlu (kaydı açan)', $cleaning->owner->name],
        ['İlk adımı başlatan', $startedBy?->name ?? '—'],
        ['Prosedür', "{$version->procedure->code} — {$version->procedure->name}"],
        ['Prosedür versiyonu', "{$version->version}".($version->published_at ? ' (yayın: '.$at($version->published_at).')' : '')],
        ['Malzeme kullanımı', $version->material_required ? 'Zorunlu' : 'Zorunlu değil'],
        ['Üretim iş emri', $cleaning->workOrder ? trim("{$cleaning->workOrder->code} ".($cleaning->workOrder->description ? "— {$cleaning->workOrder->description}" : '')) : '—'],
        ['Açıklama', filled($cleaning->notes) ? $cleaning->notes : '—'],
        ['Açılış', $at($cleaning->created_at)],
        ['Başlangıç (ilk adım)', $at($cleaning->started_at)],
        [$closedLabel, $at($cleaning->closed_at)],
    ];

    if ($cleaning->status === CleaningStatus::Cancelled) {
        $facts[] = ['İptal eden', $users->get($cleaning->cancelled_by)?->name ?? '—'];
        $facts[] = ['İptal gerekçesi', trim(($cleaning->cancel_reason?->label() ?? '').': '.$cleaning->cancel_note, ': ')];
    }

    $lastEvent = $events === [] ? null : $events[array_key_last($events)];
@endphp

<article class="audit">
    <header class="audit-header">
        <p class="audit-kicker">{{ config('app.name') }}</p>
        <h1>Temizlik Denetim Raporu</h1>
        <p class="audit-record">{{ $cleaning->record_no }}</p>
        <p class="audit-meta">
            Oluşturma: {{ $at($generatedAt) }}@if ($generatedBy) · Oluşturan: {{ $generatedBy->name }}@endif
            · Saatler {{ $timezone }} saatine göredir.
        </p>
    </header>

    <section id="audit-integrity" @class(['audit-integrity', 'audit-integrity--ok' => $chainIntact, 'audit-integrity--broken' => ! $chainIntact])>
        @if ($chainIntact)
            <p><strong>Olay zinciri doğrulandı.</strong> {{ count($events) }} olay, oluştukları andan beri değiştirilmemiş, silinmemiş ve araya olay eklenmemiş.</p>
        @else
            <p><strong>Olay zinciri doğrulanamadı.</strong> Bir olay sonradan değiştirilmiş, silinmiş ya da araya eklenmiş olabilir. Bu kayıt denetimde kanıt olarak kullanılmadan önce incelenmelidir.</p>
        @endif
        @if ($lastEvent)
            <p class="audit-hash">Zincirin son hash'i: {{ $lastEvent['event']->hash }}</p>
        @endif
    </section>

    <section id="audit-record">
        <h2>1. Kayıt bilgileri</h2>
        <table class="audit-facts">
            <tbody>
                @foreach ($facts as [$label, $value])
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section id="audit-totals">
        <h2>2. Süre ve efor</h2>
        <table class="audit-facts">
            <tbody>
                <tr>
                    <th scope="row">Net çalışma süresi</th>
                    <td>@if ($totals['started'])<x-duration :seconds="$totals['net']" />@else — @endif</td>
                    <td class="audit-note">Adımlarda gerçekten çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz.</td>
                </tr>
                <tr>
                    <th scope="row">Brüt süre</th>
                    <td>@if ($totals['started'])<x-duration :seconds="$totals['gross']" />@else — @endif</td>
                    <td class="audit-note">İlk adımın başlangıcından son çalışmanın bitişine kadar; aradaki boşluklar dahil.</td>
                </tr>
                <tr>
                    <th scope="row">İnsan eforu</th>
                    <td>@if ($totals['started'])<x-duration :seconds="$totals['effort']" />@else — @endif</td>
                    <td class="audit-note">Her çalışma diliminde süre × çalışan kişi sayısı.</td>
                </tr>
                <tr>
                    <th scope="row">Çalışma dilimi</th>
                    <td>{{ $totals['sliceCount'] }}</td>
                    <td class="audit-note">Adımın kesintisiz çalışılan her bölümü; duraklatma ya da görevli değişikliği yeni dilim açar.</td>
                </tr>
            </tbody>
        </table>
        @if ($totals['running'])
            <p class="audit-note">Bir adım hâlâ çalışıyor; açık çalışma dilimi rapor anına kadar geçen süreyle sayıldı.</p>
        @endif
    </section>

    <section id="audit-steps">
        <h2>3. Fazlar ve adımlar</h2>
        <table class="audit-table audit-steps">
            <thead>
                <tr>
                    <th scope="col">Adım</th>
                    <th scope="col">Durum</th>
                    <th scope="col">Başlangıç</th>
                    <th scope="col">Bitiş</th>
                    <th scope="col" class="audit-num">Net süre</th>
                    <th scope="col" class="audit-num">İnsan eforu</th>
                    <th scope="col">Çalışanlar</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($phases as $phaseRow)
                    @php($phase = $phaseRow['phase'])
                    <tr class="audit-phase">
                        <td colspan="7">
                            <strong>{{ $phase->sequence }}. faz — {{ $phase->procedurePhase->name }}</strong>
                            · {{ $phase->status->label() }}
                            · Minimum <x-duration :seconds="$phaseRow['minimum']" />
                            · Ölçülen ({{ $phaseRow['includeGaps'] ? 'brüt' : 'net' }}):
                            @if ($phaseRow['measured'] !== null)
                                <x-duration :seconds="$phaseRow['measured']" />
                            @else
                                —
                            @endif
                            @if ($phase->below_minimum)
                                <span class="audit-deviation">· Minimum sürenin altında. Gerekçe: {{ $phase->deviation_reason }}</span>
                            @endif
                        </td>
                    </tr>
                    @foreach ($phaseRow['steps'] as $stepRow)
                        @php($step = $stepRow['step'])
                        <tr>
                            <td>{{ $step->sequence }}. {{ $step->procedureStep->title }}</td>
                            <td>{{ $step->status->label() }}</td>
                            <td class="audit-time">{{ $at($step->started_at) }}</td>
                            <td class="audit-time">{{ $at($step->completed_at) }}</td>
                            <td class="audit-num">@if ($stepRow['slices'] !== [])<x-duration :seconds="$stepRow['net']" />@else — @endif</td>
                            <td class="audit-num">@if ($stepRow['slices'] !== [])<x-duration :seconds="$stepRow['effort']" />@else — @endif</td>
                            <td>{{ $stepRow['workers'] === [] ? '—' : implode(', ', $stepRow['workers']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </section>

    <section id="audit-slices">
        <h2>4. Çalışma dilimleri</h2>
        @if ($totals['sliceCount'] === 0)
            <p>Çalışma dilimi yok; hiçbir adım başlatılmadı.</p>
        @else
            <table class="audit-table audit-slices">
                <thead>
                    <tr>
                        <th scope="col">Adım</th>
                        <th scope="col">Başlangıç</th>
                        <th scope="col">Bitiş</th>
                        <th scope="col" class="audit-num">Süre</th>
                        <th scope="col" class="audit-num">Kişi</th>
                        <th scope="col">Çalışanlar</th>
                        <th scope="col">Bitiş nedeni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($phases as $phaseRow)
                        @foreach ($phaseRow['steps'] as $stepRow)
                            @foreach ($stepRow['slices'] as $sliceRow)
                                <tr>
                                    <td>{{ $stepRow['step']->sequence }}. adım</td>
                                    <td class="audit-time">{{ $at($sliceRow['slice']->started_at) }}</td>
                                    <td class="audit-time">{{ $sliceRow['slice']->ended_at ? $at($sliceRow['slice']->ended_at) : 'Devam ediyor' }}</td>
                                    <td class="audit-num"><x-duration :seconds="$sliceRow['seconds']" /></td>
                                    <td class="audit-num">{{ count($sliceRow['workers']) }}</td>
                                    <td>{{ implode(', ', $sliceRow['workers']) }}</td>
                                    <td>{{ $sliceRow['endReason'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section id="audit-materials">
        <h2>5. Malzemeler</h2>
        @if ($materials === [])
            <p>Malzeme girilmedi.</p>
        @else
            <table class="audit-table audit-materials">
                <thead>
                    <tr>
                        <th scope="col">Malzeme</th>
                        <th scope="col">Lot</th>
                        <th scope="col">Son kullanma</th>
                        <th scope="col">Ekleyen</th>
                        <th scope="col">Durum</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($materials as $materialRow)
                        @php($item = $materialRow['item'])
                        <tr>
                            <td>{{ $item->material->code }} — {{ $item->material->name }}</td>
                            <td>{{ $item->lot_no }}</td>
                            <td class="audit-time">{{ $item->expiry_date->format('d.m.Y') }}</td>
                            <td>{{ $materialRow['addedBy'] }}, {{ $at($item->created_at) }}</td>
                            <td>
                                @if ($item->voided_at === null)
                                    Geçerli
                                @else
                                    <span class="audit-deviation">Geçersiz kılındı</span>: {{ $item->void_reason }}
                                    ({{ $materialRow['voidedBy'] }}, {{ $at($item->voided_at) }})
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section id="audit-events">
        <h2>6. Olay kaydı</h2>
        <p class="audit-note">
            Her olay, aynı kaydın bir önceki olayının hash'ini içerir (SHA-256). Bir olayın değiştirilmesi,
            silinmesi ya da araya olay eklenmesi zinciri bozar; zincir rapor oluşturulurken baştan hesaplandı.
        </p>
        <table class="audit-table audit-events">
            <thead>
                <tr>
                    <th scope="col" class="audit-num">#</th>
                    <th scope="col">Zaman</th>
                    <th scope="col">Yapan</th>
                    <th scope="col">Olay</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $entry)
                    <tr>
                        <td class="audit-num">{{ $entry['event']->sequence }}</td>
                        <td class="audit-time">{{ $at($entry['event']->occurred_at) }}</td>
                        <td>{{ $entry['actor'] }}</td>
                        <td>
                            <strong>{{ $entry['title'] }}</strong>
                            @foreach ($entry['details'] as $detail)
                                <br>{{ $detail['label'] }}:
                                @isset($detail['seconds'])
                                    <x-duration :seconds="$detail['seconds']" />
                                @else
                                    {{ $detail['value'] }}
                                @endisset
                            @endforeach
                            <span class="audit-hash">
                                <br>Önceki hash: {{ $entry['event']->previous_hash ?? '— (ilk olay)' }}
                                <br>Hash: {{ $entry['event']->hash }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</article>
