@extends('layouts.app')

@use('App\Enums\CleaningPlanKind')

@php
    $editing = $plan->exists;
    // Görevler planın makinesine aittir; görev üretmiş planın makinesi değişmez.
    $machineLocked = $editing && $taskCount > 0;
    $selectedMachine = (string) old('machine_id', $plan->machine_id);
    $selectedKind = old('kind', $plan->kind?->value);
    $title = $editing ? "Temizlik Planı: {$plan->machine->code}" : 'Yeni Temizlik Planı';
@endphp

@section('title', $title)
@section('page-title', $title)
@section('page-subtitle', 'Plan, yapılması gereken temizliği görev olarak üretir; kaydı operatör görevden açar.')

@section('page-actions')
    @if ($editing)
        @include('admin.cleaning-plans.toggle', ['plan' => $plan])
    @endif
    <a href="{{ route('admin.cleaning-plans.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Temizlik planları
    </a>
@endsection

{{--
    K-20: periyodik planda aralık (gün) zorunludur; görev, son görevden bu yana aralık dolunca
    açılır (ilk görev hemen). "Üretim iş emri tamamlanınca" kuralında görev, makinedeki (ya da
    makinenin hattına bağlı) üretim iş emri tamamlanınca açılır.
--}}
@section('content')
    <form method="POST"
          action="{{ $editing ? route('admin.cleaning-plans.update', $plan) : route('admin.cleaning-plans.store') }}"
          class="card admin-form cleaning-plan-form"
          data-module="submit-once"
          aria-labelledby="cleaning-plan-form-title">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="card-header">
            <h2 class="card-title" id="cleaning-plan-form-title">Plan bilgileri</h2>
        </div>

        <div class="card-body">
            @if ($editing)
                <p class="cleaning-plan-form__task">
                    Etkin görev: @include('admin.cleaning-plans.active-task', ['plan' => $plan])
                </p>
            @endif

            @if ($machineLocked)
                <div class="alert alert-info cleaning-plan-form__locked" role="note">
                    Bu plan {{ $taskCount }} görev üretti. Makinesi değiştirilemez; kuralı ve aralığı değiştirilebilir.
                </div>
            @endif

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="machine_id" class="form-label">Makine</label>
                    <select id="machine_id"
                            @if ($machineLocked) disabled @else name="machine_id" @endif
                            required
                            @class(['form-select', 'is-invalid' => $errors->has('machine_id')])
                            @error('machine_id') aria-describedby="machine_id-error" @enderror>
                        <option value="">Seçin</option>
                        @foreach ($machineGroups as $location => $machines)
                            <optgroup label="{{ $location }}">
                                @foreach ($machines as $machine)
                                    <option value="{{ $machine->id }}" @selected($selectedMachine === (string) $machine->id)>
                                        {{ $machine->code }} — {{ $machine->name }}{{ $machine->is_active ? '' : ' (kullanımdan kaldırıldı)' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @if ($machineLocked)
                        <input type="hidden" name="machine_id" value="{{ $plan->machine_id }}">
                    @endif
                    @error('machine_id')
                        <div id="machine_id-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-6">
                    <fieldset class="cleaning-plan-form__kind">
                        <legend class="form-label">Kural</legend>
                        @foreach ($kinds as $kind)
                            <div class="form-check">
                                <input type="radio"
                                       id="kind-{{ $kind->value }}"
                                       name="kind"
                                       value="{{ $kind->value }}"
                                       @checked($selectedKind === $kind->value)
                                       @class(['form-check-input', 'is-invalid' => $errors->has('kind')])>
                                <label for="kind-{{ $kind->value }}" class="form-check-label">{{ $kind->label() }}</label>
                            </div>
                        @endforeach
                        @error('kind')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </fieldset>
                </div>

                <div class="col-12 col-md-4">
                    <label for="interval_days" class="form-label">Aralık (gün)</label>
                    <input type="number"
                           id="interval_days"
                           name="interval_days"
                           value="{{ old('interval_days', $plan->interval_days) }}"
                           min="1"
                           max="{{ \App\Http\Requests\Admin\Planning\SaveCleaningPlanRequest::MAX_INTERVAL_DAYS }}"
                           inputmode="numeric"
                           @class(['form-control', 'is-invalid' => $errors->has('interval_days')])
                           aria-describedby="interval-help @error('interval_days') interval_days-error @enderror">
                    <div id="interval-help" class="form-text">Yalnızca periyodik planda kullanılır.</div>
                    @error('interval_days')
                        <div id="interval_days-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        <div class="card-footer admin-form__actions">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $editing ? 'Kaydet' : 'Planı ekle' }}
            </button>
            <a href="{{ route('admin.cleaning-plans.index') }}" class="btn btn-link">Vazgeç</a>
        </div>
    </form>

    @if ($editing)
        <x-definition-history :definition="$plan" class="cleaning-plan-history mt-3" />
    @endif
@endsection
