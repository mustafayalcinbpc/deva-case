{{--
    Bir faz ve sıralı adımları (R-03, R-04). Parametreler: $phase, $first, $last ve sayfanın
    $editable, $procedure, $version değişkenleri. Taslakta sıra, düzenleme ve silme düğmeleri görünür.
--}}
<section id="phase-{{ $phase->id }}" class="card procedure-phase mb-3" aria-labelledby="phase-{{ $phase->id }}-title">
    <div class="card-header procedure-phase__header">
        <h2 class="card-title procedure-phase__title" id="phase-{{ $phase->id }}-title">
            <span class="procedure-phase__sequence">{{ $phase->sequence }}. faz</span>
            {{ $phase->name }}
        </h2>

        @if ($editable)
            <div class="card-tools procedure-phase__actions">
                @include('admin.procedures.partials.move', [
                    'up' => route('admin.procedures.phases.move', [$procedure, $version, $phase, 'up']),
                    'down' => route('admin.procedures.phases.move', [$procedure, $version, $phase, 'down']),
                    'first' => $first,
                    'last' => $last,
                    'label' => $phase->name,
                ])
                <a href="{{ route('admin.procedures.phases.edit', [$procedure, $version, $phase]) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                </a>
                <form method="POST"
                      action="{{ route('admin.procedures.phases.destroy', [$procedure, $version, $phase]) }}"
                      class="d-inline"
                      data-module="confirm-submit submit-once"
                      data-confirm="“{{ $phase->name }}” fazı adımlarıyla birlikte silinsin mi?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-trash" aria-hidden="true"></i> Sil
                    </button>
                </form>
            </div>
        @endif
    </div>

    <div class="card-body procedure-phase__facts">
        <dl class="procedure-facts mb-0">
            <dt>Minimum süre</dt>
            <dd>
                @if ($phase->min_duration_seconds > 0)
                    <x-duration :seconds="$phase->min_duration_seconds" />
                @else
                    Tanımlı değil
                @endif
            </dd>
            <dt>Süre ölçümü</dt>
            <dd>{{ $phase->include_gaps ? 'Brüt (adımlar arası boşluklar dahil)' : 'Net (adımlar arası boşluklar sayılmaz)' }}</dd>
        </dl>
    </div>

    @if ($phase->steps->isEmpty())
        <div class="card-body procedure-phase__empty">
            <p class="empty-state mb-0">Bu fazda adım yok. Yayımlamak için her fazda en az bir adım olmalı.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0 procedure-steps">
                <thead>
                    <tr>
                        <th scope="col" class="procedure-steps__sequence">Sıra</th>
                        <th scope="col">Adım</th>
                        <th scope="col">Medya</th>
                        @if ($editable)
                            <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($phase->steps as $step)
                        <tr id="step-{{ $step->id }}" class="procedure-step">
                            <td class="procedure-steps__sequence">{{ $step->sequence }}.</td>
                            <td>
                                <div class="procedure-step__title">{{ $step->title }}</div>
                                @if ($step->description)
                                    <div class="procedure-step__description">{!! nl2br(e($step->description)) !!}</div>
                                @endif
                            </td>
                            <td>
                                @if ($step->media_path)
                                    @include('admin.procedures.partials.media', ['step' => $step, 'preview' => false])
                                @else
                                    <span class="procedure-step__no-media">—</span>
                                @endif
                            </td>
                            @if ($editable)
                                <td class="text-end text-nowrap procedure-step__actions">
                                    @include('admin.procedures.partials.move', [
                                        'up' => route('admin.procedures.steps.move', [$procedure, $version, $phase, $step, 'up']),
                                        'down' => route('admin.procedures.steps.move', [$procedure, $version, $phase, $step, 'down']),
                                        'first' => $loop->first,
                                        'last' => $loop->last,
                                        'label' => $step->title,
                                    ])
                                    <a href="{{ route('admin.procedures.steps.edit', [$procedure, $version, $phase, $step]) }}" class="btn btn-sm btn-outline-secondary" title="Düzenle" aria-label="{{ $step->title }}: düzenle">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <form method="POST"
                                          action="{{ route('admin.procedures.steps.destroy', [$procedure, $version, $phase, $step]) }}"
                                          class="d-inline"
                                          data-module="confirm-submit submit-once"
                                          data-confirm="“{{ $step->title }}” adımı silinsin mi?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Sil" aria-label="{{ $step->title }}: sil">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($editable)
        <div class="card-footer procedure-phase__footer">
            <a href="{{ route('admin.procedures.steps.create', [$procedure, $version, $phase]) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle" aria-hidden="true"></i> Adım ekle
            </a>
        </div>
    @endif
</section>
