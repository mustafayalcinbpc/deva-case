{{--
    R-02, K-18: yalnızca kullanımda olan ve geçerli prosedürü bulunan makineler; prosedür makineden
    gelir. Seçilen makinenin özeti (data-machine-summary) ve K-05 uyarısı cleaning-form.js ile
    gösterilir. JS olmadan da makinede başlamamış kayıt olduğu seçenek metninden anlaşılır. Makine
    seçilmeden gönderilirse alanın altında "Lütfen bir makine seçin." görünür, seçilince kalkar.
    Görevden açılan kayıtta (K-21) makine görevden gelir: liste seçili ve kilitli gösterilir,
    değer gizli alanla gönderilir (kilitli liste gönderilmez).
--}}
@php
    $selectedMachine = (string) ($task?->machine_id ?? old('machine_id'));
@endphp
<section class="card cleaning-form__section" aria-labelledby="cleaning-form-machine">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-machine">Makine</h2>
        <span class="card-meta">Zorunlu · prosedür makineden gelir</span>
    </div>

    <div class="card-body">
        <label for="machine_id" class="form-label">Temizlenecek makine</label>
        @if ($task)
            <input type="hidden" name="machine_id" value="{{ $task->machine_id }}">
        @endif
        <select id="machine_id"
                @unless ($task) name="machine_id" @endunless
                @class(['form-select', 'is-invalid' => $errors->has('machine_id')])
                aria-describedby="machine_id-help @error('machine_id') machine_id-error @enderror"
                @if ($task) disabled @else required @endif
                data-machine-select>
            <option value="">Makine seçin</option>
            @foreach ($machineGroups as $location => $machines)
                <optgroup label="{{ $location }}">
                    @foreach ($machines as $machine)
                        <option value="{{ $machine->id }}"
                                data-line-id="{{ $machine->line_id }}"
                                data-material-required="{{ $machine->currentVersion->material_required ? '1' : '0' }}"
                                @selected($selectedMachine === (string) $machine->id)>
                            {{ $machine->code }} — {{ $machine->name }}@if ($machine->pendingCleanings->isNotEmpty()) · {{ $machine->pendingCleanings->count() }} başlamamış kayıt var @endif
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error('machine_id')
            <div id="machine_id-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
        {{-- Makine seçilmeden gönderilince tarayıcının genel uyarısı yerine (cleaning-form.js). --}}
        <div id="machine_id-required" class="invalid-feedback" data-machine-required hidden>Lütfen bir makine seçin.</div>
        <div id="machine_id-help" class="form-text">
            @if ($task)
                Makine görevden gelir ve değiştirilemez. Başka bir makine için görevsiz yeni kayıt açın.
            @else
                Listede yalnızca kullanımda olan ve geçerli prosedürü bulunan makineler var. Prosedür seçilmez; makineden gelir.
            @endif
        </div>

        @if ($machineGroups->isEmpty())
            <p class="empty-state cleaning-form__no-machines">Kayıt açılabilecek makine yok. Yöneticinize başvurun.</p>
        @endif

        <div class="machine-summaries" data-machine-summaries aria-live="polite" hidden>
            <p class="machine-summary machine-summary--empty" data-machine-summary="">
                Makineyi seçtiğinizde prosedürü, fazları ve malzeme zorunluluğu burada görünür.
            </p>
            @foreach ($machineGroups->flatten(1) as $machine)
                @include('cleanings.form.machine-summary', ['machine' => $machine])
            @endforeach
        </div>
    </div>
</section>
