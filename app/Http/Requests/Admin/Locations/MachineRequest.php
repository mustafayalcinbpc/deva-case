<?php

namespace App\Http\Requests\Admin\Locations;

use App\Models\Line;
use App\Models\Machine;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Services\Definitions\IssuedCodeLock;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Makine ekleme ve düzenleme. Makine kodu hat içinde benzersizdir. K-18: yalnızca yayımlanmış
 * versiyonu olan prosedür atanabilir; kullanımdaki (ve yeni) makine için prosedür zorunludur.
 * Kullanım durumu bu formdan değil MachineRetirement üzerinden değişir (K-16).
 */
class MachineRequest extends LocationRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $machine = $this->machine();
        $locked = $this->locks()->machineLocked($machine);

        // Benzersizlik, makinenin gideceği hatta bakılarak denetlenir.
        $lineId = ! $locked && filter_var($this->input('line_id'), FILTER_VALIDATE_INT) !== false
            ? (int) $this->input('line_id')
            : $machine->line_id;

        return [
            'line_id' => $locked
                ? ['sometimes', Rule::in([(string) $machine->line_id])]
                : ['required', 'integer', Rule::exists(Line::class, 'id')],
            'code' => $this->codeRules(
                $locked ? $machine->code : null,
                [Rule::unique(Machine::class, 'code')->where('line_id', $lineId)->ignore($machine)],
            ),
            'name' => ['required', 'string', 'max:255'],
            'procedure_id' => [
                'bail',
                Rule::requiredIf(! $machine->exists || $machine->is_active),
                'nullable',
                'integer',
                Rule::exists(Procedure::class, 'id'),
                $this->publishedProcedure(...),
            ],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'line_id' => 'hat',
            'procedure_id' => 'prosedür',
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'code.unique' => 'Bu hatta aynı kodlu bir makine zaten var.',
            'code.in' => IssuedCodeLock::MACHINE_REASON,
            'line_id.in' => IssuedCodeLock::MACHINE_REASON,
            'procedure_id.required' => 'Kullanımdaki makinenin bir prosedürü olmalı (K-18).',
        ];
    }

    private function publishedProcedure(string $attribute, mixed $value, Closure $fail): void
    {
        $published = ProcedureVersion::query()
            ->where('procedure_id', $value)
            ->where('published_at', '<=', now())
            ->exists();

        if (! $published) {
            $fail('Seçilen prosedürün yayımlanmış bir versiyonu yok; önce prosedürü yayımlayın (K-18).');
        }
    }

    private function machine(): Machine
    {
        return $this->route('machine') ?? new Machine(['is_active' => true]);
    }
}
