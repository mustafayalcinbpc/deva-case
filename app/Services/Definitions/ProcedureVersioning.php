<?php

namespace App\Services\Definitions;

use App\Models\Material;
use App\Models\Procedure;
use App\Models\ProcedurePhase;
use App\Models\ProcedureStep;
use App\Models\ProcedureVersion;
use App\Models\ProcedureVersionMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Prosedür tanımları: taslak versiyon, fazlar, adımlar, adım medyası, beklenen malzemeler ve
 * yayımlama (R-01–R-06, R-11, R-13, K-02, K-12, K-13, K-15).
 *
 * - Bir prosedürün aynı anda en fazla bir taslağı olur. Yeni taslak, en son versiyonun
 *   fazlarını, adımlarını (medya yolları dahil) ve beklenen malzemelerini kopyalayarak başlar.
 * - Malzeme zorunluluğu (material_required) beklenen malzemeler listesinden türetilir: listede
 *   zorunlu bir malzeme varsa açıktır. Liste tanımlanmadan önceki versiyonlardan kopyalanan değer,
 *   liste ilk kez değişene kadar korunur.
 * - Yalnızca taslak değişir. Her işlem versiyon satırını kilitler ve taslak olduğunu yeniden
 *   doğrular; böylece yayımlama ile eşzamanlı bir düzenleme yayımlanmış versiyona yazamaz.
 *   Modeller de aynı kuralı LogicException ile korur.
 * - Yayımlama "hemen" ya da ileri bir tarih için yapılır; tarih gelene kadar yeni kayıtlar
 *   önceki versiyonla açılır, açık kayıtlar her zaman kendi versiyonunda kalır.
 *
 * İş kuralı ihlalleri ValidationException olarak döner (form hatası olarak gösterilir).
 */
final class ProcedureVersioning
{
    public const MEDIA_DISK = 'public';

    public const MEDIA_DIRECTORY = 'procedures';

    public const UP = 'up';

    public const DOWN = 'down';

    /**
     * Yeni prosedür, boş bir v1 taslağıyla oluşturulur.
     */
    public function createProcedure(string $code, string $name): Procedure
    {
        return DB::transaction(function () use ($code, $name) {
            $procedure = Procedure::create(['code' => $code, 'name' => $name]);
            $procedure->versions()->create(['version' => 1, 'material_required' => false]);

            return $procedure;
        });
    }

    /**
     * En son versiyonun kopyası olan yeni bir taslak açar (R-11, K-15).
     */
    public function createDraft(Procedure $procedure): ProcedureVersion
    {
        return DB::transaction(function () use ($procedure) {
            $this->lockProcedure($procedure->id);

            $existing = $procedure->versions()->whereNull('published_at')->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'version' => "Bu prosedürün zaten bir taslağı var (v{$existing->version}). Önce onu yayımlayın ya da silin.",
                ]);
            }

            $latest = $procedure->versions()->orderByDesc('version')->with(['phases.steps', 'materials'])->first();

            $draft = $procedure->versions()->create([
                'version' => ($latest?->version ?? 0) + 1,
                'material_required' => $latest?->material_required ?? false,
            ]);

            foreach ($latest?->phases ?? [] as $phase) {
                $copy = $draft->phases()->create($phase->only(['sequence', 'name', 'min_duration_seconds', 'include_gaps']));

                foreach ($phase->steps as $step) {
                    $copy->steps()->create($step->only(['sequence', 'title', 'description', 'media_path']));
                }
            }

            foreach ($latest?->materials ?? [] as $item) {
                $draft->materials()->create($item->only(['material_id', 'sequence', 'is_required']));
            }

            return $draft;
        });
    }

    /**
     * Taslağı fazları ve adımlarıyla siler. Başka bir versiyonun kullanmadığı medya dosyaları da silinir.
     */
    public function deleteDraft(ProcedureVersion $version): void
    {
        $paths = DB::transaction(function () use ($version) {
            $draft = $this->lockDraft($version->id);
            $phases = $draft->phases()->with('steps')->get();

            foreach ($phases as $phase) {
                $phase->steps->each->delete();
                $phase->delete();
            }

            $draft->materials()->get()->each->delete();
            $draft->delete();

            return $phases->flatMap->steps->pluck('media_path')->all();
        });

        $this->deleteUnusedMedia($paths);
    }

    /**
     * K-13: taslağın beklediği malzemeye ekler. Aynı malzeme listede bir kez bulunur;
     * kullanımdan kaldırılmış malzeme eklenemez.
     */
    public function addMaterial(ProcedureVersion $version, Material $material, bool $required): ProcedureVersionMaterial
    {
        return DB::transaction(function () use ($version, $material, $required) {
            $draft = $this->lockDraft($version->id);
            $material->refresh();

            if (! $material->is_active) {
                throw ValidationException::withMessages([
                    'material_id' => "{$material->code} kullanımdan kaldırılmış; listeye eklenemez.",
                ]);
            }

            if ($draft->materials()->where('material_id', $material->id)->exists()) {
                throw ValidationException::withMessages([
                    'material_id' => "{$material->code} bu versiyonun listesinde zaten var.",
                ]);
            }

            $item = $draft->materials()->create([
                'material_id' => $material->id,
                'sequence' => $this->nextSequence($draft->materials()),
                'is_required' => $required,
            ]);

            $this->syncMaterialRequirement($draft);

            return $item;
        });
    }

    /**
     * K-12: malzeme zorunlu ya da isteğe bağlı yapılır.
     */
    public function updateMaterial(ProcedureVersionMaterial $item, bool $required): void
    {
        DB::transaction(function () use ($item, $required) {
            $draft = $this->lockDraft($item->procedure_version_id);
            $item->refresh()->update(['is_required' => $required]);
            $this->syncMaterialRequirement($draft);
        });
    }

    public function removeMaterial(ProcedureVersionMaterial $item): void
    {
        DB::transaction(function () use ($item) {
            $draft = $this->lockDraft($item->procedure_version_id);
            $item->refresh()->delete();
            $this->closeGap($draft->materials(), $item->sequence);
            $this->syncMaterialRequirement($draft);
        });
    }

    public function moveMaterial(ProcedureVersionMaterial $item, string $direction): void
    {
        DB::transaction(function () use ($item, $direction) {
            $draft = $this->lockDraft($item->procedure_version_id);
            $this->move($item->refresh(), $draft->materials(), $direction);
        });
    }

    /**
     * @param  array{name: string, min_duration_seconds: int, include_gaps: bool}  $attributes
     */
    public function addPhase(ProcedureVersion $version, array $attributes): ProcedurePhase
    {
        return DB::transaction(function () use ($version, $attributes) {
            $draft = $this->lockDraft($version->id);

            return $draft->phases()->create($attributes + [
                'sequence' => $this->nextSequence($draft->phases()),
            ]);
        });
    }

    /**
     * @param  array{name: string, min_duration_seconds: int, include_gaps: bool}  $attributes
     */
    public function updatePhase(ProcedurePhase $phase, array $attributes): void
    {
        DB::transaction(function () use ($phase, $attributes) {
            $this->lockDraft($phase->procedure_version_id);
            $phase->refresh()->update($attributes);
        });
    }

    public function deletePhase(ProcedurePhase $phase): void
    {
        $paths = DB::transaction(function () use ($phase) {
            $draft = $this->lockDraft($phase->procedure_version_id);
            $phase->refresh()->load('steps');

            $phase->steps->each->delete();
            $phase->delete();
            $this->closeGap($draft->phases(), $phase->sequence);

            return $phase->steps->pluck('media_path')->all();
        });

        $this->deleteUnusedMedia($paths);
    }

    public function movePhase(ProcedurePhase $phase, string $direction): void
    {
        DB::transaction(function () use ($phase, $direction) {
            $draft = $this->lockDraft($phase->procedure_version_id);
            $this->move($phase->refresh(), $draft->phases(), $direction);
        });
    }

    /**
     * @param  array{title: string, description: ?string}  $attributes
     */
    public function addStep(ProcedurePhase $phase, array $attributes, ?UploadedFile $media = null): ProcedureStep
    {
        $this->assertDraft($phase->version);
        $path = $media !== null ? $this->storeMedia($media) : null;

        try {
            return DB::transaction(function () use ($phase, $attributes, $path) {
                $this->lockDraft($phase->procedure_version_id);

                return $phase->steps()->create($attributes + [
                    'sequence' => $this->nextSequence($phase->steps()),
                    'media_path' => $path,
                ]);
            });
        } catch (Throwable $exception) {
            $this->deleteUnusedMedia([$path]);

            throw $exception;
        }
    }

    /**
     * Yeni medya yüklenirse eskisinin yerine geçer; `$removeMedia` medyayı kaldırır.
     *
     * @param  array{title: string, description: ?string}  $attributes
     */
    public function updateStep(ProcedureStep $step, array $attributes, ?UploadedFile $media = null, bool $removeMedia = false): void
    {
        $this->assertDraft($step->phase->version);
        $path = $media !== null ? $this->storeMedia($media) : null;

        try {
            $previous = DB::transaction(function () use ($step, $attributes, $path, $removeMedia) {
                $this->lockDraft($step->phase->procedure_version_id);
                $step->refresh();
                $previous = $step->media_path;

                if ($path !== null) {
                    $attributes['media_path'] = $path;
                } elseif ($removeMedia) {
                    $attributes['media_path'] = null;
                }

                $step->update($attributes);

                return $previous !== $step->media_path ? $previous : null;
            });
        } catch (Throwable $exception) {
            $this->deleteUnusedMedia([$path]);

            throw $exception;
        }

        $this->deleteUnusedMedia([$previous]);
    }

    public function deleteStep(ProcedureStep $step): void
    {
        $path = DB::transaction(function () use ($step) {
            $this->lockDraft($step->phase->procedure_version_id);
            $step->refresh();

            $step->delete();
            $this->closeGap($step->phase->steps(), $step->sequence);

            return $step->media_path;
        });

        $this->deleteUnusedMedia([$path]);
    }

    public function moveStep(ProcedureStep $step, string $direction): void
    {
        DB::transaction(function () use ($step, $direction) {
            $this->lockDraft($step->phase->procedure_version_id);
            $step->refresh();
            $this->move($step, $step->phase->steps(), $direction);
        });
    }

    /**
     * Taslağı yayımlar: `$at` NULL ise hemen, değilse o anda (UTC) yürürlüğe girer (K-15).
     * En az bir faz olmalı, her fazda en az bir adım bulunmalı ve minimum süreler negatif
     * olmamalı (R-03, R-06). Yayın tarihleri versiyon sırasını izler: yeni versiyon, daha
     * önce yayımlanmış bir versiyonun yayın tarihinden önce yürürlüğe giremez.
     */
    public function publish(ProcedureVersion $version, ?CarbonImmutable $at = null): ProcedureVersion
    {
        return DB::transaction(function () use ($version, $at) {
            $this->lockProcedure($version->procedure_id);
            $draft = $this->lockDraft($version->id);

            $now = CarbonImmutable::now()->startOfSecond();
            $publishAt = $at?->utc()->startOfSecond() ?? $now;
            $errors = [];

            if ($at !== null && $publishAt->lte($now)) {
                $errors['published_at'][] = 'İleri tarihli yayın için şu andan sonraki bir tarih ve saat seçin; hemen yayımlamak için "Hemen" seçeneğini kullanın.';
            }

            $latest = ProcedureVersion::query()
                ->where('procedure_id', $draft->procedure_id)
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->first();

            if ($latest !== null && $publishAt->lt($latest->published_at)) {
                $date = $latest->published_at->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i');
                $errors['published_at'][] = "v{$latest->version} {$date} tarihinde yayına girecek. Yeni versiyon bu tarihten önce yayımlanamaz; daha sonraki bir tarih seçin.";
            }

            $phases = $draft->phases()->withCount('steps')->get();

            if ($phases->isEmpty()) {
                $errors['phases'][] = 'Taslakta faz yok. Yayımlamak için en az bir faz ve adım tanımlayın (R-03).';
            }

            foreach ($phases as $phase) {
                if ($phase->steps_count === 0) {
                    $errors['phases'][] = "“{$phase->name}” fazında adım yok. Her fazda en az bir adım olmalı (R-03).";
                }

                if ($phase->min_duration_seconds < 0) {
                    $errors['phases'][] = "“{$phase->name}” fazının minimum süresi negatif olamaz (R-06).";
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $draft->update(['published_at' => $publishAt]);

            return $draft;
        });
    }

    /**
     * Yalnızca taslak düzenlenebilir (K-15).
     */
    public function assertDraft(ProcedureVersion $version): void
    {
        if (! $version->isDraft()) {
            throw $this->publishedVersion($version);
        }
    }

    /**
     * K-13: liste değişince versiyonun malzeme zorunluluğu listeden yeniden hesaplanır.
     */
    private function syncMaterialRequirement(ProcedureVersion $draft): void
    {
        $required = $draft->materials()->where('is_required', true)->exists();

        if ($draft->material_required !== $required) {
            $draft->update(['material_required' => $required]);
        }
    }

    private function lockProcedure(int $procedureId): void
    {
        Procedure::query()->whereKey($procedureId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Versiyon satırını kilitler ve hâlâ taslak olduğunu doğrular.
     */
    private function lockDraft(int $versionId): ProcedureVersion
    {
        $version = ProcedureVersion::query()->whereKey($versionId)->lockForUpdate()->firstOrFail();
        $this->assertDraft($version);

        return $version;
    }

    private function publishedVersion(ProcedureVersion $version): ValidationException
    {
        return ValidationException::withMessages([
            'version' => "v{$version->version} yayımlanmış; yayımlanmış versiyon değiştirilemez. Değişiklik için yeni taslak oluşturun (K-15).",
        ]);
    }

    private function nextSequence(HasMany $siblings): int
    {
        return (int) $siblings->max('sequence') + 1;
    }

    /**
     * Faz ya da adımı bir üst/alt komşusuyla yer değiştirir. Uçtaysa bir şey yapmaz.
     */
    private function move(Model $item, HasMany $siblings, string $direction): void
    {
        $neighbour = match ($direction) {
            self::UP => $siblings->where('sequence', '<', $item->sequence)->reorder('sequence', 'desc')->first(),
            self::DOWN => $siblings->where('sequence', '>', $item->sequence)->reorder('sequence')->first(),
            default => throw new InvalidArgumentException("Geçersiz yön: {$direction}"),
        };

        if ($neighbour === null) {
            return;
        }

        [$itemSequence, $neighbourSequence] = [$item->sequence, $neighbour->sequence];

        // (…, sequence) benzersizdir: önce geçici bir değere alınır. Geçici değer modeli
        // değiştirmeden sorguyla yazılır; değişiklik günlüğünde yalnızca gerçek yer değişikliği görünür.
        $item->newQuery()->whereKey($item->getKey())->update(['sequence' => 0]);
        $neighbour->update(['sequence' => $itemSequence]);
        $item->update(['sequence' => $neighbourSequence]);
    }

    /**
     * Silinen öğeden sonrakileri birer öne alır (sıra 1..N kalır).
     */
    private function closeGap(HasMany $siblings, int $removedSequence): void
    {
        $siblings->where('sequence', '>', $removedSequence)
            ->reorder('sequence')
            ->get()
            ->each(fn (Model $sibling) => $sibling->update(['sequence' => $sibling->sequence - 1]));
    }

    private function storeMedia(UploadedFile $file): string
    {
        $path = $file->store(self::MEDIA_DIRECTORY, self::MEDIA_DISK);

        if ($path === false) {
            throw new RuntimeException('Medya dosyası kaydedilemedi.');
        }

        return $path;
    }

    /**
     * Hiçbir adımın (taslak kopyaları ve yayımlanmış versiyonlar dahil) kullanmadığı medya
     * dosyalarını siler. Yalnızca bu ekranın yüklediği klasöre dokunulur.
     *
     * @param  array<?string>  $paths
     */
    private function deleteUnusedMedia(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if (str_starts_with($path, self::MEDIA_DIRECTORY.'/') && ! ProcedureStep::query()->where('media_path', $path)->exists()) {
                Storage::disk(self::MEDIA_DISK)->delete($path);
            }
        }
    }
}
