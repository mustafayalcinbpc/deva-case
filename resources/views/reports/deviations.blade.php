@extends('layouts.app')

@section('title', 'Sapmalar')
@section('page-title', 'Sapmalar')
@section('page-subtitle', 'Minimum süresinin altında kapanan fazlar ve anormal uzun çalışma dilimleri.')

{{--
    Sapmalar: K-01 (minimum süre altında, gerekçeyle kapanan faz) ve K-03 (eşiği aşan çalışma
    dilimi; açık dilim şu ana kadar geçen süreyle). Kayıtlar değiştirilmez, yalnızca listelenir.
--}}
@section('content')
    @include('reports.partials.filters', [
        'action' => route('reports.deviations'),
        'dateHint' => 'Tarih aralığı fazlarda kapanış, dilimlerde başlangıç zamanına uygulanır.',
    ])

    <section id="below-minimum" class="card mb-4" aria-labelledby="below-minimum-title">
        <div class="card-header">
            <h2 class="card-title" id="below-minimum-title">Minimum süresinin altında kapanan fazlar</h2>
            <div class="card-tools">{{ $phases->total() }} faz</div>
        </div>

        @if ($phases->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Seçilen aralıkta minimum süresinin altında kapanan faz yok.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Kayıt no</th>
                                <th scope="col">Konum</th>
                                <th scope="col">Faz</th>
                                <th scope="col" class="text-end">Ölçülen</th>
                                <th scope="col" class="text-end">Minimum</th>
                                <th scope="col" class="text-end">Eksik</th>
                                <th scope="col">Gerekçe</th>
                                <th scope="col">Kapatan</th>
                                <th scope="col">Kapanış</th>
                                <th scope="col"><span class="visually-hidden">Denetim raporu</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($phases as $phase)
                                @php
                                    $cleaning = $phase->cleaning;
                                    $minimum = (int) $phase->procedurePhase->min_duration_seconds;
                                    $measured = (int) $phase->measured_seconds;
                                @endphp
                                <tr>
                                    <td class="text-nowrap"><a href="{{ route('cleanings.show', $cleaning) }}" class="record-no">{{ $cleaning->record_no }}</a></td>
                                    <td class="text-nowrap" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }} / {{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</td>
                                    <td>
                                        {{ $phase->sequence }}. {{ $phase->procedurePhase->name }}
                                        <small>({{ $phase->procedurePhase->include_gaps ? 'brüt' : 'net' }})</small>
                                    </td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$measured" /></td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$minimum" /></td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="max(0, $minimum - $measured)" /></td>
                                    <td>{{ $phase->deviation_reason }}</td>
                                    <td>{{ $phase->closedBy?->name ?? '—' }}</td>
                                    <td class="text-nowrap"><x-datetime :value="$phase->completed_at" format="d.m.Y H:i:s" /></td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('reports.audit', $cleaning) }}">
                                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Denetim raporu
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($phases->hasPages())
                <div class="card-footer">
                    {{ $phases->links() }}
                </div>
            @endif
        @endif
    </section>

    <section id="anomalous-slices" class="card mb-4" aria-labelledby="anomalous-slices-title">
        <div class="card-header">
            <h2 class="card-title" id="anomalous-slices-title">{{ $thresholdHours }} saatten uzun çalışma dilimleri</h2>
            <div class="card-tools">{{ $slices->total() }} dilim</div>
        </div>

        <div class="card-body border-bottom">
            <p class="mb-0">
                Adımın kesintisiz çalışılan bölümü bir dilimdir. Bu kadar uzun bir dilim, adımın duraklatılmasının
                unutulduğunu gösterebilir. Kayıt değiştirilmez; yalnızca işaretlenir. Hâlâ açık olan dilim şu ana
                kadar geçen süreyle gösterilir.
            </p>
        </div>

        @if ($slices->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Seçilen aralıkta eşiği aşan çalışma dilimi yok.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Kayıt no</th>
                                <th scope="col">Konum</th>
                                <th scope="col">Adım</th>
                                <th scope="col">Başlangıç</th>
                                <th scope="col">Bitiş</th>
                                <th scope="col" class="text-end">Süre</th>
                                <th scope="col">Çalışanlar</th>
                                <th scope="col"><span class="visually-hidden">Denetim raporu</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($slices as $slice)
                                @php($cleaning = $slice->step->cleaning)
                                <tr>
                                    <td class="text-nowrap"><a href="{{ route('cleanings.show', $cleaning) }}" class="record-no">{{ $cleaning->record_no }}</a></td>
                                    <td class="text-nowrap" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }} / {{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</td>
                                    <td>{{ $slice->step->sequence }}. {{ $slice->step->procedureStep->title }}</td>
                                    <td class="text-nowrap"><x-datetime :value="$slice->started_at" format="d.m.Y H:i:s" /></td>
                                    <td class="text-nowrap">
                                        @if ($slice->ended_at)
                                            <x-datetime :value="$slice->ended_at" format="d.m.Y H:i:s" />
                                        @else
                                            <strong>Devam ediyor</strong>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$slice->duration_seconds" /></td>
                                    <td>{{ $slice->workers->map(fn ($worker) => $worker->user->name)->implode(', ') }}</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('reports.audit', $cleaning) }}">
                                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Denetim raporu
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($slices->hasPages())
                <div class="card-footer">
                    {{ $slices->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
