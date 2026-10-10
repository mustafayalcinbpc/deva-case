@extends('layouts.app')

@section('title', 'Süre ve Efor')
@section('page-title', 'Süre ve Efor')
@section('page-subtitle', 'Tamamlanan temizlikler makine bazında: net süre, brüt süre ve insan eforu ayrı ayrı.')

@section('page-actions')
    <form method="POST" action="{{ route('reports.durations.csv') }}" class="report-export">
        @csrf
        @foreach ($filters->toArray() as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button type="submit" class="btn btn-outline-secondary">
            <i class="bi bi-filetype-csv" aria-hidden="true"></i> CSV olarak dışa aktar
        </button>
    </form>
@endsection

{{--
    Süre ve efor (R-26, R-28, K-02, K-04): "bu makinenin temizliği gerçekte ne kadar sürüyor,
    hangi faz uzun?" Yalnızca tamamlanmış kayıtlar sayılır. Makine seçilince fazlar ve o
    makinenin kayıtları da gösterilir.
--}}
@section('content')
    @include('reports.partials.filters', [
        'action' => route('reports.durations'),
        'dateHint' => 'Tarih aralığı kaydın tamamlanma zamanına uygulanır.',
    ])

    <section id="duration-machines" class="card mb-4" aria-labelledby="duration-machines-title">
        <div class="card-header">
            <h2 class="card-title" id="duration-machines-title">Makineler</h2>
            <div class="card-tools">{{ $filters->periodLabel() }}</div>
        </div>

        <div class="card-body border-bottom">
            <dl class="row mb-0">
                <dt class="col-sm-3 col-xl-2">Net süre</dt>
                <dd class="col-sm-9 col-xl-10">Adımlarda gerçekten çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz.</dd>
                <dt class="col-sm-3 col-xl-2">Brüt süre</dt>
                <dd class="col-sm-9 col-xl-10">İlk adımın başlangıcından son adımın bitişine kadar geçen süre; aradaki boşluklar dahil.</dd>
                <dt class="col-sm-3 col-xl-2">İnsan eforu</dt>
                <dd class="col-sm-9 col-xl-10 mb-0">Her çalışma diliminde süre × çalışan kişi sayısı.</dd>
            </dl>
        </div>

        @if ($machines->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Seçilen aralıkta filtreye uyan tamamlanmış temizlik yok.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Makine</th>
                                <th scope="col">Konum</th>
                                <th scope="col" class="text-end">Tamamlanan</th>
                                <th scope="col" class="text-end">Ort. net süre</th>
                                <th scope="col" class="text-end">En kısa net</th>
                                <th scope="col" class="text-end">En uzun net</th>
                                <th scope="col" class="text-end">Ort. brüt süre</th>
                                <th scope="col" class="text-end">Ort. insan eforu</th>
                                <th scope="col" class="text-end" title="Kayıt başına ortalama çalışma dilimi sayısı">Ort. dilim</th>
                                <th scope="col"><span class="visually-hidden">Fazlar</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($machines as $row)
                                <tr data-machine-id="{{ $row['machine_id'] }}">
                                    <td class="text-nowrap">
                                        {{ $row['machine_code'] }} — {{ $row['machine_name'] }}
                                        @unless ($row['machine_active'])
                                            <small>(kullanımdan kaldırıldı)</small>
                                        @endunless
                                    </td>
                                    <td class="text-nowrap" title="{{ $row['facility_name'] }} / {{ $row['line_name'] }}">{{ $row['facility_code'] }} / {{ $row['line_code'] }}</td>
                                    <td class="text-end">{{ $row['completed_count'] }}</td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$row['avg_net_seconds']" /></td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$row['min_net_seconds']" /></td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$row['max_net_seconds']" /></td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$row['avg_gross_seconds']" /></td>
                                    <td class="text-end text-nowrap"><x-duration :seconds="$row['avg_effort_seconds']" /></td>
                                    <td class="text-end">{{ number_format($row['avg_slice_count'], 1, ',', '.') }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($machine?->id === $row['machine_id'])
                                            <span aria-current="true">Seçili</span>
                                        @else
                                            <a href="{{ route('reports.durations', ['machine_id' => $row['machine_id']] + $filters->toArray()) }}">
                                                Fazlar ve kayıtlar <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    @if ($machine)
        <section id="duration-phases" class="card mb-4" aria-labelledby="duration-phases-title">
            <div class="card-header">
                <h2 class="card-title" id="duration-phases-title">{{ $machine->code }} — {{ $machine->name }}: fazlar</h2>
                <div class="card-tools">
                    <a href="{{ route('reports.durations', $filters->withMachine(null)->toArray()) }}">Bütün makineler</a>
                </div>
            </div>

            @if ($phases->isEmpty())
                <div class="card-body">
                    <p class="empty-state mb-0">Bu makinede seçilen aralıkta tamamlanmış temizlik yok.</p>
                </div>
            @else
                <div class="card-body border-bottom">
                    <p class="mb-0">
                        Ölçülen süre, minimum süre kontrolünde kullanılan süredir: fazın ayarına göre net (adımlar
                        arası boşluk sayılmaz) ya da brüt (boşluklar dahil). Faz tanımları prosedür versiyonuna aittir.
                    </p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Prosedür</th>
                                    <th scope="col">Faz</th>
                                    <th scope="col">Süre türü</th>
                                    <th scope="col" class="text-end">Minimum</th>
                                    <th scope="col" class="text-end">Ort. ölçülen</th>
                                    <th scope="col" class="text-end">En kısa</th>
                                    <th scope="col" class="text-end">En uzun</th>
                                    <th scope="col" class="text-end">Ort. net süre</th>
                                    <th scope="col" class="text-end">Ort. insan eforu</th>
                                    <th scope="col" class="text-end">Minimum altında</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($phases as $phase)
                                    <tr>
                                        <td class="text-nowrap">{{ $phase['procedure_code'] }} v{{ $phase['version'] }}</td>
                                        <td>{{ $phase['sequence'] }}. {{ $phase['name'] }}</td>
                                        <td>{{ $phase['include_gaps'] ? 'Brüt' : 'Net' }}</td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$phase['minimum_seconds']" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$phase['avg_measured_seconds']" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$phase['min_measured_seconds']" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$phase['max_measured_seconds']" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$phase['avg_net_seconds']" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$phase['avg_effort_seconds']" /></td>
                                        <td class="text-end text-nowrap">{{ $phase['below_minimum_count'] }} / {{ $phase['phase_count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        <section id="duration-cleanings" class="card mb-4" aria-labelledby="duration-cleanings-title">
            <div class="card-header">
                <h2 class="card-title" id="duration-cleanings-title">{{ $machine->code }}: tamamlanan kayıtlar</h2>
            </div>

            @if ($cleanings->isEmpty())
                <div class="card-body">
                    <p class="empty-state mb-0">Kayıt yok.</p>
                </div>
            @else
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Kayıt no</th>
                                    <th scope="col">Tür</th>
                                    <th scope="col">Sorumlu</th>
                                    <th scope="col">Başlangıç</th>
                                    <th scope="col">Tamamlanma</th>
                                    <th scope="col" class="text-end">Net süre</th>
                                    <th scope="col" class="text-end">Brüt süre</th>
                                    <th scope="col" class="text-end">İnsan eforu</th>
                                    <th scope="col" class="text-end">Dilim</th>
                                    <th scope="col"><span class="visually-hidden">Denetim raporu</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cleanings as $cleaning)
                                    <tr>
                                        <td class="text-nowrap"><a href="{{ route('cleanings.show', $cleaning) }}" class="record-no">{{ $cleaning->record_no }}</a></td>
                                        <td>{{ $cleaning->type->label() }}</td>
                                        <td>{{ $cleaning->owner->name }}</td>
                                        <td class="text-nowrap"><x-datetime :value="$cleaning->started_at" format="list" /></td>
                                        <td class="text-nowrap"><x-datetime :value="$cleaning->closed_at" format="list" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$cleaning->net_seconds" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$cleaning->gross_seconds" /></td>
                                        <td class="text-end text-nowrap"><x-duration :seconds="$cleaning->effort_seconds" /></td>
                                        <td class="text-end">{{ $cleaning->slice_count }}</td>
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

                @if ($cleanings->hasPages())
                    <div class="card-footer">
                        {{ $cleanings->links() }}
                    </div>
                @endif
            @endif
        </section>
    @endif
@endsection
