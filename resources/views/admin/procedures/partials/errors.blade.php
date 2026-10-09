{{--
    İş kuralı hataları (ProcedureVersioning) ve alan dışı mesajlar. Parametre: $keys (hata anahtarları).
--}}
@php($messages = collect($keys)->flatMap(fn (string $key) => $errors->get($key)))

@if ($messages->isNotEmpty())
    <div class="alert alert-danger procedure-errors" role="alert">
        @if ($messages->count() === 1)
            {{ $messages->first() }}
        @else
            <ul class="mb-0">
                @foreach ($messages as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
