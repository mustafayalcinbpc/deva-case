{{--
    Kullanımdan kaldırma ve yeniden kullanıma alma (R-12, K-16, K-18). Kuralın sonucu önceden
    biliniyorsa düğme pasif gösterilir ve nedeni yazılır; asıl karar MachineRetirement'tadır.
--}}
<section id="retirement" class="card machine-retirement" aria-labelledby="retirement-title">
    <div class="card-header">
        <h2 class="card-title" id="retirement-title">Kullanım durumu</h2>
    </div>

    <div class="card-body">
        @error('machine')
            <div class="alert alert-danger" role="alert">{{ $message }}</div>
        @enderror

        @if ($machine->is_active)
            <p>
                Kullanımdan kaldırılan makine silinmez: yeni kayıt formunda seçilemez, geçmiş kayıtlarda ve raporlarda
                görünmeye devam eder. Gerekirse yeniden kullanıma alınabilir.
            </p>

            @if ($openCleanings->isNotEmpty())
                <p id="retire-blocked">
                    Bu makinede {{ $openCleanings->count() }} açık kayıt var
                    ({{ $openCleanings->pluck('record_no')->implode(', ') }}). Kayıtlar tamamlanmadan ya da iptal
                    edilmeden makine kullanımdan kaldırılamaz (K-16).
                </p>
            @endif

            <form method="POST" action="{{ route('admin.machines.retire', $machine) }}"
                  data-module="confirm-submit submit-once"
                  data-confirm="{{ $machine->code }} makinesi kullanımdan kaldırılacak. Yeni kayıtlarda seçilemeyecek. Devam edilsin mi?">
                @csrf
                <button type="submit" class="btn btn-outline-secondary"
                        @disabled($openCleanings->isNotEmpty())
                        @if ($openCleanings->isNotEmpty()) aria-describedby="retire-blocked" @endif>
                    <i class="bi bi-archive" aria-hidden="true"></i> Kullanımdan kaldır
                </button>
            </form>
        @else
            <p>
                Makine kullanımdan kaldırılmış; yeni kayıt formunda görünmüyor. Yeniden kullanıma alındığında
                yeni kayıtlar açılabilir.
            </p>

            @if ($currentVersion === null)
                <p id="reinstate-blocked">
                    Yeniden kullanıma almak için makineye yayımlanmış versiyonu olan bir prosedür atanmalı (K-18).
                    <a href="{{ route('admin.machines.edit', $machine) }}">Makineyi düzenleyin</a>.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.machines.reinstate', $machine) }}" data-module="submit-once">
                @csrf
                <button type="submit" class="btn btn-primary"
                        @disabled($currentVersion === null)
                        @if ($currentVersion === null) aria-describedby="reinstate-blocked" @endif>
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Yeniden kullanıma al
                </button>
            </form>
        @endif
    </div>
</section>
