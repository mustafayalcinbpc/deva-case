{{--
    R-23: kaydın sahibi ve seçilen yardımcılar her adıma görevli olarak eklenir; adım bazında
    sonradan değiştirilebilir (R-22). Kaydı açan kişi listede yoktur.
--}}
@php($oldHelperIds = array_map(strval(...), array_filter((array) old('helper_ids', []), is_scalar(...))))
@php($helperError = $errors->first('helper_ids') ?: $errors->first('helper_ids.*'))

<section class="card cleaning-form__section" aria-labelledby="cleaning-form-helpers">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-helpers">Personel</h2>
        <span class="card-meta">Yardımcılar isteğe bağlı</span>
    </div>

    <div class="card-body">
        <p class="cleaning-form__owner">
            Sorumlu: <strong>{{ auth()->user()->name }}</strong> (siz). Her adıma görevli olarak eklenirsiniz;
            kaydın sorumluluğu sonradan başkasına devredilemez.
        </p>

        <fieldset aria-describedby="helper_ids-help @if ($helperError) helper_ids-error @endif">
            <legend class="form-label">Yardımcı personel <span class="optional-mark">(isteğe bağlı)</span></legend>

            @if ($helpers->isEmpty())
                <p class="empty-state mb-0">Seçilebilecek başka aktif personel yok.</p>
            @else
                <div class="helper-list">
                    @foreach ($helpers as $helper)
                        <div class="form-check helper-list__item">
                            <input type="checkbox"
                                   id="helper-{{ $helper->id }}"
                                   name="helper_ids[]"
                                   value="{{ $helper->id }}"
                                   @class(['form-check-input', 'is-invalid' => $helperError])
                                   @checked(in_array((string) $helper->id, $oldHelperIds, true))>
                            <label for="helper-{{ $helper->id }}" class="form-check-label">{{ $helper->name }}</label>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($helperError)
                <div id="helper_ids-error" class="invalid-feedback d-block">{{ $helperError }}</div>
            @endif
            <div id="helper_ids-help" class="form-text">
                Seçtiğiniz kişiler her adıma görevli olarak eklenir; adım bazında sonradan değiştirilebilir.
            </div>
        </fieldset>
    </div>
</section>
