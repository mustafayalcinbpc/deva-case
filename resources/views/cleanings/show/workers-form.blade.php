{{--
    Güncel adımın görevli listesi (R-22, R-23, K-04, K-10). Hatalı gönderimden sonra kullanıcının
    seçimi korunur; diğer durumlarda adımın şu anki görevlileri işaretlidir.
--}}
@use('App\Enums\StepStatus')

@php
    $workersSubmitted = old('user_ids') !== null || $errors->hasAny(['user_ids', 'user_ids.*']);
    $selectedWorkers = $workersSubmitted
        ? array_map('intval', (array) old('user_ids', []))
        : $row['assigneeIds'];
@endphp

<details class="workers-form" @if ($workersSubmitted) open @endif>
    <summary class="workers-form__toggle">
        <i class="bi bi-people" aria-hidden="true"></i> Görevlileri değiştir
    </summary>

    <form method="POST" action="{{ route('cleanings.steps.workers', [$cleaning, $current]) }}" class="workers-form__form" data-module="submit-once">
        @csrf
        @method('PUT')

        <fieldset class="workers-form__fieldset">
            <legend class="form-label workers-form__legend">Bu adımda çalışan kişiler</legend>
            <p class="form-text workers-form__help">
                En az bir kişi seçilmelidir. Kaydın sahibi listeden çıkarılsa da adımı yürütmeye devam eder, ama eforuna sayılmaz.
                @if ($current->status === StepStatus::Running)
                    Adım çalışırken yapılan değişiklikte o ana kadarki çalışma dilimi kapanır ve yeni görevlilerle yeni dilim başlar.
                @endif
            </p>

            <div class="workers-form__choices">
                @foreach ($workerChoices as $choice)
                    <div class="form-check workers-form__choice">
                        <input type="checkbox" name="user_ids[]" value="{{ $choice->id }}" id="worker-{{ $choice->id }}"
                               @class(['form-check-input', 'is-invalid' => $errors->hasAny(['user_ids', 'user_ids.*'])])
                               @checked(in_array($choice->id, $selectedWorkers, true))>
                        <label class="form-check-label" for="worker-{{ $choice->id }}">
                            {{ $choice->name }}
                            @if ($cleaning->owner_id === $choice->id)
                                <span class="workers-form__note">(kayıt sahibi)</span>
                            @endif
                        </label>
                    </div>
                @endforeach
            </div>

            @error('user_ids')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            @error('user_ids.*')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </fieldset>

        <button type="submit" class="btn btn-secondary workers-form__submit">Görevlileri kaydet</button>
    </form>
</details>
