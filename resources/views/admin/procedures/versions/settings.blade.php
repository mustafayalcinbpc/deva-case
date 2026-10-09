{{--
    Versiyonun genel bilgisi. Taslakta malzeme zorunluluğu düzenlenir (K-13); yayımlanmış
    versiyonda yayın tarihi ve bu versiyonla açılan kayıt sayısı gösterilir.
--}}
<section class="card procedure-settings mb-3" aria-labelledby="procedure-settings-title">
    <div class="card-header">
        <h2 class="card-title" id="procedure-settings-title">Versiyon</h2>
    </div>

    @if ($editable)
        <form method="POST" action="{{ route('admin.procedures.versions.update', [$procedure, $version]) }}" data-module="submit-once">
            @csrf
            @method('PUT')
            <div class="card-body">
                <div class="form-check mb-0">
                    <input type="checkbox"
                           id="material_required"
                           name="material_required"
                           value="1"
                           @checked($version->material_required)
                           @class(['form-check-input', 'is-invalid' => $errors->has('material_required')])
                           aria-describedby="material_required-help">
                    <label for="material_required" class="form-check-label">Malzeme girişi zorunlu</label>
                    @error('material_required')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div id="material_required-help" class="form-text">
                        K-13: işaretliyse kayda en az bir geçerli malzeme girilmeden ilk adım başlatılamaz.
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Kaydet</button>
            </div>
        </form>
    @else
        <div class="card-body">
            <dl class="procedure-facts mb-0">
                <dt>Yayın tarihi</dt>
                <dd><x-datetime :value="$version->published_at" /></dd>
                <dt>Malzeme girişi</dt>
                <dd>{{ $version->material_required ? 'Zorunlu' : 'Zorunlu değil' }}</dd>
                <dt>Bu versiyonla açılan kayıt</dt>
                <dd>{{ $version->cleanings_count }}</dd>
            </dl>
        </div>
    @endif
</section>
