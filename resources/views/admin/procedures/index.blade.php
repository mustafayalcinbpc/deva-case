@extends('layouts.app')

@section('title', 'Prosedürler')
@section('page-title', 'Prosedürler')
@section('page-subtitle', 'Makinelerin temizlik prosedürleri; fazlar ve adımlar versiyonlarda tanımlanır (R-01–R-06, K-15).')

@section('page-actions')
    <a href="{{ route('admin.procedures.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni prosedür
    </a>
@endsection

{{--
    Prosedür listesi: geçerli versiyon (yayın tarihi gelmiş en son versiyon), bekleyen ileri
    tarihli yayın, açık taslak, prosedürü kullanan makineler ve bütün versiyonlarla açılmış
    kayıt sayısı. İlişkiler sayfa başına sabit sayıda sorguyla yüklenir.
--}}
@section('content')
    <section class="card procedure-list" aria-labelledby="procedure-list-title">
        <div class="card-header">
            <h2 class="card-title" id="procedure-list-title">Tanımlı prosedürler</h2>
        </div>

        @if ($procedures->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Henüz prosedür yok. Yeni prosedür boş bir v1 taslağıyla oluşturulur.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 procedure-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Kod</th>
                                <th scope="col">Ad</th>
                                <th scope="col">Geçerli versiyon</th>
                                <th scope="col">Taslak</th>
                                <th scope="col">Makineler</th>
                                <th scope="col" class="text-end">Kayıt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($procedures as $procedure)
                                <tr class="procedure-list__row">
                                    <td class="text-nowrap">
                                        <a href="{{ route('admin.procedures.show', $procedure) }}" class="procedure-code">{{ $procedure->code }}</a>
                                    </td>
                                    <td>{{ $procedure->name }}</td>
                                    <td class="procedure-list__current">
                                        @if ($current = $procedure->currentPublishedVersion)
                                            <a href="{{ route('admin.procedures.versions.show', [$procedure, $current]) }}" class="procedure-version">v{{ $current->version }}</a>
                                            <small class="procedure-list__meta"><x-datetime :value="$current->published_at" /></small>
                                        @else
                                            <span class="procedure-list__meta">Yayında versiyon yok</span>
                                        @endif
                                        @if ($upcoming = $procedure->upcomingVersion)
                                            <small class="procedure-list__meta d-block">
                                                v{{ $upcoming->version }}: <x-datetime :value="$upcoming->published_at" /> itibarıyla
                                            </small>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($draft = $procedure->draftVersion)
                                            <a href="{{ route('admin.procedures.versions.show', [$procedure, $draft]) }}">v{{ $draft->version }} taslağı</a>
                                        @else
                                            <span class="procedure-list__meta">Yok</span>
                                        @endif
                                    </td>
                                    <td class="procedure-list__machines">
                                        @forelse ($procedure->machines as $machine)
                                            <span class="procedure-machine text-nowrap" title="{{ $machine->name }}{{ $machine->is_active ? '' : ' (kullanımdan kaldırıldı)' }}">{{ $machine->line->code }} / {{ $machine->code }}</span>@if (! $loop->last), @endif
                                        @empty
                                            <span class="procedure-list__meta">Makine yok</span>
                                        @endforelse
                                    </td>
                                    <td class="text-end">{{ $procedure->cleanings_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($procedures->hasPages())
                <div class="card-footer procedure-list__pagination">
                    {{ $procedures->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
