{{--
    K-19: üretim iş emri listeden seçilir ve isteğe bağlıdır. cleaning-form.js seçilen makineye ya da
    hattına ait olmayanları gizler; asıl kontrol workflow'dadır (invalid_work_order). Görevden
    açılan kayıtta görevin sonraki üretim iş emri seçili gelir ve değiştirilebilir (K-21).
--}}
@php
    $selectedWorkOrder = (string) old('work_order_id', $task?->work_order_id);
@endphp
<section class="card cleaning-form__section" aria-labelledby="cleaning-form-details">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-details">Üretim iş emri ve açıklama</h2>
        <span class="card-meta">İsteğe bağlı</span>
    </div>

    <div class="card-body">
        <div class="cleaning-form__field">
            <label for="work_order_id" class="form-label">Üretim iş emri <span class="optional-mark">(isteğe bağlı)</span></label>
            <select id="work_order_id"
                    name="work_order_id"
                    @class(['form-select', 'is-invalid' => $errors->has('work_order_id')])
                    aria-describedby="work_order_id-help @error('work_order_id') work_order_id-error @enderror"
                    data-work-order-select>
                <option value="">Üretim iş emri yok</option>
                @foreach ($workOrders as $workOrder)
                    <option value="{{ $workOrder['id'] }}"
                            data-machine-id="{{ $workOrder['machine_id'] }}"
                            data-line-id="{{ $workOrder['line_id'] }}"
                            @selected($selectedWorkOrder === (string) $workOrder['id'])>{{ $workOrder['label'] }}</option>
                @endforeach
            </select>
            @error('work_order_id')
                <div id="work_order_id-error" class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div id="work_order_id-help" class="form-text">
                Periyodik temizlikte ilgili üretim iş emri olmayabilir. Yalnızca seçilen makineye ya da hattına ait üretim iş emirleri kullanılabilir.
            </div>
            <div class="form-text work-order-empty" data-work-order-empty hidden>Bu makine için üretim iş emri yok.</div>
        </div>

        <div class="cleaning-form__field">
            <label for="notes" class="form-label">Açıklama <span class="optional-mark">(isteğe bağlı)</span></label>
            <textarea id="notes"
                      name="notes"
                      rows="3"
                      maxlength="2000"
                      @class(['form-control', 'is-invalid' => $errors->has('notes')])
                      @error('notes') aria-describedby="notes-error" @enderror>{{ old('notes') }}</textarea>
            @error('notes')
                <div id="notes-error" class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</section>
