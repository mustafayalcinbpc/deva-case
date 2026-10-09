@extends('layouts.app')

@section('title', 'Değişiklik Günlüğü')
@section('page-title', 'Değişiklik Günlüğü')
@section('page-subtitle', 'Tesis, hat, makine, prosedür, malzeme, iş emri ve kullanıcı tanımlarında kim, ne zaman, neyi değiştirdi. Günlük kayıtları değiştirilemez ve silinemez.')

{{--
    Tanım değişiklik günlüğü (R-49). En yeni değişiklik üstte. Her satırda işlemi yapan (oturum
    yoksa "Sistem"), değişen tanım ve sayfasına bağlantı, işlem ve alanların eski → yeni hali.
    Bir tanımın sayfasından gelinirse (root_type, root_id) yalnızca o tanımın ve alt tanımlarının
    değişiklikleri gösterilir.
--}}
@section('content')
    @if ($definition)
        <div class="alert alert-info definition-change-scope" role="status">
            <span class="definition-change-scope__text">
                {{ $definition['type'] }}
                @if ($definition['url'])
                    <a href="{{ $definition['url'] }}" class="definition-change-scope__label">{{ $definition['label'] }}</a>
                @else
                    <strong class="definition-change-scope__label">{{ $definition['label'] }}</strong>
                @endif
                için değişiklikler gösteriliyor.
            </span>
            <a href="{{ route('admin.definition-changes.index') }}" class="alert-link definition-change-scope__all">Bütün değişiklikler</a>
        </div>
    @endif

    <section class="card admin-list definition-change-list" aria-labelledby="definition-change-list-title">
        <div class="card-header">
            <h2 class="card-title" id="definition-change-list-title">Değişiklikler</h2>
        </div>

        <div class="card-body border-bottom admin-list__filters">
            <form method="GET" action="{{ route('admin.definition-changes.index') }}" class="row g-3 align-items-end admin-filters definition-change-filters" role="search" aria-label="Değişiklikleri filtrele">
                @if ($definition)
                    <input type="hidden" name="root_type" value="{{ $filters['root_type'] }}">
                    <input type="hidden" name="root_id" value="{{ $filters['root_id'] }}">
                @endif

                <div class="col-12 col-md-6 col-xl-2">
                    <label for="filter-type" class="form-label">Tanım türü</label>
                    <select id="filter-type" name="type" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label for="filter-actor" class="form-label">Kişi</label>
                    <select id="filter-actor" name="actor" class="form-select">
                        <option value="">Tümü</option>
                        <option value="{{ $systemActor }}" @selected($filters['actor'] === $systemActor)>Sistem</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor->id }}" @selected($filters['actor'] === $actor->id)>{{ $actor->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label for="filter-action" class="form-label">İşlem</label>
                    <select id="filter-action" name="action" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action->value }}" @selected($filters['action'] === $action)>{{ $action->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label for="filter-from" class="form-label">Başlangıç</label>
                    <input type="date" id="filter-from" name="from" value="{{ $filters['from']?->format('Y-m-d') }}" class="form-control">
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label for="filter-to" class="form-label">Bitiş</label>
                    <input type="date" id="filter-to" name="to" value="{{ $filters['to']?->format('Y-m-d') }}" class="form-control">
                </div>

                <div class="col-12 col-xl-2 admin-filters__actions">
                    <button type="submit" class="btn btn-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrele
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.definition-changes.index') }}" class="btn btn-link">Filtreyi temizle</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($changes->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">
                    @if ($isFiltered)
                        Filtreye uyan değişiklik yok.
                    @else
                        Henüz kayıtlı değişiklik yok.
                    @endif
                </p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 admin-list__table definition-change-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Zaman</th>
                                <th scope="col">Kişi</th>
                                <th scope="col">Tanım</th>
                                <th scope="col">İşlem</th>
                                <th scope="col">Değişiklik</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($changes as $change)
                                <tr id="definition-change-{{ $change->id }}" class="definition-change-list__row">
                                    <td class="text-nowrap definition-change__time">
                                        <x-datetime :value="$change->occurred_at" format="d.m.Y H:i:s" />
                                    </td>
                                    <td @class(['definition-change__actor', 'definition-change__actor--system' => $change->actor_id === null])>
                                        {{ $change->actorName() }}
                                    </td>
                                    <td class="definition-change__subject">
                                        <span class="definition-change__type">{{ $change->subjectTypeLabel() }}</span>
                                        @if ($links[$change->id] ?? null)
                                            <a href="{{ $links[$change->id] }}" class="definition-change__label">{{ $change->subject_label }}</a>
                                        @else
                                            <span class="definition-change__label">{{ $change->subject_label }}</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">@include('admin.definition-changes.action', ['action' => $change->action])</td>
                                    <td class="definition-change__changes">@include('admin.definition-changes.diff', ['change' => $change])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($changes->hasPages())
                <div class="card-footer admin-list__pagination">
                    {{ $changes->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
