<?php

namespace App\Models\Concerns;

use App\Enums\DefinitionChangeAction;
use App\Models\DefinitionChange;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Tanımın her değişikliğini definition_changes günlüğüne yazar (R-49): kim, ne zaman, hangi alan
 * neyken ne oldu. Model olaylarından yazıldığı için değişikliğin ekrandan, servisten ya da
 * seed'den gelmesi fark etmez; aynı transaction'da yazılır, işlem geri alınırsa satır da gider.
 *
 * - Şifre ve "beni hatırla" anahtarının değeri hiçbir zaman yazılmaz; şifre değişikliği yalnızca
 *   işlem olarak kaydedilir. Yalnızca oturum anahtarı ya da zaman damgası değiştiyse satır oluşmaz.
 * - İş anlamı olan alan değişiklikleri (kullanımdan kaldırma, yayımlama, pasife alma, şifre
 *   sıfırlama) kendi işlem adıyla ayrı satırdır (definitionChangeActions()); kalan alanlar
 *   "güncellendi" satırıdır.
 * - Yabancı anahtarlarda ilişkili tanımın o anki kısa adı da saklanır (definitionChangeReferences()).
 * - İşlemi yapan oturumdaki kullanıcıdır; oturum yoksa (konsol, seed) NULL, ekranda "Sistem".
 *
 * Sorgu oluşturucuyla yapılan toplu güncellemeler model olayı üretmez; tanımlar bu yüzden
 * modeller üzerinden değiştirilir.
 */
trait RecordsDefinitionChanges
{
    /** Günlüğe yazılmayan alanlar. */
    private const DEFINITION_CHANGE_NOISE = ['id', 'created_at', 'updated_at'];

    /** Değeri hiçbir zaman yazılmayan alanlar. */
    private const DEFINITION_CHANGE_SECRETS = ['password', 'remember_token'];

    public static function bootRecordsDefinitionChanges(): void
    {
        static::created(fn (self $model) => $model->recordDefinitionCreated());
        static::updated(fn (self $model) => $model->recordDefinitionUpdated());
        static::deleted(fn (self $model) => $model->recordDefinitionDeleted());
    }

    /**
     * Günlükte tanımın kısa adı (ör. makine konumu, kullanıcı e-postası); değişiklik anındaki
     * hali saklanır, tanım sonradan değişse de satırda o an görünen ad kalır.
     */
    abstract public function definitionChangeLabel(): string;

    /**
     * Değişikliğin geçmişinde göründüğü tanım: varsayılan olarak kendisi. Versiyon, faz ve adım
     * prosedürün geçmişinde görünür.
     */
    public function definitionChangeRoot(): Model
    {
        return $this;
    }

    /**
     * Günlüğe bir satır yazar. Model olayları bunu kendisi çağırır; alan değişikliğinden
     * çıkarılamayan bir işlem servisten de doğrudan kaydedilebilir.
     *
     * @param  array<string, array<string, mixed>>|null  $fields  alan => {old, new, old_label?, new_label?}
     */
    public function recordDefinitionChange(DefinitionChangeAction $action, ?array $fields = null): DefinitionChange
    {
        $root = $this->definitionChangeRoot();

        return DefinitionChange::create([
            'occurred_at' => CarbonImmutable::now(),
            'actor_id' => Auth::id(),
            'subject_type' => DefinitionChange::typeOf($this),
            'subject_id' => $this->getKey(),
            'subject_label' => Str::limit($this->definitionChangeLabel(), 250),
            'root_type' => DefinitionChange::typeOf($root),
            'root_id' => $root->getKey(),
            'action' => $action,
            'fields' => $fields === [] ? null : $fields,
        ]);
    }

    /**
     * İş anlamı olan alanlar: alan → fn (eski, yeni) => işlem; NULL dönerse "güncellendi" sayılır.
     * Gizli alanlarda (şifre) eski ve yeni değer verilmez.
     *
     * @return array<string, Closure(mixed, mixed): ?DefinitionChangeAction>
     */
    protected function definitionChangeActions(): array
    {
        return [];
    }

    /**
     * Yabancı anahtar alanları ve modelleri: günlükte kimliğin yanında kısa adı da saklanır.
     *
     * @return array<string, class-string<Model>>
     */
    protected function definitionChangeReferences(): array
    {
        return [];
    }

    /**
     * Yeni tanımın yazılan hali; veritabanı varsayılanları (ör. is_active) da dahil olsun diye
     * satır yeniden okunur.
     */
    private function recordDefinitionCreated(): void
    {
        $stored = $this->newQueryWithoutScopes()->find($this->getKey()) ?? $this;
        $changes = [];

        foreach (array_keys($stored->getAttributes()) as $key) {
            if ($this->tracksDefinitionAttribute($key) && ($value = $stored->getAttributeValue($key)) !== null) {
                $changes[$key] = $this->definitionChangeEntry($key, null, $value);
            }
        }

        $this->recordDefinitionChange(DefinitionChangeAction::Created, $changes);
    }

    private function recordDefinitionUpdated(): void
    {
        $actions = $this->definitionChangeActions();
        $updated = [];
        $rows = [];

        foreach (array_keys($this->getChanges()) as $key) {
            if (in_array($key, self::DEFINITION_CHANGE_NOISE, true)) {
                continue;
            }

            $secret = in_array($key, self::DEFINITION_CHANGE_SECRETS, true);
            $old = $secret ? null : $this->getOriginal($key);
            $new = $secret ? null : $this->getAttributeValue($key);
            $action = isset($actions[$key]) ? $actions[$key]($old, $new) : null;

            if ($secret) {
                if ($action !== null) {
                    $rows[] = [$action, null];
                }

                continue;
            }

            $entry = $this->definitionChangeEntry($key, $old, $new);

            if ($action !== null) {
                $rows[] = [$action, [$key => $entry]];
            } else {
                $updated[$key] = $entry;
            }
        }

        if ($updated !== []) {
            array_unshift($rows, [DefinitionChangeAction::Updated, $updated]);
        }

        foreach ($rows as [$action, $changes]) {
            $this->recordDefinitionChange($action, $changes);
        }
    }

    /**
     * Silinen tanımın son hali "eski" değer olarak saklanır.
     */
    private function recordDefinitionDeleted(): void
    {
        $changes = [];

        foreach (array_keys($this->getAttributes()) as $key) {
            if ($this->tracksDefinitionAttribute($key) && ($value = $this->getAttributeValue($key)) !== null) {
                $changes[$key] = $this->definitionChangeEntry($key, $value, null);
            }
        }

        $this->recordDefinitionChange(DefinitionChangeAction::Deleted, $changes);
    }

    private function tracksDefinitionAttribute(string $key): bool
    {
        return ! in_array($key, self::DEFINITION_CHANGE_NOISE, true)
            && ! in_array($key, self::DEFINITION_CHANGE_SECRETS, true);
    }

    /**
     * @return array{old: mixed, new: mixed, old_label?: string, new_label?: string}
     */
    private function definitionChangeEntry(string $key, mixed $old, mixed $new): array
    {
        $reference = $this->definitionChangeReferences()[$key] ?? null;
        $entry = [
            'old' => self::definitionChangeValue($old, $reference !== null),
            'new' => self::definitionChangeValue($new, $reference !== null),
        ];

        if ($reference !== null) {
            foreach (['old', 'new'] as $side) {
                $label = $entry[$side] === null ? null : $reference::query()->find($entry[$side])?->definitionChangeLabel();

                if ($label !== null) {
                    $entry["{$side}_label"] = $label;
                }
            }
        }

        return $entry;
    }

    /**
     * JSON'a yazılabilir değer: enum değeri, UTC ISO 8601 zaman, yabancı anahtarda tamsayı.
     */
    private static function definitionChangeValue(mixed $value, bool $reference): mixed
    {
        return match (true) {
            $value === null => null,
            $reference => (int) $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc()->toIso8601String(),
            is_scalar($value) => $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
