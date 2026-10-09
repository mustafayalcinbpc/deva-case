<?php

namespace App\Http\Requests\Admin\Locations;

use App\Services\Definitions\IssuedCodeLock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tesis, hat ve makine formlarının ortak kısmı. Kodlar kayıt numarasının parçasıdır (K-17):
 * büyük harfe çevrilir, yalnızca A–Z ve rakam içerir (numaradaki "-" ayırıcısıyla karışmaz).
 * Yetki route grubundaki `can:manage-definitions` ile verilir (R-42, R-43).
 */
abstract class LocationRequest extends FormRequest
{
    public const CODE_MAX = 10;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper($this->input('code'))]);
        }
    }

    /**
     * Kayıt açılmış tanımda kod yalnızca aynı değerle gönderilebilir (form alanı salt okunurdur).
     *
     * @param  list<mixed>  $uniqueness
     * @return list<mixed>
     */
    protected function codeRules(?string $lockedCode, array $uniqueness): array
    {
        if ($lockedCode !== null) {
            return ['sometimes', 'string', Rule::in([$lockedCode])];
        }

        return ['required', 'string', 'max:'.self::CODE_MAX, 'regex:/^[A-Z0-9]+$/', ...$uniqueness];
    }

    protected function locks(): IssuedCodeLock
    {
        return app(IssuedCodeLock::class);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kod',
            'name' => 'ad',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kod yalnızca büyük harf (A–Z) ve rakamlardan oluşmalıdır; boşluk ve tire kullanılamaz.',
        ];
    }
}
