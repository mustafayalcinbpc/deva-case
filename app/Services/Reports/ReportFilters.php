<?php

namespace App\Services\Reports;

use App\Enums\CleaningType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Raporların ortak filtreleri: tarih aralığı, tesis / hat / makine ve temizlik tipi.
 *
 * Tarihler gösterim saat diliminde (config('app.display_timezone')) gün olarak yorumlanır:
 * "09.10.2026" İstanbul'da 00:00'dan ertesi gün 00:00'a kadardır. Veritabanı zamanları UTC
 * saklandığı için sorguda bu sınırlar uygulama saat dilimine çevrilir. Geçersiz değerler yok
 * sayılır; tarih verilmezse son 30 gün (bugün dahil) kullanılır.
 */
final class ReportFilters
{
    public const DEFAULT_DAYS = 30;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $facilityId = null,
        public readonly ?int $lineId = null,
        public readonly ?int $machineId = null,
        public readonly ?CleaningType $type = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->query());
    }

    /**
     * @param  array<string, mixed>  $input  sorgu dizesi ya da kuyruk işinin parametreleri
     */
    public static function fromArray(array $input): self
    {
        $timezone = config('app.display_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $to = self::date($input['to'] ?? null, $timezone) ?? $today;
        $from = self::date($input['from'] ?? null, $timezone) ?? $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $type = $input['type'] ?? null;

        return new self(
            $from,
            $to,
            self::id($input['facility_id'] ?? null),
            self::id($input['line_id'] ?? null),
            self::id($input['machine_id'] ?? null),
            is_string($type) ? CleaningType::tryFrom($type) : null,
        );
    }

    /**
     * Aralığın başlangıcı (dahil), veritabanı saat diliminde.
     */
    public function start(): CarbonImmutable
    {
        return $this->from->setTimezone(config('app.timezone'));
    }

    /**
     * Aralığın sonu (hariç): bitiş gününden sonraki gün 00:00, veritabanı saat diliminde.
     */
    public function end(): CarbonImmutable
    {
        return $this->to->addDay()->setTimezone(config('app.timezone'));
    }

    /**
     * Konum ve tip filtreleri, verilen tablo takma adındaki temizlik kaydına uygulanır. Kayıt
     * açıldığı andaki tesis ve hattı saklar; filtre de onlara bakar.
     */
    public function applyToCleanings(Builder $query, string $alias = 'cleanings'): void
    {
        $query
            ->when($this->facilityId, fn (Builder $query, int $id) => $query->where("{$alias}.facility_id", $id))
            ->when($this->lineId, fn (Builder $query, int $id) => $query->where("{$alias}.line_id", $id))
            ->when($this->machineId, fn (Builder $query, int $id) => $query->where("{$alias}.machine_id", $id))
            ->when($this->type, fn (Builder $query, CleaningType $type) => $query->where("{$alias}.type", $type->value));
    }

    /**
     * Tarih aralığını verilen zaman sütununa uygular: [başlangıç, bitiş).
     */
    public function applyDateRange(Builder $query, string $column): void
    {
        $query
            ->where($column, '>=', $this->start()->format('Y-m-d H:i:s'))
            ->where($column, '<', $this->end()->format('Y-m-d H:i:s'));
    }

    public function withMachine(?int $machineId): self
    {
        return new self($this->from, $this->to, $this->facilityId, $this->lineId, $machineId, $this->type);
    }

    /**
     * Sorgu dizesi ve kuyruk işi parametreleri; boş filtreler yazılmaz.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'facility_id' => $this->facilityId,
            'line_id' => $this->lineId,
            'machine_id' => $this->machineId,
            'type' => $this->type?->value,
        ], fn ($value) => $value !== null);
    }

    /**
     * Ekranda ve dosya adında aralığın okunur hali.
     */
    public function periodLabel(): string
    {
        return $this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y');
    }

    private static function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
