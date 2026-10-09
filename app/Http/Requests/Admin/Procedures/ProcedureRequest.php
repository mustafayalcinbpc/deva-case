<?php

namespace App\Http\Requests\Admin\Procedures;

use App\Models\Procedure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Prosedür ekleme ve düzenleme: benzersiz kod ve ad. Fazlar ve adımlar versiyonda tanımlanır.
 * Yetki route grubundaki `can:manage-definitions` ile verilir.
 */
class ProcedureRequest extends FormRequest
{
    public const CODE_MAX = 30;

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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:'.self::CODE_MAX,
                'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/',
                Rule::unique(Procedure::class, 'code')->ignore($this->route('procedure')),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
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
            'code.regex' => 'Kod yalnızca büyük harf (A–Z), rakam ve aralarında tire içerebilir; ör. PRC-DOL.',
        ];
    }
}
