<?php

namespace App\Models;

use App\Enums\DefinitionChangeAction;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Tanım değişiklik günlüğünün bir satırı (R-49). Yalnızca eklenir; veritabanında trigger'larla
 * da korunur. Satırları RecordsDefinitionChanges trait'i model olaylarından yazar.
 *
 * `fields`: {alan: {old, new, old_label?, new_label?}}. Yabancı anahtarlarda `*_label`,
 * ilişkili tanımın değişiklik anındaki kısa adıdır (ör. hat "IST / H01").
 */
#[Fillable([
    'occurred_at', 'actor_id', 'subject_type', 'subject_id', 'subject_label', 'root_type', 'root_id', 'action', 'fields',
])]
#[WithoutTimestamps]
class DefinitionChange extends Model
{
    /** Günlükteki tanım türleri: saklanan kısa ad → model. */
    public const SUBJECT_TYPES = [
        'facility' => Facility::class,
        'line' => Line::class,
        'machine' => Machine::class,
        'procedure' => Procedure::class,
        'procedure_version' => ProcedureVersion::class,
        'procedure_phase' => ProcedurePhase::class,
        'procedure_step' => ProcedureStep::class,
        'material' => Material::class,
        'work_order' => WorkOrder::class,
        'user' => User::class,
    ];

    public const SUBJECT_LABELS = [
        'facility' => 'Tesis',
        'line' => 'Hat',
        'machine' => 'Makine',
        'procedure' => 'Prosedür',
        'procedure_version' => 'Prosedür versiyonu',
        'procedure_phase' => 'Prosedür fazı',
        'procedure_step' => 'Prosedür adımı',
        'material' => 'Malzeme',
        'work_order' => 'İş emri',
        'user' => 'Kullanıcı',
    ];

    /** Ekrandaki alan adları; aynı alanın türe göre farklı adı TYPE_ATTRIBUTE_LABELS'tadır. */
    private const ATTRIBUTE_LABELS = [
        'code' => 'Kod',
        'name' => 'Ad',
        'facility_id' => 'Tesis',
        'line_id' => 'Hat',
        'machine_id' => 'Makine',
        'procedure_id' => 'Prosedür',
        'is_active' => 'Kullanımda',
        'version' => 'Versiyon',
        'material_required' => 'Malzeme girişi zorunlu',
        'published_at' => 'Yayın tarihi',
        'procedure_version_id' => 'Versiyon',
        'sequence' => 'Sıra',
        'min_duration_seconds' => 'Minimum süre',
        'include_gaps' => 'Adımlar arasındaki boşluklar faz süresine dahil',
        'procedure_phase_id' => 'Faz',
        'title' => 'Başlık',
        'description' => 'Açıklama',
        'media_path' => 'Medya',
        'email' => 'E-posta',
        'email_verified_at' => 'E-posta doğrulama',
        'role' => 'Rol',
    ];

    private const TYPE_ATTRIBUTE_LABELS = [
        'user' => ['name' => 'Ad soyad', 'is_active' => 'Aktif'],
        'procedure_phase' => ['name' => 'Faz adı'],
    ];

    private const DATETIME_ATTRIBUTES = ['published_at', 'email_verified_at'];

    private const DURATION_ATTRIBUTES = ['min_duration_seconds'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Değişiklik günlüğü kayıtları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Değişiklik günlüğü kayıtları silinemez.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'action' => DefinitionChangeAction::class,
            'fields' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Modelin günlükteki tür adı (SUBJECT_TYPES).
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function typeOf(Model|string $model): string
    {
        $type = array_search(is_string($model) ? $model : $model::class, self::SUBJECT_TYPES, true);

        if ($type === false) {
            throw new InvalidArgumentException('Değişiklik günlüğünde olmayan tanım türü: '.(is_string($model) ? $model : $model::class));
        }

        return $type;
    }

    /**
     * Bir tanımın ve alt tanımlarının (ör. prosedürün versiyon, faz ve adımları) değişiklikleri.
     */
    public function scopeOfDefinition(Builder $query, string $type, int $id): void
    {
        $query->where('root_type', $type)->where('root_id', $id);
    }

    public function subjectTypeLabel(): string
    {
        return self::SUBJECT_LABELS[$this->subject_type] ?? $this->subject_type;
    }

    /**
     * İşlemi yapan; oturum yokken (konsol, seed) yapılan değişiklik "Sistem"dir.
     */
    public function actorName(): string
    {
        return $this->actor?->name ?? 'Sistem';
    }

    /**
     * Ekranda gösterilecek alan değişiklikleri; değerler okunur metindir, NULL "boş" demektir.
     * MySQL JSON alan sırasını korumadığı için alanlar ATTRIBUTE_LABELS sırasıyla verilir.
     *
     * @return list<array{attribute: string, label: string, old: ?string, new: ?string}>
     */
    public function diff(): array
    {
        $order = array_flip(array_keys(self::ATTRIBUTE_LABELS));
        $fields = $this->fields ?? [];
        uksort($fields, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        $rows = [];

        foreach ($fields as $attribute => $change) {
            $rows[] = [
                'attribute' => $attribute,
                'label' => self::attributeLabel($this->subject_type, $attribute),
                'old' => self::displayValue($attribute, $change['old'] ?? null, $change['old_label'] ?? null),
                'new' => self::displayValue($attribute, $change['new'] ?? null, $change['new_label'] ?? null),
            ];
        }

        return $rows;
    }

    public static function attributeLabel(string $type, string $attribute): string
    {
        return self::TYPE_ATTRIBUTE_LABELS[$type][$attribute] ?? self::ATTRIBUTE_LABELS[$attribute] ?? $attribute;
    }

    private static function displayValue(string $attribute, mixed $value, ?string $label): ?string
    {
        if ($label !== null) {
            return $label;
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Evet' : 'Hayır',
            $attribute === 'role' => UserRole::tryFrom((string) $value)?->label() ?? (string) $value,
            in_array($attribute, self::DATETIME_ATTRIBUTES, true) => CarbonImmutable::parse($value)
                ->setTimezone(config('app.display_timezone'))
                ->format('d.m.Y H:i'),
            in_array($attribute, self::DURATION_ATTRIBUTES, true) => self::duration((int) $value),
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * <x-duration> ile aynı biçim.
     */
    private static function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return match (true) {
            $hours > 0 => sprintf('%d sa %02d dk', $hours, $minutes),
            $minutes > 0 => sprintf('%d dk %02d sn', $minutes, $rest),
            default => "{$rest} sn",
        };
    }
}
