{{--
    Raporların ortak filtresi: tarih aralığı (gösterim saat diliminde gün olarak), tesis, hat,
    makine ve temizlik tipi. Geçersiz değerler yok sayılır; tarih verilmezse son 30 gün.
    Değişkenler: $filters (ReportFilters), $options (ReportFilterOptions::all()), $action,
    $dateHint (tarih aralığının hangi zamana uygulandığı).
--}}
<section class="card mb-4 report-filters" aria-labelledby="report-filters-title">
    <div class="card-header">
        <h2 class="card-title" id="report-filters-title">Filtre</h2>
    </div>

    <div class="card-body">
        <form method="GET" action="{{ $action }}" class="row g-3 align-items-end" role="search" aria-label="Raporu filtrele">
            <div class="col-sm-6 col-lg-4 col-xl-2">
                <label for="filter-from" class="form-label">Başlangıç tarihi</label>
                <input type="date" id="filter-from" name="from" value="{{ $filters->from->format('Y-m-d') }}" class="form-control">
            </div>

            <div class="col-sm-6 col-lg-4 col-xl-2">
                <label for="filter-to" class="form-label">Bitiş tarihi</label>
                <input type="date" id="filter-to" name="to" value="{{ $filters->to->format('Y-m-d') }}" class="form-control">
            </div>

            <div class="col-sm-6 col-lg-4 col-xl-2">
                <label for="filter-type" class="form-label">Tür</label>
                <select id="filter-type" name="type" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($options['types'] as $type)
                        <option value="{{ $type->value }}" @selected($filters->type === $type)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-lg-4 col-xl-2">
                <label for="filter-facility" class="form-label">Tesis</label>
                <select id="filter-facility" name="facility_id" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($options['facilities'] as $facility)
                        <option value="{{ $facility->id }}" @selected($filters->facilityId === $facility->id)>{{ $facility->code }} — {{ $facility->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-lg-4 col-xl-2">
                <label for="filter-line" class="form-label">Hat</label>
                <select id="filter-line" name="line_id" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($options['lineGroups'] as $facilityName => $lines)
                        <optgroup label="{{ $facilityName }}">
                            @foreach ($lines as $line)
                                <option value="{{ $line->id }}" @selected($filters->lineId === $line->id)>{{ $line->code }} — {{ $line->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-lg-4 col-xl-2">
                <label for="filter-machine" class="form-label">Makine</label>
                <select id="filter-machine" name="machine_id" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($options['machineGroups'] as $location => $machines)
                        <optgroup label="{{ $location }}">
                            @foreach ($machines as $option)
                                <option value="{{ $option->id }}" @selected($filters->machineId === $option->id)>
                                    {{ $option->code }} — {{ $option->name }}{{ $option->is_active ? '' : ' (kullanımdan kaldırıldı)' }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel" aria-hidden="true"></i> Filtrele
                </button>
                <a href="{{ $action }}" class="btn btn-outline-secondary">Filtreyi temizle</a>
            </div>
        </form>

        <p class="form-text mb-0 mt-3">
            {{ $dateHint }} Tarihler {{ config('app.display_timezone') }} saatine göre gün olarak alınır; boş bırakılırsa son {{ \App\Services\Reports\ReportFilters::DEFAULT_DAYS }} gün.
        </p>
    </div>
</section>
