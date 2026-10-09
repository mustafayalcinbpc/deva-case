<?php

namespace App\Http\Requests\Admin\Procedures;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Taslağı yayımlama: hemen ya da ileri bir tarihte (K-15). Tarih ve saat gösterim saat
 * diliminde (config('app.display_timezone')) girilir, UTC olarak saklanır. Tarihin gelecekte
 * olması ve taslağın yapısı ProcedureVersioning::publish() içinde denetlenir.
 */
class PublishProcedureVersionRequest extends FormRequest
{
    public const NOW = 'now';

    public const SCHEDULED = 'scheduled';

    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'when' => ['required', Rule::in([self::NOW, self::SCHEDULED])],
            // Tarih girilip "Hemen" seçilmişse hangisinin istendiği belirsizdir; hemen yayımlanmaz.
            'publish_at' => ['nullable', 'required_if:when,'.self::SCHEDULED, 'prohibited_if:when,'.self::NOW, 'date_format:'.self::INPUT_FORMAT],
        ];
    }

    /**
     * NULL: hemen.
     */
    public function publishAt(): ?CarbonImmutable
    {
        if ($this->validated('when') !== self::SCHEDULED) {
            return null;
        }

        return CarbonImmutable::createFromFormat(self::INPUT_FORMAT, $this->validated('publish_at'), config('app.display_timezone'))
            ->startOfMinute()
            ->utc();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'when' => 'yayın zamanı',
            'publish_at' => 'yayın tarihi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'publish_at.required_if' => 'İleri tarihli yayın için tarih ve saat girin.',
            'publish_at.prohibited_if' => 'Tarih girildi ama "Hemen" seçili. İleri tarihte yayımlamak için "İleri bir tarihte"yi seçin, hemen yayımlamak için tarihi silin.',
            'publish_at.date_format' => 'Yayın tarihi geçerli bir tarih ve saat olmalıdır.',
        ];
    }
}
