{{-- Alan hataları özeti; iş kuralı ihlali (workflow) flash partial'da ayrıca gösterilir. --}}
@php($fieldErrors = collect($errors->getMessages())->except('workflow')->flatten())

@if ($fieldErrors->isNotEmpty())
    <div class="alert alert-danger cleaning-form__errors" role="alert">
        <p class="cleaning-form__errors-title">Kayıt açılamadı; aşağıdaki alanları kontrol edin:</p>
        <ul class="mb-0">
            @foreach ($fieldErrors as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
