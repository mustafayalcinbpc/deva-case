<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\Procedure;
use App\Models\ProcedurePhase;
use App\Models\ProcedureVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Adım medyası (R-05): JPG/PNG/WEBP görsel ya da MP4/WEBM video, `public` diskte
 * procedures/ altında. Yeni dosya eskisinin yerine geçer; kaldırılan ya da silinen medya,
 * başka bir versiyonun adımı kullanmıyorsa diskten silinir.
 */
class StepMediaTest extends ProcedureTestCase
{
    private Procedure $procedure;

    private ProcedureVersion $draftVersion;

    private ProcedurePhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->procedure = $this->procedure();
        $this->draftVersion = $this->draft($this->procedure, [['steps' => 1]]);
        $this->phase = $this->draftVersion->phases()->first();
    }

    public function test_image_and_video_are_stored_on_the_public_disk(): void
    {
        $this->post($this->storeUrl(), ['title' => 'Nozülleri sök', 'media' => UploadedFile::fake()->create('sokme.jpg', 300, 'image/jpeg')])
            ->assertSessionHasNoErrors();
        $this->post($this->storeUrl(), ['title' => 'Yıka', 'media' => UploadedFile::fake()->create('yikama.webm', 2048, 'video/webm')])
            ->assertSessionHasNoErrors();
        $this->post($this->storeUrl(), ['title' => 'Kontrol et', 'media' => UploadedFile::fake()->create('kontrol.png', 100, 'image/png')])
            ->assertSessionHasNoErrors();

        [, $image, $video, $png] = $this->phase->steps()->get()->all();
        $this->assertMatchesRegularExpression('#^procedures/[^/]+\.jpg$#', $image->media_path);
        $this->assertMatchesRegularExpression('#^procedures/[^/]+\.webm$#', $video->media_path);
        $this->assertMatchesRegularExpression('#^procedures/[^/]+\.png$#', $png->media_path);
        Storage::disk('public')->assertExists([$image->media_path, $video->media_path, $png->media_path]);
        $this->assertSame(['image', 'video', 'image'], [$image->mediaType(), $video->mediaType(), $png->mediaType()]);

        // Taslak ekranında bağlantı, düzenleme ekranında önizleme.
        $editor = $this->get(route('admin.procedures.versions.show', [$this->procedure, $this->draftVersion]));
        $this->assertSame('_blank', $this->one($editor, '#step-'.$video->id.' a[href="'.Storage::disk('public')->url($video->media_path).'"]')->getAttribute('target'));
        $edit = $this->get(route('admin.procedures.steps.edit', [$this->procedure, $this->draftVersion, $this->phase, $image]));
        $this->one($edit, 'img[src="'.Storage::disk('public')->url($image->media_path).'"]');
        $this->one($edit, 'input[name="remove_media"]');
    }

    public function test_file_type_and_size_are_validated(): void
    {
        $rejected = [
            UploadedFile::fake()->create('belge.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('resim.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->create('video.mov', 100, 'video/quicktime'),
            UploadedFile::fake()->create('sahte.jpg', 100, 'application/x-php'),
        ];

        foreach ($rejected as $file) {
            $this->post($this->storeUrl(), ['title' => 'Adım', 'media' => $file])
                ->assertSessionHasErrors(['media' => 'Medya dosyası JPG, PNG ya da WEBP görsel veya MP4 ya da WEBM video olmalıdır.']);
        }

        $this->post($this->storeUrl(), ['title' => 'Adım', 'media' => UploadedFile::fake()->create('uzun.mp4', 20481, 'video/mp4')])
            ->assertSessionHasErrors(['media' => 'Medya dosyası en fazla 20 MB olabilir.']);
        $this->post($this->storeUrl(), ['title' => 'Adım', 'media' => UploadedFile::fake()->create('sinir.mp4', 20480, 'video/mp4')])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->phase->steps()->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('procedures'));
    }

    public function test_new_media_replaces_the_old_one_and_media_can_be_removed(): void
    {
        $step = $this->phase->steps()->first();
        $update = fn (array $data) => $this->put(route('admin.procedures.steps.update', [$this->procedure, $this->draftVersion, $this->phase, $step]), ['title' => $step->title, ...$data]);

        $update(['media' => UploadedFile::fake()->create('ilk.jpg', 100, 'image/jpeg')])->assertSessionHasNoErrors();
        $first = $step->fresh()->media_path;
        Storage::disk('public')->assertExists($first);

        // Medya gönderilmeden kaydetmek mevcut medyayı korur.
        $update(['description' => 'Açıklama'])->assertSessionHasNoErrors();
        $this->assertSame($first, $step->fresh()->media_path);

        $update(['media' => UploadedFile::fake()->create('ikinci.mp4', 100, 'video/mp4')])->assertSessionHasNoErrors();
        $second = $step->fresh()->media_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $update(['remove_media' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($step->fresh()->media_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_media_shared_with_a_published_version_is_kept_on_disk(): void
    {
        // v1 yayımlanır; v2 taslağı medya yolunu kopyalar (aynı dosya).
        $procedure = $this->procedure('PRC-MEDYA');
        Storage::disk('public')->put('procedures/ortak.jpg', 'jpg');
        Storage::disk('public')->put('procedures/v1-video.mp4', 'mp4');
        $v1 = $this->publishVersion($procedure, [['steps' => 2, 'step_attributes' => [
            1 => ['media_path' => 'procedures/ortak.jpg'],
            2 => ['media_path' => 'procedures/v1-video.mp4'],
        ]]]);
        $this->post(route('admin.procedures.versions.store', $procedure));
        $draft = $procedure->versions()->whereNull('published_at')->sole();
        $phase = $draft->phases()->first();
        [$shared, $video] = $phase->steps()->get()->all();
        $this->assertSame('procedures/ortak.jpg', $shared->media_path);

        $this->put(route('admin.procedures.steps.update', [$procedure, $draft, $phase, $shared]), ['title' => $shared->title, 'remove_media' => '1']);
        $this->delete(route('admin.procedures.steps.destroy', [$procedure, $draft, $phase, $video]));

        $this->assertNull($shared->fresh()->media_path);
        Storage::disk('public')->assertExists(['procedures/ortak.jpg', 'procedures/v1-video.mp4']);
        $this->assertSame(['procedures/ortak.jpg', 'procedures/v1-video.mp4'], $v1->steps()->orderBy('procedure_steps.sequence')->pluck('media_path')->all());
    }

    public function test_deleting_a_draft_removes_only_its_own_media(): void
    {
        Storage::disk('public')->put('procedures/ortak.jpg', 'jpg');
        $procedure = $this->procedure('PRC-MEDYA');
        $this->publishVersion($procedure, [['steps' => 1, 'step_attributes' => [1 => ['media_path' => 'procedures/ortak.jpg']]]]);
        $this->post(route('admin.procedures.versions.store', $procedure));
        $draft = $procedure->versions()->whereNull('published_at')->sole();
        $phase = $draft->phases()->first();
        $this->post(route('admin.procedures.steps.store', [$procedure, $draft, $phase]), ['title' => 'Yeni', 'media' => UploadedFile::fake()->create('yeni.png', 10, 'image/png')]);
        $own = $phase->steps()->where('sequence', 2)->sole()->media_path;
        Storage::disk('public')->assertExists($own);

        $this->delete(route('admin.procedures.versions.destroy', [$procedure, $draft]))->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($own);
        Storage::disk('public')->assertExists('procedures/ortak.jpg');
    }

    public function test_upload_to_a_published_version_is_rejected_and_not_stored(): void
    {
        $v1 = $this->publishVersion($this->procedure('PRC-YAYIN'), [['steps' => 1]]);
        $phase = $v1->phases()->first();

        $this->post(route('admin.procedures.steps.store', [$v1->procedure, $v1, $phase]), ['title' => 'Yeni', 'media' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')])
            ->assertSessionHasErrors('version');

        $this->assertSame([], Storage::disk('public')->allFiles('procedures'));
        $this->assertSame(1, $phase->steps()->count());
    }

    private function storeUrl(): string
    {
        return route('admin.procedures.steps.store', [$this->procedure, $this->draftVersion, $this->phase]);
    }
}
