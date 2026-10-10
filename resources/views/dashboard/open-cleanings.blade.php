{{--
    Açık (başlamamış ve devam eden) kayıtlar. Herkes görür, bu tabloda işlem yapılmaz (K-11).
    Kullanıcının sahibi olduğu ya da güncel adımında görevli olduğu satırlar işaretlenir (R-44).
--}}
<section class="card open-cleanings" aria-labelledby="open-cleanings-title">
    <div class="card-header">
        <h2 class="card-title" id="open-cleanings-title">Açık kayıtlar</h2>
        <span class="tag tag-neutral open-cleanings__count" title="Açık kayıt sayısı">{{ $rows->count() }}</span>
        <a href="{{ route('cleanings.index') }}" class="open-cleanings__all">Tümünü gör</a>
    </div>

    @if ($rows->isEmpty())
        <div class="card-body">
            <p class="empty-state mb-0">Şu anda açık temizlik kaydı yok.</p>
        </div>
    @else
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 open-cleanings__table">
                    <thead>
                        <tr>
                            <th scope="col">Kayıt no</th>
                            <th scope="col">Konum</th>
                            <th scope="col">Tür</th>
                            <th scope="col">Sorumlu</th>
                            <th scope="col">Durum</th>
                            <th scope="col">Açılış</th>
                            <th scope="col">Başlangıç</th>
                            <th scope="col">Güncel adım</th>
                            <th scope="col" title="Adımlarda çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz">Net süre</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $cleaning = $row['cleaning'];
                                $step = $row['step'];
                            @endphp
                            <tr @class(['open-cleanings__row', 'open-cleanings__row--mine' => $row['mine'] !== null])>
                                <td>
                                    <a href="{{ route('cleanings.show', $cleaning) }}" class="record-no text-nowrap">{{ $cleaning->record_no }}</a>
                                    @if ($row['mine'] !== null)
                                        <span class="mine-badge" title="{{ $row['mine'] === 'owner' ? 'Bu kaydın sorumlususunuz' : 'Güncel adımda görevlisiniz' }}">Bana ait</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    <span class="location" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }} / {{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</span>
                                </td>
                                <td>{{ $cleaning->type->label() }}</td>
                                <td>{{ $cleaning->owner->name }}</td>
                                <td><x-status-badge :status="$cleaning->status" /></td>
                                <td class="text-nowrap"><x-datetime :value="$cleaning->created_at" format="list" /></td>
                                <td class="text-nowrap"><x-datetime :value="$cleaning->started_at" format="list" /></td>
                                <td class="current-step">
                                    @if ($step)
                                        <span class="current-step__title">{{ $step->procedureStep->title }}</span>
                                        <x-status-badge :status="$step->status" />
                                    @else
                                        <span class="text-body-secondary">—</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($row['netSeconds'] === null)
                                        <span class="text-body-secondary">—</span>
                                    @else
                                        <x-duration :seconds="$row['netSeconds']" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
