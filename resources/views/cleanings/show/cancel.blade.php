{{--
    Kaydı iptal etme (K-08, K-09). Yalnızca CleaningPermissions::allowedCancelReasons boş değilse
    gösterilir ve yalnızca o gerekçeler seçilebilir: yönetici açık her kaydı her gerekçeyle, sahibi
    başlamamış kaydını "hatalı kayıt" gerekçesiyle iptal eder. Gönderimden önce onay istenir.
--}}
@php
    $cancelFormOpen = $errors->hasAny(['cancel_reason', 'cancel_note']) || old('cancel_reason') !== null;
@endphp

<section id="cancel" class="card cancel-card" aria-labelledby="cancel-title">
    <div class="card-header">
        <h2 class="card-title" id="cancel-title">Kaydı iptal et</h2>
    </div>

    <div class="card-body">
        <p class="cancel-card__intro">
            @if (auth()->user()->isManager())
                Yönetici olarak bu açık kaydı iptal edebilirsiniz. Yapılan adımlar ve çalışma dilimleri kayıtta kalır;
                çalışan bir adım varsa dilimi iptal anında kapanır, makine ve personel serbest kalır.
            @else
                Henüz hiçbir adım başlamadığı için kendi kaydınızı "hatalı kayıt" gerekçesiyle iptal edebilirsiniz.
            @endif
            İptal geri alınamaz; temizliğin yapılması gerekiyorsa yeni kayıt açılır.
        </p>

        <details class="cancel-form" @if ($cancelFormOpen) open @endif>
            <summary class="cancel-form__toggle">İptal formunu aç</summary>

            <form method="POST" action="{{ route('cleanings.cancel', $cleaning) }}" class="cancel-form__form"
                  data-module="confirm-submit submit-once"
                  data-confirm="{{ $cleaning->record_no }} kaydı iptal edilecek. Bu işlem geri alınamaz. Devam edilsin mi?">
                @csrf

                <div class="cancel-form__field">
                    <label for="cancel-reason" class="form-label">Gerekçe türü</label>
                    <select id="cancel-reason" name="cancel_reason" required @class(['form-select', 'is-invalid' => $errors->has('cancel_reason')])>
                        @if (count($cancelReasons) > 1)
                            <option value="">Seçin</option>
                        @endif
                        @foreach ($cancelReasons as $reason)
                            <option value="{{ $reason->value }}" @selected(old('cancel_reason') === $reason->value)>{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                    @error('cancel_reason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="cancel-form__field">
                    <label for="cancel-note" class="form-label">Açıklama</label>
                    <textarea id="cancel-note" name="cancel_note" rows="3" required
                              @class(['form-control', 'is-invalid' => $errors->has('cancel_note')])>{{ old('cancel_note') }}</textarea>
                    @error('cancel_note')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-danger cancel-form__submit">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> Kaydı iptal et
                </button>
            </form>
        </details>
    </div>
</section>
