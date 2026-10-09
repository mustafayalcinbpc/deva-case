{{--
    Taslağı yayımlama (K-15): hemen ya da ileri bir tarihte. Tarih gösterim saat diliminde
    girilir, UTC saklanır. Yapı kuralları (en az bir faz, her fazda en az bir adım) önceden
    listelenir; asıl kontrol ProcedureVersioning::publish() içindedir.
--}}
@use('App\Http\Requests\Admin\Procedures\PublishProcedureVersionRequest')

@php
    $timezone = config('app.display_timezone');
    $blockers = $version->phases->isEmpty()
        ? collect(['En az bir faz ekleyin.'])
        : $version->phases->filter(fn ($phase) => $phase->steps->isEmpty())->map(fn ($phase) => "“{$phase->name}” fazına en az bir adım ekleyin.");
    $scheduled = old('when') === PublishProcedureVersionRequest::SCHEDULED;
@endphp

<section id="publish" class="card procedure-publish mb-3" aria-labelledby="procedure-publish-title">
    <div class="card-header">
        <h2 class="card-title" id="procedure-publish-title">Yayımla</h2>
    </div>

    <form method="POST"
          action="{{ route('admin.procedures.versions.publish', [$procedure, $version]) }}"
          data-module="procedure-publish confirm-submit submit-once"
          data-confirm="v{{ $version->version }} yayımlandıktan sonra değiştirilemez. Yayımlansın mı?"
          novalidate>
        @csrf
        <div class="card-body">
            @include('admin.procedures.partials.errors', ['keys' => ['phases', 'published_at']])

            @if ($blockers->isNotEmpty())
                <div class="alert alert-warning procedure-publish__blockers" role="note">
                    Yayımlamadan önce:
                    <ul class="mb-0">
                        @foreach ($blockers as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <fieldset class="mb-3">
                <legend class="form-label">Yürürlüğe giriş</legend>
                <div class="form-check">
                    <input type="radio" id="when-now" name="when" value="{{ PublishProcedureVersionRequest::NOW }}" class="form-check-input" @checked(! $scheduled)>
                    <label for="when-now" class="form-check-label">Hemen</label>
                </div>
                <div class="form-check">
                    <input type="radio" id="when-scheduled" name="when" value="{{ PublishProcedureVersionRequest::SCHEDULED }}" @class(['form-check-input', 'is-invalid' => $errors->has('when')]) @checked($scheduled)>
                    <label for="when-scheduled" class="form-check-label">İleri bir tarihte</label>
                    @error('when')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </fieldset>

            <div class="mb-0">
                <label for="publish_at" class="form-label">Yayın tarihi ve saati</label>
                <input type="datetime-local"
                       id="publish_at"
                       name="publish_at"
                       value="{{ old('publish_at') }}"
                       min="{{ now()->setTimezone($timezone)->format(PublishProcedureVersionRequest::INPUT_FORMAT) }}"
                       @class(['form-control', 'is-invalid' => $errors->has('publish_at')])
                       aria-describedby="publish_at-help @error('publish_at') publish_at-error @enderror">
                @error('publish_at')
                    <div id="publish_at-error" class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div id="publish_at-help" class="form-text">
                    Yalnızca "İleri bir tarihte" için. Saat {{ $timezone }} saatidir. Tarih gelene kadar yeni kayıtlar önceki versiyonla açılır.
                    @if ($latestPublication?->isScheduled())
                        v{{ $latestPublication->version }} <x-datetime :value="$latestPublication->published_at" /> tarihinde yayına girecek; yeni versiyon bu tarihten önce yayımlanamaz.
                    @endif
                </div>
            </div>
        </div>

        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send-check" aria-hidden="true"></i> Yayımla
            </button>
        </div>
    </form>
</section>
