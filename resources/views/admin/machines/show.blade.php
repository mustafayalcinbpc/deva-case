@extends('layouts.app')

@section('title', "{$machine->code} makinesi")
@section('page-title', "{$machine->code} — {$machine->name}")
@section('page-subtitle', "{$machine->line->facility->name} / {$machine->line->name}")

@section('page-actions')
    <a href="{{ route('cleanings.index', ['machine_id' => $machine->id]) }}" class="btn btn-outline-secondary">
        <i class="bi bi-list-check" aria-hidden="true"></i> Kayıtları
    </a>
    <a href="{{ route('admin.machines.edit', $machine) }}" class="btn btn-primary">
        <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
    </a>
@endsection

{{--
    Makine ayrıntısı (R-12, K-16, K-18): prosedür ve yeni kayıtlara uygulanacak versiyon, açık
    kayıtlar ve kullanım durumu. Kullanımdan kaldırma/yeniden kullanıma alma kararını
    MachineRetirement verir; kural ihlali `machine` hatasıyla bu sayfaya döner.
--}}
@section('content')
    @php
        $canOpenRecords = $machine->is_active && $currentVersion !== null;
    @endphp

    <section class="card machine-detail" aria-labelledby="machine-detail-title">
        <div class="card-header">
            <h2 class="card-title" id="machine-detail-title">Makine bilgileri</h2>
        </div>

        <div class="card-body">
            <dl class="row mb-0 definition-list">
                <dt class="col-sm-4 col-lg-3">Kod</dt>
                <dd class="col-sm-8 col-lg-9 record-no">{{ $machine->code }}</dd>

                <dt class="col-sm-4 col-lg-3">Ad</dt>
                <dd class="col-sm-8 col-lg-9">{{ $machine->name }}</dd>

                <dt class="col-sm-4 col-lg-3">Tesis</dt>
                <dd class="col-sm-8 col-lg-9">{{ $machine->line->facility->code }} — {{ $machine->line->facility->name }}</dd>

                <dt class="col-sm-4 col-lg-3">Hat</dt>
                <dd class="col-sm-8 col-lg-9">{{ $machine->line->code }} — {{ $machine->line->name }}</dd>

                <dt class="col-sm-4 col-lg-3">Durum</dt>
                <dd class="col-sm-8 col-lg-9">@include('admin.machines.partials.state')</dd>

                <dt class="col-sm-4 col-lg-3">Prosedür</dt>
                <dd class="col-sm-8 col-lg-9">
                    @include('admin.machines.partials.procedure', [
                        'procedure' => $machine->procedure,
                        'version' => $currentVersion?->version,
                    ])
                    @if ($currentVersion)
                        <div class="form-text">
                            Yeni kayıtlar v{{ $currentVersion->version }} versiyonuna bağlanır.
                            Yayın: <x-datetime :value="$currentVersion->published_at" format="list" />
                        </div>
                    @endif
                </dd>

                <dt class="col-sm-4 col-lg-3">Yeni kayıt</dt>
                <dd class="col-sm-8 col-lg-9">
                    @if ($canOpenRecords)
                        Açılabilir.
                    @elseif (! $machine->is_active)
                        Açılamaz: makine kullanımdan kaldırılmış.
                    @else
                        Açılamaz: makinenin yayımlanmış versiyonu olan bir prosedürü yok (K-18).
                    @endif
                </dd>

                <dt class="col-sm-4 col-lg-3">Kayıtlar</dt>
                <dd class="col-sm-8 col-lg-9">
                    <a href="{{ route('cleanings.index', ['machine_id' => $machine->id]) }}">{{ $cleaningCount }} kayıt</a>
                </dd>
            </dl>
        </div>
    </section>

    <section id="open-cleanings" class="card machine-open-cleanings" aria-labelledby="open-cleanings-title">
        <div class="card-header">
            <h2 class="card-title" id="open-cleanings-title">Açık kayıtlar</h2>
        </div>

        @if ($openCleanings->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Bu makinede başlamamış ya da devam eden kayıt yok.</p>
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
                                <th scope="col">Durum</th>
                                <th scope="col">Açılış</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($openCleanings as $cleaning)
                                <tr>
                                    <td><a href="{{ route('cleanings.show', $cleaning) }}" class="record-no text-nowrap">{{ $cleaning->record_no }}</a></td>
                                    <td>{{ $cleaning->type->label() }}</td>
                                    <td>{{ $cleaning->owner->name }}</td>
                                    <td><x-status-badge :status="$cleaning->status" /></td>
                                    <td class="text-nowrap"><x-datetime :value="$cleaning->created_at" format="list" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    @include('admin.machines.partials.retirement')

    <x-definition-history :definition="$machine" class="machine-history" />
@endsection
