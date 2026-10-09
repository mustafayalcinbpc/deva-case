{{--
    Güncel adımın aksiyonları: Başlat (bekleyen), Duraklat / Tamamla (çalışan), Devam et
    (duraklatılmış) ve görevli değişikliği. Yalnızca izin verilen kullanıcıya gösterilir; kuralı
    workflow uygular. Fazın son adımında tamamlarken minimum süre gerekçesi istenebilir (K-01):
    workflow fazı minimumun altında bulursa alan açık ve açıklamalı gösterilir.
--}}
@use('App\Enums\StepStatus')

@php
    $completeFormId = "step-{$current->id}-complete";
    $askDeviation = $current->status === StepStatus::Running && $row['isLastOfPhase'] && $phaseRow['minimum'] > 0;
    $deviationOpen = $deviation !== null || $errors->has('deviation_reason') || filled(old('deviation_reason'));
@endphp

@if ($current->status === StepStatus::Paused)
    <p class="now-card__hint form-text">Duraklatılmış adım doğrudan tamamlanamaz; önce devam ettirin.</p>
@endif

@if ($askDeviation)
    <details class="deviation-field" @if ($deviationOpen) open @endif>
        <summary class="deviation-field__toggle">Minimum süre altı gerekçesi</summary>

        <div class="deviation-field__body">
            @if ($deviation !== null)
                <div class="alert alert-warning deviation-field__alert" role="alert">
                    <strong>{{ $currentPhase->sequence }}. faz minimum sürenin altında kaldı.</strong>
                    Adımı tamamlamak için gerekçe yazın; faz "minimum süre altında" olarak işaretlenir.
                    <span class="deviation-field__numbers">
                        Ölçülen süre <x-duration :seconds="$deviation['measured']" class="deviation-field__measured" />
                        · Minimum <x-duration :seconds="$deviation['minimum']" class="deviation-field__minimum" />
                    </span>
                </div>
            @else
                <p class="form-text">Faz minimum süreden kısa sürdüyse gerekçe yazmak zorunludur; değilse boş bırakın.</p>
            @endif

            <label for="deviation-reason" class="form-label">Gerekçe</label>
            <textarea id="deviation-reason" name="deviation_reason" form="{{ $completeFormId }}" rows="3"
                      @class(['form-control', 'is-invalid' => $errors->has('deviation_reason')])
                      @if ($deviation !== null) autofocus @endif>{{ old('deviation_reason') }}</textarea>
            @error('deviation_reason')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </details>
@endif

<div class="now-card__actions">
    @switch($current->status)
        @case(StepStatus::Pending)
            <form method="POST" action="{{ route('cleanings.steps.start', [$cleaning, $current]) }}" class="now-card__form" data-module="submit-once">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg now-card__action now-card__action--start">
                    <i class="bi bi-play-fill" aria-hidden="true"></i> Başlat
                </button>
            </form>
            @break

        @case(StepStatus::Running)
            <form method="POST" action="{{ route('cleanings.steps.pause', [$cleaning, $current]) }}" class="now-card__form" data-module="submit-once">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-lg now-card__action now-card__action--pause">
                    <i class="bi bi-pause-fill" aria-hidden="true"></i> Duraklat
                </button>
            </form>
            <form method="POST" action="{{ route('cleanings.steps.complete', [$cleaning, $current]) }}" id="{{ $completeFormId }}" class="now-card__form" data-module="submit-once">
                @csrf
                <button type="submit" class="btn btn-success btn-lg now-card__action now-card__action--complete">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Tamamla
                </button>
            </form>
            @break

        @case(StepStatus::Paused)
            <form method="POST" action="{{ route('cleanings.steps.resume', [$cleaning, $current]) }}" class="now-card__form" data-module="submit-once">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg now-card__action now-card__action--resume">
                    <i class="bi bi-play-fill" aria-hidden="true"></i> Devam et
                </button>
            </form>
            @break
    @endswitch
</div>

@include('cleanings.show.workers-form')
