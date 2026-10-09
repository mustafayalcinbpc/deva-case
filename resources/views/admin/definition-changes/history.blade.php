{{--
    <x-definition-history> görünümü: tanımın son değişiklikleri ve günlüğün bu tanıma süzülmüş hali.
    Alt tanımın (ör. prosedürde versiyon, faz, adım) değişikliğinde hangi tanımın değiştiği de yazılır.
--}}
<section {{ $attributes->class(['card', 'definition-history']) }} aria-labelledby="definition-history-title">
    <div class="card-header">
        <h2 class="card-title" id="definition-history-title">Değişiklik geçmişi</h2>
    </div>

    @if ($changes->isEmpty())
        <div class="card-body">
            <p class="empty-state mb-0">Kayıtlı değişiklik yok.</p>
        </div>
    @else
        <ol class="list-group list-group-flush definition-history__list">
            @foreach ($changes as $change)
                <li class="list-group-item definition-history__item" id="definition-history-{{ $change->id }}">
                    <div class="definition-history__meta">
                        <x-datetime :value="$change->occurred_at" format="d.m.Y H:i" />
                        ·
                        <span @class(['definition-change__actor', 'definition-change__actor--system' => $change->actor_id === null])>{{ $change->actorName() }}</span>
                    </div>
                    <div class="definition-history__summary">
                        @include('admin.definition-changes.action', ['action' => $change->action])
                        @unless ($change->subject_type === $type && (int) $change->subject_id === $id)
                            <span class="definition-change__type">{{ $change->subjectTypeLabel() }}</span>
                            @if ($links[$change->id] ?? null)
                                <a href="{{ $links[$change->id] }}" class="definition-change__label">{{ $change->subject_label }}</a>
                            @else
                                <span class="definition-change__label">{{ $change->subject_label }}</span>
                            @endif
                        @endunless
                    </div>
                    @include('admin.definition-changes.diff', ['change' => $change])
                </li>
            @endforeach
        </ol>
    @endif

    <div class="card-footer definition-history__footer">
        <a href="{{ $url }}" class="definition-history__more">
            <i class="bi bi-clock-history" aria-hidden="true"></i> Değişiklik günlüğünde gör ({{ $total }})
        </a>
    </div>
</section>
