{{-- Planın kuralı (K-20): "Periyodik · 7 günde bir" ya da "Üretim iş emri tamamlanınca". --}}
@use('App\Enums\CleaningPlanKind')

<span class="cleaning-plan-rule cleaning-plan-rule--{{ str_replace('_', '-', $plan->kind->value) }}">
    {{ $plan->kind->label() }}@if ($plan->kind === CleaningPlanKind::Periodic && $plan->interval_days) · {{ $plan->interval_days }} günde bir @endif
</span>
