@php
    $diff = $change->diff();
    $action = $change->action;
@endphp

{{--
    Bir değişikliğin alanları: oluşturmada yeni değerler, silmede son değerler, diğerlerinde
    eski → yeni. Şifre değeri günlüğe hiç yazılmaz; şifre sıfırlama yalnızca işlem olarak görünür.
--}}
@if ($action === \App\Enums\DefinitionChangeAction::PasswordReset)
    <p class="definition-change-diff__note mb-0">Şifre değeri günlüğe yazılmaz.</p>
@elseif ($diff === [])
    <span class="definition-change-diff__none">—</span>
@else
    <ul class="list-unstyled mb-0 definition-change-diff">
        @foreach ($diff as $row)
            <li class="definition-change-diff__item" data-attribute="{{ $row['attribute'] }}">
                <span class="definition-change-diff__attribute">{{ $row['label'] }}:</span>
                @if ($action === \App\Enums\DefinitionChangeAction::Created)
                    <span class="definition-change-diff__new">{{ $row['new'] ?? '—' }}</span>
                @elseif ($action === \App\Enums\DefinitionChangeAction::Deleted)
                    <span class="definition-change-diff__old">{{ $row['old'] ?? '—' }}</span>
                @else
                    <del class="definition-change-diff__old"><span class="visually-hidden">eski değer </span>{{ $row['old'] ?? '—' }}</del>
                    <span class="definition-change-diff__arrow" aria-hidden="true">→</span>
                    <ins class="definition-change-diff__new"><span class="visually-hidden">yeni değer </span>{{ $row['new'] ?? '—' }}</ins>
                @endif
            </li>
        @endforeach
    </ul>
@endif
