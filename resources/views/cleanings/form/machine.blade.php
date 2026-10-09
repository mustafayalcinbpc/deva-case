{{--
    R-02, K-18: yalnızca kullanımda olan ve geçerli prosedürü bulunan makineler; prosedür makineden
    gelir. Seçilen makinenin özeti (data-machine-summary) ve K-05 uyarısı cleaning-form.js ile
    gösterilir. JS olmadan da makinede başlamamış kayıt olduğu seçenek metninden anlaşılır.
--}}
<section class="card cleaning-form__section" aria-labelledby="cleaning-form-machine">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-machine">Makine</h2>
        <span class="card-meta">Zorunlu · prosedür makineden gelir</span>
    </div>

    <div class="card-body">
        <label for="machine_id" class="form-label">Temizlenecek makine</label>
        <select id="machine_id"
                name="machine_id"
                @class(['form-select', 'is-invalid' => $errors->has('machine_id')])
                aria-describedby="machine_id-help @error('machine_id') machine_id-error @enderror"
                required
                data-machine-select>
            <option value="">Makine seçin</option>
            @foreach ($machineGroups as $location => $machines)
                <optgroup label="{{ $location }}">
                    @foreach ($machines as $machine)
                        <option value="{{ $machine->id }}"
                                data-line-id="{{ $machine->line_id }}"
                                data-material-required="{{ $machine->currentVersion->material_required ? '1' : '0' }}"
                                @selected((string) old('machine_id') === (string) $machine->id)>
                            {{ $machine->code }} — {{ $machine->name }}@if ($machine->pendingCleanings->isNotEmpty()) · {{ $machine->pendingCleanings->count() }} başlamamış kayıt var @endif
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error('machine_id')
            <div id="machine_id-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="machine_id-help" class="form-text">
            Listede yalnızca kullanımda olan ve geçerli prosedürü bulunan makineler var. Prosedür seçilmez; makineden gelir.
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
