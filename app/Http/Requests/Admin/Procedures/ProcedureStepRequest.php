<?php

namespace App\Http\Requests\Admin\Procedures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Adım ekleme ve düzenleme: başlık, açıklama ve isteğe bağlı fotoğraf ya da video (R-05).
 * Dosya türü hem uzantıdan hem içerikten denetlenir; detay ekranı uzantıya göre görsel ya da
 * video olarak gösterir.
 */
class ProcedureStepRequest extends FormRequest
{
    public const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm'];

    /** Kilobayt (20 MB). */
    public const MEDIA_MAX_KB = 20480;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $extensions = implode(',', self::MEDIA_EXTENSIONS);

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'media' => ['nullable', 'file', 'extensions:'.$extensions, 'mimes:'.$extensions, 'max:'.self::MEDIA_MAX_KB],
            'remove_media' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{title: string, description: ?string}
     */
    public function stepAttributes(): array
    {
        $description = $this->validated('description');

        return [
            'title' => $this->validated('title'),
            'description' => $description === '' ? null : $description,
        ];
    }

    public function media(): ?UploadedFile
    {
        return $this->file('media');
    }

    public function removeMedia(): bool
    {
        return $this->boolean('remove_media');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'adım başlığı',
            'description' => 'açıklama',
            'media' => 'medya dosyası',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $types = 'Medya dosyası JPG, PNG ya da WEBP görsel veya MP4 ya da WEBM video olmalıdır.';

        return [
            'media.file' => 'Medya dosyası yüklenemedi; dosya boyutu sunucu sınırını aşmış olabilir.',
            'media.uploaded' => 'Medya dosyası yüklenemedi; dosya boyutu sunucu sınırını aşmış olabilir.',
            'media.extensions' => $types,
            'media.mimes' => $types,
            'media.max' => 'Medya dosyası en fazla '.(self::MEDIA_MAX_KB / 1024).' MB olabilir.',
        ];
    }
}
