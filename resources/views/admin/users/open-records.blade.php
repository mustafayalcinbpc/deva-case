{{--
    Açık kayıt listesi (kullanıcı düzenleme). $records: facility, line, machine ve owner yüklü
    Cleaning listesi; $anchor: kayıt bağlantısına eklenecek çapa (ör. #cancel); $class: liste sınıfı.
--}}
<ul class="list-unstyled user-open-records {{ $class }}">
    @foreach ($records as $cleaning)
        <li class="user-open-records__item">
            <a href="{{ route('cleanings.show', $cleaning) }}{{ $anchor }}" class="record-no">{{ $cleaning->record_no }}</a>
            <x-status-badge :status="$cleaning->status" />
            <div class="user-open-records__meta">
                <span title="{{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</span>
                · Sorumlu: {{ $cleaning->owner->name }}
                · Açılış: <x-datetime :value="$cleaning->created_at" />
            </div>
        </li>
    @endforeach
</ul>
