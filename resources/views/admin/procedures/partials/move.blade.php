{{--
    Sıra değiştirme düğmeleri (JS gerektirmez). Parametreler: $up, $down (form adresleri),
    $first, $last (uçtaki düğme pasif), $label (ekran okuyucu için öğe adı).
--}}
<form method="POST" action="{{ $up }}" class="d-inline procedure-move">
    @csrf
    <button type="submit" class="btn btn-sm btn-outline-secondary" @disabled($first) title="Yukarı taşı" aria-label="{{ $label }}: yukarı taşı">
        <i class="bi bi-arrow-up" aria-hidden="true"></i>
    </button>
</form>
<form method="POST" action="{{ $down }}" class="d-inline procedure-move">
    @csrf
    <button type="submit" class="btn btn-sm btn-outline-secondary" @disabled($last) title="Aşağı taşı" aria-label="{{ $label }}: aşağı taşı">
        <i class="bi bi-arrow-down" aria-hidden="true"></i>
    </button>
</form>
