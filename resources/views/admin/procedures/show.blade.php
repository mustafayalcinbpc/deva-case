@extends('layouts.app')

@section('title', "{$procedure->code} · Prosedür")
@section('page-title', "{$procedure->code} — {$procedure->name}")
@section('page-subtitle', 'Prosedürün versiyonları ve onu kullanan makineler. Yayımlanmış versiyon değiştirilemez; her değişiklik yeni bir versiyondur (K-15).')

@section('page-actions')
    <a href="{{ route('admin.procedures.edit', $procedure) }}" class="btn btn-outline-secondary">
        <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
    </a>
    @if ($draft)
        <a href="{{ route('admin.procedures.versions.show', [$procedure, $draft]) }}" class="btn btn-primary">
            <i class="bi bi-pencil-square" aria-hidden="true"></i> v{{ $draft->version }} taslağını düzenle
        </a>
    @else
        <form method="POST" action="{{ route('admin.procedures.versions.store', $procedure) }}" class="d-inline" data-module="submit-once">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-files" aria-hidden="true"></i> Yeni taslak
            </button>
        </form>
    @endif
@endsection

{{--
    Prosedür sayfası: versiyonlar, özet, kullanan makineler ve değişiklik geçmişi sekmelerde; her
    sekmede yalnızca kendi bölümü görünür. Bölüm id'leri (#versions, #summary, #machines, #history)
    adres çapası olarak kullanılır; çapa bir paneldeyse o sekme açılır (modules/section-tabs.js).
    Versiyon durumu yayın tarihinden hesaplanır: Taslak (yayımlanmamış), Yayımlanacak (ileri
    tarihli), Yayında (geçerli), Eski. Taslak silme ve yayımlama bu sayfaya döner; ilk sekme
    Versiyonlar olduğu için sonuç hemen görünür.
--}}
@php
    $tabs = [
        'versions' => 'Versiyonlar',
        'summary' => 'Özet',
        'machines' => 'Kullanan makineler',
        'history' => 'Değişiklik geçmişi',
    ];
@endphp

@section('content')
    @include('admin.procedures.partials.errors', ['keys' => ['version']])

    <div class="procedure-tabs" data-module="section-tabs">
        <x-section-tabs.nav :tabs="$tabs" label="Prosedür bölümleri" class="procedure-tabs__nav"
                            :counts="['versions' => $procedure->versions->count(), 'machines' => $procedure->machines->count()]" />

        <div class="tab-content section-tabs__panes">
            <x-section-tabs.pane section="versions" :active="true">
                <section id="versions" class="card procedure-versions" aria-labelledby="procedure-versions-title">
                    <div class="card-header">
                        <h2 class="card-title" id="procedure-versions-title">Versiyonlar</h2>
                    </div>

                    @if ($procedure->versions->isEmpty())
                        <div class="card-body">
                            <p class="empty-state mb-0">Versiyon yok. "Yeni taslak" ile boş bir taslak açın.</p>
                        </div>
                    @else
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 procedure-versions__table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Versiyon</th>
                                            <th scope="col">Durum</th>
                                            <th scope="col">Yayın tarihi</th>
                                            <th scope="col">Malzeme</th>
                                            <th scope="col" class="text-end">Faz</th>
                                            <th scope="col" class="text-end">Adım</th>
                                            <th scope="col" class="text-end">Kayıt</th>
                                            <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($procedure->versions as $version)
                                            <tr id="version-{{ $version->id }}" class="procedure-versions__row">
                                                <td class="text-nowrap">
                                                    <a href="{{ route('admin.procedures.versions.show', [$procedure, $version]) }}" class="procedure-version">v{{ $version->version }}</a>
                                                </td>
                                                <td><x-status-badge :status="$statuses[$version->id]" /></td>
                                                <td class="text-nowrap"><x-datetime :value="$version->published_at" format="list" /></td>
                                                <td>{{ $version->material_required ? 'Zorunlu' : 'Zorunlu değil' }}</td>
                                                <td class="text-end">{{ $version->phases_count }}</td>
                                                <td class="text-end">{{ $version->steps_count }}</td>
                                                <td class="text-end">{{ $version->cleanings_count }}</td>
                                                <td class="text-end text-nowrap">
                                                    @if ($version->isDraft())
                                                        <a href="{{ route('admin.procedures.versions.show', [$procedure, $version]) }}" class="btn btn-sm btn-primary">Düzenle</a>
                                                        <form method="POST"
                                                              action="{{ route('admin.procedures.versions.destroy', [$procedure, $version]) }}"
                                                              class="d-inline"
                                                              data-module="confirm-submit submit-once"
                                                              data-confirm="v{{ $version->version }} taslağı fazları ve adımlarıyla birlikte silinsin mi?">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Sil</button>
                                                        </form>
                                                    @else
                                                        <a href="{{ route('admin.procedures.versions.show', [$procedure, $version]) }}" class="btn btn-sm btn-outline-secondary">Görüntüle</a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="card-footer procedure-versions__help">
                        <small>
                            Yeni taslak en son versiyonun fazlarını, adımlarını ve medyasını kopyalar. Yeni kayıtlar,
                            yayın tarihi gelmiş en son versiyonla açılır; açık kayıtlar açıldıkları versiyonla devam eder (K-15).
                        </small>
                    </div>
                </section>
            </x-section-tabs.pane>

            <x-section-tabs.pane section="summary">
                <section id="summary" class="card procedure-summary" aria-labelledby="procedure-summary-title">
                    <div class="card-header">
                        <h2 class="card-title" id="procedure-summary-title">Özet</h2>
                    </div>
                    <div class="card-body">
                        <dl class="procedure-facts mb-0">
                            <dt>Kod</dt>
                            <dd>{{ $procedure->code }}</dd>
                            <dt>Ad</dt>
                            <dd>{{ $procedure->name }}</dd>
                            <dt>Geçerli versiyon</dt>
                            <dd>
                                @if ($current)
                                    v{{ $current->version }} · <x-datetime :value="$current->published_at" format="list" />
                                @else
                                    Yok. Yayımlanmış versiyon olmadan bu prosedürü kullanan makinede kayıt açılamaz (K-18).
                                @endif
                            </dd>
                            <dt>Toplam kayıt</dt>
                            <dd>{{ $cleaningsCount }}</dd>
                        </dl>
                    </div>
                </section>
            </x-section-tabs.pane>

            <x-section-tabs.pane section="machines">
                <section id="machines" class="card procedure-machines" aria-labelledby="procedure-machines-title">
                    <div class="card-header">
                        <h2 class="card-title" id="procedure-machines-title">Kullanan makineler</h2>
                    </div>
                    @if ($procedure->machines->isEmpty())
                        <div class="card-body">
                            <p class="empty-state mb-0">Bu prosedür henüz bir makineye bağlanmadı.</p>
                        </div>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($procedure->machines as $machine)
                                <li class="list-group-item procedure-machines__item">
                                    <span class="procedure-machine">{{ $machine->line->facility->code }} / {{ $machine->line->code }} / {{ $machine->code }}</span>
                                    — {{ $machine->name }}
                                    @unless ($machine->is_active)
                                        <span class="procedure-machines__retired">(kullanımdan kaldırıldı)</span>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </x-section-tabs.pane>

            <x-section-tabs.pane section="history">
                <x-definition-history :definition="$procedure" id="history" class="procedure-history" />
            </x-section-tabs.pane>
        </div>
    </div>
@endsection
