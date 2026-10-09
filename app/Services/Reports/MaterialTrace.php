<?php

namespace App\Services\Reports;

use App\Models\CleaningMaterial;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Malzeme izlenebilirliği (R-10): "bu malzeme / bu lot hangi temizliklerde kullanılmış?"
 * Geçersiz kılınan girişler de gösterilir (K-12); ilk kayıt silinmez, gerekçesiyle görünür.
 * Geriye dönük bir sorgu olduğu için tarih aralığı uygulanmaz.
 */
final class MaterialTrace
{
    /**
     * Lot numarası içeren arama yapılır (büyük/küçük harf duyarsız, veritabanı karşılaştırmasıyla).
     *
     * @return LengthAwarePaginator<int, CleaningMaterial>
     */
    public function search(?int $materialId, ?string $lotNo, int $perPage): LengthAwarePaginator
    {
        $results = CleaningMaterial::query()
            ->join('cleanings as c', 'c.id', '=', 'cleaning_materials.cleaning_id')
            ->select('cleaning_materials.*')
            ->when($materialId, fn ($query, int $id) => $query->where('cleaning_materials.material_id', $id))
            ->when($lotNo, fn ($query, string $lot) => $query->where('cleaning_materials.lot_no', 'like', '%'.$this->escapeLike($lot).'%'))
            ->with(['material', 'cleaning.facility', 'cleaning.line', 'cleaning.machine', 'cleaning.owner'])
            ->orderByDesc('c.created_at')
            ->orderByDesc('cleaning_materials.id')
            ->paginate($perPage)
            ->withQueryString();

        $users = $this->users($results->getCollection());

        $results->getCollection()->each(fn (CleaningMaterial $item) => $item
            ->setRelation('addedBy', $users->get((int) $item->added_by))
            ->setRelation('voidedBy', $item->voided_by === null ? null : $users->get((int) $item->voided_by)));

        return $results;
    }

    /**
     * @param  Collection<int, CleaningMaterial>  $items
     * @return Collection<int, User>
     */
    private function users(Collection $items): Collection
    {
        $ids = $items->flatMap(fn (CleaningMaterial $item) => [$item->added_by, $item->voided_by])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? new Collection
            : User::query()->whereIn('id', $ids)->get()->keyBy('id')->toBase();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
