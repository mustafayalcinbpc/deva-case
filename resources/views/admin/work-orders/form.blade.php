@extends('layouts.app')

@php
    $editing = $workOrder->exists;
    // Kayıt, açıldığı andaki üretim iş emrine bağlıdır; kullanılmış üretim iş emrinin kodu ve bağlantısı değişmez.
    $locked = $editing && $usage > 0;
    $selectedLine = (string) old('line_id', $workOrder->line_id);
    $selectedMachine = (string) old('machine_id', $workOrder->machine_id);
@endphp

@section('title', $editing ? "Üretim İş Emri {$workOrder->code}" : 'Yeni Üretim İş Emri')
@section('page-title', $editing ? "Üretim İş Emri: {$workOrder->code}" : 'Yeni Üretim İş Emri')
@section('page-subtitle', 'Gerçek kullanımda üretim iş emirleri ERP\'den gelir; demoda burada tanımlanır.')

@section('page-actions')
    <a href="{{ route('admin.work-orders.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Üretim iş emirleri
    </a>
@endsection

{{--
    K-19: üretim iş emri bir hatta, bir makineye ya da hiçbirine bağlanır. Makine seçilirse hat
    makineden gelir; ikisi birlikte seçilirse makine o hatta olmalıdır (SaveWorkOrderRequest).
--}}
@section('content')
    <form method="POST"
          action="{{ $editing ? route('admin.work-orders.update', $workOrder) : route('admin.work-orders.store') }}"
          class="card admin-form work-order-form"
          data-module="submit-once"
          aria-labelledby="work-order-form-title">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="card-header">
            <h2 class="card-title" id="work-order-form-title">Üretim iş emri bilgileri</h2>
        </div>

        <div class="card-body">
            @if ($locked)
                <div class="alert alert-info work-order-form__locked" role="note">
                    Bu üretim iş emri {{ $usage }} temizlik kaydında kullanıldı. Kodu ve bağlantısı değiştirilemez; yalnızca açıklaması düzeltilebilir.
                </div>
            @endif

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="code" class="form-label">Üretim iş emri kodu</label>
                    <input type="text"
                           id="code"
                           name="code"
                           value="{{ old('code', $workOrder->code) }}"
                           maxlength="50"
                           required
                           autocomplete="off"
                           @readonly($locked)
                           @class(['form-control', 'is-invalid' => $errors->has('code')])
                           @error('code') aria-describedby="code-error" @enderror>
                    @error('code')
                        <div id="code-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-8">
                    <label for="description" class="form-label">Açıklama <span class="optional-mark">(isteğe bağlı)</span></label>
                    <input type="text"
                           id="description"
                           name="description"
                           value="{{ old('description', $workOrder->description) }}"
                           maxlength="255"
                           @class(['form-control', 'is-invalid' => $errors->has('description')])
                           @error('description') aria-describedby="description-error" @enderror>
                    @error('description')
                        <div id="description-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="line_id" class="form-label">Bağlı hat <span class="optional-mark">(isteğe bağlı)</span></label>
                    <select id="line_id"
                            @if ($locked) disabled @else name="line_id" @endif
                            @class(['form-select', 'is-invalid' => $errors->has('line_id')])
                            aria-describedby="binding-help @error('line_id') line_id-error @enderror">
                        <option value="">Hat yok</option>
                        @foreach ($lineGroups as $facility => $lines)
                            <optgroup label="{{ $facility }}">
                                @foreach ($lines as $line)
                                    <option value="{{ $line->id }}" @selected($selectedLine === (string) $line->id)>{{ $line->code }} — {{ $line->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('line_id')
                        <div id="line_id-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="machine_id" class="form-label">Bağlı makine <span class="optional-mark">(isteğe bağlı)</span></label>
                    <select id="machine_id"
                            @if ($locked) disabled @else name="machine_id" @endif
                            @class(['form-select', 'is-invalid' => $errors->has('machine_id')])
                            aria-describedby="binding-help @error('machine_id') machine_id-error @enderror">
                        <option value="">Makine yok</option>
                        @foreach ($machineGroups as $location => $machines)
                            <optgroup label="{{ $location }}">
                                @foreach ($machines as $machine)
                                    <option value="{{ $machine->id }}" data-line-id="{{ $machine->line_id }}" @selected($selectedMachine === (string) $machine->id)>
                                        {{ $machine->code }} — {{ $machine->name }}{{ $machine->is_active ? '' : ' (kullanımdan kaldırıldı)' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('machine_id')
                        <div id="machine_id-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @if ($locked)
                    {{-- Kilitli bağlantı değişmeden geri gönderilir; sunucu yine de karşılaştırır. --}}
                    <input type="hidden" name="line_id" value="{{ $workOrder->line_id }}">
                    <input type="hidden" name="machine_id" value="{{ $workOrder->machine_id }}">
                @endif

                <div class="col-12">
                    <div id="binding-help" class="form-text">
                        Makineye bağlı üretim iş emri yalnızca o makinede, hatta bağlı üretim iş emri o hattın makinelerinde seçilebilir.
                        Makine seçilirse hat makineden gelir; ikisi birlikte seçilirse makine o hatta olmalıdır.
                        Hiçbiri seçilmezse üretim iş emri bütün makinelerde kullanılabilir.
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer admin-form__actions">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $editing ? 'Kaydet' : 'Üretim iş emrini ekle' }}
            </button>
            <a href="{{ route('admin.work-orders.index') }}" class="btn btn-link">Vazgeç</a>
        </div>
    </form>

    @if ($editing)
        <x-definition-history :definition="$workOrder" class="work-order-history mt-3" />
    @endif
@endsection
