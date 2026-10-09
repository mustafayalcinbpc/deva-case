{{-- Değişiklik günlüğündeki işlem etiketi; renkler tema katmanından (definition-change-action--*). --}}
<span class="status-badge definition-change-action definition-change-action--{{ str_replace('_', '-', $action->value) }}">{{ $action->label() }}</span>
