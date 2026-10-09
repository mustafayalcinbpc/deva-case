<?php

namespace App\Http\Requests\Admin\Locations;

use App\Models\Facility;
use App\Models\Line;
use App\Services\Definitions\IssuedCodeLock;
use Illuminate\Validation\Rule;

/**
 * Hat ekleme (tesisin içinde) ve düzenleme. Hat kodu tesis içinde benzersizdir; hat başka
 * tesise taşınmaz (K-17).
 */
class LineRequest extends LocationRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $line = $this->route('line') ?? new Line;
        $facilityId = $line->exists ? $line->facility_id : $this->facility()->id;

        return [
            'code' => $this->codeRules(
                $this->locks()->lineLocked($line) ? $line->code : null,
                [Rule::unique(Line::class, 'code')->where('facility_id', $facilityId)->ignore($line)],
            ),
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'code.unique' => 'Bu tesiste aynı kodlu bir hat zaten var.',
            'code.in' => IssuedCodeLock::LINE_REASON,
        ];
    }

    public function facility(): Facility
    {
        return $this->route('facility') ?? $this->route('line')->facility;
    }
}
