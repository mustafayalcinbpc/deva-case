{{-- Seçilen makinenin prosedür özeti (K-15, K-18) ve aynı makinedeki başlamamış kayıtlar (K-05). --}}
@php($version = $machine->currentVersion)

<div class="machine-summary" data-machine-summary="{{ $machine->id }}" hidden>
    <dl class="machine-summary__facts">
        <div class="machine-summary__fact">
            <dt>Prosedür</dt>
            <dd>{{ $machine->procedure->name }} <span class="machine-summary__code">{{ $machine->procedure->code }}</span></dd>
        </div>
        <div class="machine-summary__fact">
            <dt>Versiyon</dt>
            <dd>v{{ $version->version }}</dd>
        </div>
        <div class="machine-summary__fact">
            <dt>Kapsam</dt>
            <dd>{{ $version->phases->count() }} faz, {{ $version->phases->sum('steps_count') }} adım</dd>
        </div>
        <div class="machine-summary__fact">
            <dt>Malzeme</dt>
            <dd>{{ $version->material_required ? 'Zorunlu: ilk adım malzeme girilmeden başlatılamaz' : 'Zorunlu değil' }}</dd>
        </div>
    </dl>

    <ol class="machine-summary__phases">
        @foreach ($version->phases as $phase)
            <li class="machine-summary__phase">
                <span class="machine-summary__phase-name">{{ $phase->name }}</span>
                <span class="machine-summary__phase-meta">
                    {{ $phase->steps_count }} adım ·
                    @if ($phase->min_duration_seconds > 0)
                        en az <x-duration :seconds="$phase->min_duration_seconds" />
                    @else
                        minimum süre yok
                    @endif
                </span>
            </li>
        @endforeach
    </ol>

    @if ($machine->pendingCleanings->isNotEmpty())
        <div class="alert alert-warning machine-summary__pending" role="note">
            <p class="machine-summary__pending-text">
                Bu makinede henüz başlamamış {{ $machine->pendingCleanings->count() }} kayıt var. Kayıt açmak makineyi
                kilitlemez; yine de aynı temizlik için ikinci bir kayıt açmadığınızdan emin olun.
            </p>
            <ul class="machine-summary__pending-list mb-0">
                @foreach ($machine->pendingCleanings as $pending)
                    <li>
                        <a href="{{ route('cleanings.show', $pending) }}" class="record-no">{{ $pending->record_no }}</a>
                        — {{ $pending->owner->name }}, <x-datetime :value="$pending->created_at" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
