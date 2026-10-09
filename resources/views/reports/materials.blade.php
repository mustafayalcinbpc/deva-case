@extends('layouts.app')

@section('title', 'Malzeme İzlenebilirliği')
@section('page-title', 'Malzeme İzlenebilirliği')
@section('page-subtitle', 'Bir malzemenin ya da lotun hangi temizliklerde kullanıldığı.')

{{--
    R-10: "Bu makinenin temizliği yapılırken hangi malzeme (ve hangi lot) kullanılmış?" sorusunun
    tersi de cevaplanır: bir malzeme ya da lot hangi kayıtlarda kullanılmış. Geçersiz kılınan
    girişler de listelenir; ilk kayıt silinmez (K-12).
--}}
@section('content')
    <section class="card mb-4 report-filters" aria-labelledby="material-search-title">
        <div class="card-header">
            <h2 class="card-title" id="material-search-title">Ara</h2>
        </div>

        <div class="card-body">
            <form method="GET" action="{{ route('reports.materials') }}" class="row g-3 align-items-end" role="search" aria-label="Malzeme ya da lot ara">
                <div class="col-md-6 col-xl-4">
                    <label for="search-material" class="form-label">Malzeme</label>
                    <select id="search-material" name="material_id" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($catalog as $material)
                            <option value="{{ $material->id }}" @selected($materialId === $material->id)>{{ $material->code }} — {{ $material->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-xl-4">
                    <label for="search-lot" class="form-label">Lot numarası</label>
                    <input type="search" id="search-lot" name="lot" value="{{ $lot }}" maxlength="100" autocomplete="off" class="form-control">
                </div>

                <div class="col-12 col-xl-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search" aria-hidden="true"></i> Ara
                    </button>
                    @if ($searched)
                        <a href="{{ route('reports.materials') }}" class="btn btn-outline-secondary">Aramayı temizle</a>
                    @endif
                </div>
            </form>

            <p class="form-text mb-0 mt-3">Malzeme, lot numarası ya da ikisi birlikte aranabilir. Lot numarasının bir kısmını yazmak yeterlidir.</p>
        </div>
    </section>

    @if ($searched)
        <section id="material-results" class="card mb-4" aria-labelledby="material-results-title">
            <div class="card-header">
                <h2 class="card-title" id="material-results-title">Kullanıldığı kayıtlar</h2>
                <div class="card-tools">{{ $results->total() }} giriş</div>
            </div>

            @if ($results->isEmpty())
                <div class="card-body">
                    <p class="empty-state mb-0">Aramaya uyan malzeme girişi yok.</p>
                </div>
            @else
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Kayıt no</th>
                                    <th scope="col">Konum</th>
                                    <th scope="col">Durum</th>
                                    <th scope="col">Açılış</th>
                                    <th scope="col">Kapanış</th>
                                    <th scope="col">Malzeme</th>
                                    <th scope="col">Lot</th>
                                    <th scope="col">Son kullanma</th>
                                    <th scope="col">Ekleyen</th>
                                    <th scope="col">Geçerlilik</th>
                                    <th scope="col"><span class="visually-hidden">Denetim raporu</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($results as $item)
                                    @php($cleaning = $item->cleaning)
                                    <tr @class(['material-trace__row', 'material-trace__row--voided' => $item->voided_at !== null])>
                                        <td class="text-nowrap"><a href="{{ route('cleanings.show', $cleaning) }}" class="record-no">{{ $cleaning->record_no }}</a></td>
                                        <td class="text-nowrap" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }} / {{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</td>
                                        <td><x-status-badge :status="$cleaning->status" /></td>
                                        <td class="text-nowrap"><x-datetime :value="$cleaning->created_at" /></td>
                                        <td class="text-nowrap"><x-datetime :value="$cleaning->closed_at" /></td>
                                        <td>{{ $item->material->code }} — {{ $item->material->name }}</td>
                                        <td class="text-nowrap">{{ $item->lot_no }}</td>
                                        <td class="text-nowrap"><time datetime="{{ $item->expiry_date->toDateString() }}">{{ $item->expiry_date->format('d.m.Y') }}</time></td>
                                        <td>
                                            {{ $item->addedBy?->name ?? '—' }}<br>
                                            <small><x-datetime :value="$item->created_at" format="d.m.Y H:i:s" /></small>
                                        </td>
                                        <td>
                                            @if ($item->voided_at === null)
                                                Geçerli
                                            @else
                                                <strong>Geçersiz kılındı</strong>: {{ $item->void_reason }}<br>
                                                <small>{{ $item->voidedBy?->name ?? '—' }}, <x-datetime :value="$item->voided_at" format="d.m.Y H:i:s" /></small>
                                            @endif
                                        </td>
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

                @if ($results->hasPages())
                    <div class="card-footer">
                        {{ $results->links() }}
                    </div>
                @endif
            @endif
        </section>
    @endif
@endsection
