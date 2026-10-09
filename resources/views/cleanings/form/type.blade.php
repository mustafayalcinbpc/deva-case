{{--
    K-18: operatör prosedür seçmez, yalnızca temizliğin planlı mı plansız mı olduğunu seçer.
    Varsayılan seçim yoktur: tür sonradan değiştirilemez ve saha defterini etkiler (R-18, R-19).
--}}
<section class="card cleaning-form__section" aria-labelledby="cleaning-form-type">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-type">Temizlik türü</h2>
        <span class="card-meta">Zorunlu · sonradan değiştirilemez</span>
    </div>

    <div class="card-body">
        <fieldset class="type-options" @error('type') aria-describedby="type-error" @enderror>
            <legend class="visually-hidden">Temizlik türü</legend>

            {{-- Segment kontrol görünümünde iki seçenek; gerçek radyo düğmeleri görünür kalır. --}}
            <div class="type-options__choices">
                @foreach ($types as $type)
                    <div class="form-check type-option">
                        <input type="radio"
                               id="type-{{ $type->value }}"
                               name="type"
                               value="{{ $type->value }}"
                               @class(['form-check-input', 'is-invalid' => $errors->has('type')])
                               aria-describedby="type-{{ $type->value }}-help"
                               required
                               @checked(old('type') === $type->value)>
                        <label for="type-{{ $type->value }}" class="form-check-label type-option__label">{{ $type->label() }}</label>
                        <div id="type-{{ $type->value }}-help" class="form-text type-option__help">
                            @if ($type->hasFieldReference())
                                Normal temizlik. Saha defterine işlenir; saha defteri referansı ilk adım başlatıldığında otomatik üretilir.
                            @else
                                Üretim sırasında beklenmeyen bir durum için acil temizlik. Saha defterine işlenmez, saha defteri referansı olmaz.
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @error('type')
                <div id="type-error" class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </fieldset>
    </div>
</section>
