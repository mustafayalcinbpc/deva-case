@extends('layouts.app')

@php($title = $machine->exists ? "{$machine->code} makinesini düzenle" : 'Yeni makine')

@section('title', $title)
@section('page-title', $title)
@section('page-subtitle', 'Makine kodu kayıt numarasında hat kodundan sonra gelir; prosedür makineden kayda otomatik bağlanır.')

{{--
    Makine ekleme ve düzenleme (R-02, R-43, K-18). Prosedür listesinde yalnızca yayımlanmış
    versiyonu olan prosedürler var. Kayıt açılmış makinenin kodu ve hattı değişmez (K-17).
    Kullanım durumu bu formdan değil, makine sayfasındaki düğmelerle değişir (K-16).
--}}
@section('content')
    <form method="POST"
          action="{{ $machine->exists ? route('admin.machines.update', $machine) : route('admin.machines.store') }}"
          class="definition-form"
          data-module="submit-once">
        @csrf
        @if ($machine->exists)
            @method('PUT')
        @endif

        @include('admin.locations.partials.errors')

        <section class="card" aria-labelledby="machine-form-location">
            <div class="card-header">
                <h2 class="card-title" id="machine-form-location">Konum ve kimlik</h2>
            </div>

            <div class="card-body">
                <div class="mb-3 definition-form__field">
                    <label for="line_id" class="form-label">Hat</label>
                    @if ($codeLocked)
                        <input type="text"
                               id="line_id"
                               class="form-control"
                               value="{{ $machine->line->facility->code }} / {{ $machine->line->code }} — {{ $machine->line->name }}"
                               readonly
                               aria-describedby="line_id-locked">
                        <div id="line_id-locked" class="form-text definition-form__locked">
                            <i class="bi bi-lock" aria-hidden="true"></i> Kayıt açılmış makine başka bir hatta taşınamaz (K-17).
                        </div>
                    @else
                        <select id="line_id"
                                name="line_id"
                                @class(['form-select', 'is-invalid' => $errors->has('line_id')])
                                @error('line_id') aria-describedby="line_id-error" @enderror
                                required>
                            <option value="">Hat seçin</option>
                            @foreach ($lineGroups as $facility => $lines)
                                <optgroup label="{{ $facility }}">
                                    @foreach ($lines as $line)
                                        <option value="{{ $line->id }}" @selected((string) old('line_id', $machine->line_id) === (string) $line->id)>{{ $line->code }} — {{ $line->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @if ($lineGroups->isEmpty())
                            <div class="form-text">Önce <a href="{{ route('admin.facilities.index') }}">tesis ve hat</a> tanımlayın.</div>
                        @endif
                    @endif
                    @error('line_id')
                        <div id="line_id-error" class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                @include('admin.locations.partials.code-field', [
                    'value' => $machine->code,
                    'locked' => $codeLocked,
                    'reason' => \App\Services\Definitions\IssuedCodeLock::MACHINE_REASON,
                    'help' => 'Büyük harf ve rakam, en fazla 10 karakter; hat içinde benzersiz.',
                    'example' => 'M03',
                ])

                @include('admin.locations.partials.name-field', ['value' => $machine->name])
            </div>
        </section>

        <section class="card" aria-labelledby="machine-form-procedure">
            <div class="card-header">
                <h2 class="card-title" id="machine-form-procedure">Prosedür</h2>
            </div>

            <div class="card-body">
                <div class="mb-3 definition-form__field">
                    <label for="procedure_id" class="form-label">Geçerli prosedür</label>
                    <select id="procedure_id"
                            name="procedure_id"
                            @class(['form-select', 'is-invalid' => $errors->has('procedure_id')])
                            aria-describedby="procedure_id-help @error('procedure_id') procedure_id-error @enderror"
                            @required(! $machine->exists || $machine->is_active)>
                        <option value="">{{ ! $machine->exists || $machine->is_active ? 'Prosedür seçin' : 'Prosedür yok' }}</option>
                        @foreach ($procedures as $procedure)
                            <option value="{{ $procedure->id }}" @selected((string) old('procedure_id', $machine->procedure_id) === (string) $procedure->id)>
                                {{ $procedure->code }} — {{ $procedure->name }}
                                @if ($procedure->current_version !== null)
                                    (v{{ $procedure->current_version }})
                                @else
                                    (yayımlanmış versiyonu yok)
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('procedure_id')
                        <div id="procedure_id-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div id="procedure_id-help" class="form-text">
                        Listede yalnızca yayımlanmış versiyonu olan prosedürler var. Bu makinedeki planlı ve plansız bütün
                        temizlikler bu prosedüre göre yapılır; yeni kayıt, prosedürün en son yayımlanmış versiyonuna bağlanır.
                        Prosedürü değiştirmek açık kayıtları etkilemez.
                        @if ($machine->exists && ! $machine->is_active)
                            Makine kullanımdan kaldırılmış olduğu için prosedür boş bırakılabilir.
                        @endif
                    </div>
                    @if ($procedures->isEmpty())
                        <p class="empty-state mb-0">
                            Yayımlanmış prosedür yok. Önce bir prosedür yayımlanmalı.
                            @if (Route::has('admin.procedures.index'))
                                <a href="{{ route('admin.procedures.index') }}">Prosedürler</a>
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="card-footer definition-form__actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2" aria-hidden="true"></i> Kaydet
                </button>
                <a href="{{ $machine->exists ? route('admin.machines.show', $machine) : route('admin.machines.index') }}" class="btn btn-outline-secondary">Vazgeç</a>
            </div>
        </section>
    </form>
@endsection
