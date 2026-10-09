@props(['status'])

{{-- CleaningStatus, PhaseStatus ya da StepStatus; renkler theme/_tokens.scss içinde. --}}
<span {{ $attributes->class(['status-badge', 'status-badge--'.str_replace('_', '-', $status->value)]) }}>{{ $status->label() }}</span>
