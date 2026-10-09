@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@error('workflow')
    <div class="alert alert-danger" role="alert">{{ $message }}</div>
@enderror
