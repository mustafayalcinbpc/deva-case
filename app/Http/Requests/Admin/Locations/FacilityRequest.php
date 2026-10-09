<?php

namespace App\Http\Requests\Admin\Locations;

use App\Models\Facility;
use App\Services\Definitions\IssuedCodeLock;
use Illuminate\Validation\Rule;

/**
 * Tesis ekleme ve düzenleme. Tesis kodu benzersizdir ve kayıt numarası ile saha defteri
 * referansının başında yer alır (K-17).
 */
class FacilityRequest extends LocationRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $facility = $this->facility();

        return [
            'code' => $this->codeRules(
                $this->locks()->facilityLocked($facility) ? $facility->code : null,
                [Rule::unique(Facility::class, 'code')->ignore($facility)],
            ),
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'code.in' => IssuedCodeLock::FACILITY_REASON,
        ];
    }

    private function facility(): Facility
    {
        return $this->route('facility') ?? new Facility;
    }
}
