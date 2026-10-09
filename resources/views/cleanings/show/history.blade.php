{{--
    Olay geçmişi (R-45–R-49): kayıt üzerinde kim, ne zaman, ne yaptı. Olaylar değiştirilemez bir
    zincirdir; her olay bir öncekinin özetini (hash) içerir. Zincir baştan hesaplanarak doğrulanır.
--}}
<section id="history" class="card event-history" aria-labelledby="history-title">
    <div class="card-header">
        <h2 class="card-title" id="history-title">Olay geçmişi</h2>
    </div>

    <div class="card-body">
        @if ($chainIntact)
            <p class="event-history__integrity event-history__integrity--verified" role="status">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <strong>Kayıt bütünlüğü doğrulandı.</strong>
                Olaylar oluştukları andan beri değiştirilmemiş, silinmemiş ve araya olay eklenmemiş.
            </p>
        @else
            <div class="alert alert-danger event-history__integrity event-history__integrity--broken" role="alert">
                <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                <strong>Kayıt bütünlüğü doğrulanamadı.</strong>
                Olay zinciri bozuk: bir olay sonradan değiştirilmiş, silinmiş ya da araya eklenmiş olabilir.
                Bu kaydı denetimde kullanmadan önce sistem yöneticisine bildirin.
            </div>
        @endif

        <ol class="event-history__list">
            @foreach ($history as $entry)
                <li @class([
                    'event-history__item',
                    'event-history__item--'.str_replace(['.', '_'], '-', $entry['event']->type),
                    'event-history__item--system' => $entry['system'],
                ])>
                    <div class="event-history__meta">
                        <span class="event-history__sequence">#{{ $entry['event']->sequence }}</span>
                        <x-datetime :value="$entry['event']->occurred_at" format="d.m.Y H:i:s" class="event-history__time" />
                        <span class="event-history__actor">{{ $entry['actor'] }}</span>
                    </div>

                    <p class="event-history__title">{{ $entry['title'] }}</p>

                    @if ($entry['details'] !== [])
                        <dl class="event-history__details">
                            @foreach ($entry['details'] as $detail)
                                <div class="event-history__detail">
                                    <dt>{{ $detail['label'] }}</dt>
                                    <dd>
                                        @isset($detail['seconds'])
                                            <x-duration :seconds="$detail['seconds']" />
                                        @else
                                            {{ $detail['value'] }}
                                        @endisset
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
</section>
