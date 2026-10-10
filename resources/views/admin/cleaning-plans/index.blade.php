@extends('layouts.app')

@section('title', 'Temizlik Planları')
@section('page-title', 'Temizlik Planları')
@section('page-subtitle', 'Planlar yapılması gereken temizliği görev olarak üretir: belirli aralıklarla ya da makinedeki üretim iş emri tamamlanınca.')

@section('page-actions')
    <a href="{{ route('admin.cleaning-plans.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni temizlik planı
    </a>
@endsection

{{--
    K-20, K-24: makine bazında temizlik planları. Planın sıradaki görevi hemen "ileride" olarak açılır
    (plan, üretim iş emri ve görev ekranları; dakikalık cleaning:generate-tasks kaçanları yakalar);
    "üretim iş emri tamamlanınca" kuralında görevin vakti emir tamamlanınca gelir. Bir planın aynı
    anda tek etkin görevi olur (K-21, K-23).
--}}
@section('content')
    @if ($errors->has('plan'))
        <div class="alert alert-danger cleaning-plan-list__error" role="alert">{{ $errors->first('plan') }}</div>
    @endif

    <section class="card admin-list cleaning-plan-list" aria-labelledby="cleaning-plan-list-title">
        <div class="card-header">
            <h2 class="card-title" id="cleaning-plan-list-title">Temizlik planları</h2>
        </div>

        @if ($plans->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Henüz temizlik planı tanımlanmadı.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 admin-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Makine</th>
                                <th scope="col">Kural</th>
                                <th scope="col">Etkin görev</th>
                                <th scope="col">Son görev</th>
                                <th scope="col">Durum</th>
                                <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plans as $plan)
                                <tr id="cleaning-plan-{{ $plan->id }}" @class(['admin-list__row', 'admin-list__row--retired' => ! $plan->is_active])>
                                    <td>
                                        <a href="{{ route('admin.cleaning-plans.edit', $plan) }}" class="record-no">{{ $plan->machine->line->facility->code }} / {{ $plan->machine->line->code }} / {{ $plan->machine->code }}</a>
                                        <span class="cleaning-plan-list__machine d-block">{{ $plan->machine->name }}</span>
                                    </td>
                                    <td>@include('admin.cleaning-plans.rule', ['plan' => $plan])</td>
                                    <td>@include('admin.cleaning-plans.active-task', ['plan' => $plan])</td>
                                    <td class="text-nowrap"><x-datetime :value="$plan->last_task_at" format="list" /></td>
                                    <td class="text-nowrap">
                                        @if ($plan->is_active)
                                            <span class="status-badge status-badge--active">Kullanımda</span>
                                        @else
                                            <span class="status-badge status-badge--retired">Kullanımdan kaldırıldı</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap admin-list__actions">
                                        <a href="{{ route('admin.cleaning-plans.edit', $plan) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                            <span class="visually-hidden">{{ $plan->machine->code }}</span>
                                        </a>
                                        @include('admin.cleaning-plans.toggle', ['plan' => $plan, 'size' => 'btn-sm'])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($plans->hasPages())
                <div class="card-footer admin-list__pagination">
                    {{ $plans->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
