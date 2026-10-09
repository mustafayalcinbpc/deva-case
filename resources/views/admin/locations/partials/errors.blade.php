{{-- Form hataları özeti; alan hataları ayrıca alanın altında gösterilir. --}}
@if ($errors->any())
    <div class="alert alert-danger definition-form__errors" role="alert">
        <p class="mb-1">Kaydedilemedi; aşağıdaki alanları kontrol edin:</p>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
