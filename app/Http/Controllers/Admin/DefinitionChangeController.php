<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DefinitionChangeAction;
use App\Http\Controllers\Controller;
use App\Models\DefinitionChange;
use App\Models\User;
use App\Services\Definitions\DefinitionChangeLinks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Tanım değişiklik günlüğü (R-49): tesis, hat, makine, prosedür, malzeme, üretim iş emri ve kullanıcı
 * tanımlarında kim, ne zaman, neyi değiştirdi. Yalnızca okunur; satırlar model olaylarından
 * yazılır (RecordsDefinitionChanges). En yeni değişiklik üsttedir.
 *
 * Tarihler gösterim saat diliminde gün olarak yorumlanır (ReportFilters ile aynı). `root_type`
 * ve `root_id` bir tanımın geçmişini gösterir: prosedürde versiyon, faz ve adımlar da dahildir.
 * Geçersiz filtre değerleri yok sayılır.
 */
class DefinitionChangeController extends Controller
{
    private const PER_PAGE = 50;

    public const SYSTEM_ACTOR = 'system';

    public function index(Request $request, DefinitionChangeLinks $links): View
    {
        $filters = $this->filters($request);

        $changes = DefinitionChange::query()
            ->with('actor:id,name')
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['actor'] === self::SYSTEM_ACTOR, fn (Builder $query) => $query->whereNull('actor_id'))
            ->when(is_int($filters['actor']), fn (Builder $query) => $query->where('actor_id', $filters['actor']))
            ->when($filters['action'], fn (Builder $query, DefinitionChangeAction $action) => $query->where('action', $action->value))
            ->when($filters['from'], fn (Builder $query, CarbonImmutable $from) => $query->where('occurred_at', '>=', $this->databaseTime($from)))
            ->when($filters['to'], fn (Builder $query, CarbonImmutable $to) => $query->where('occurred_at', '<', $this->databaseTime($to->addDay())))
            ->when($filters['root_type'], fn (Builder $query, string $type) => $query->ofDefinition($type, $filters['root_id']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.definition-changes.index', [
            'changes' => $changes,
            'links' => $links->for($changes->getCollection()),
            'filters' => $filters,
            'isFiltered' => array_filter($filters, fn ($value) => $value !== null) !== [],
            'definition' => $this->definition($filters, $links),
            'types' => DefinitionChange::SUBJECT_LABELS,
            'actions' => DefinitionChangeAction::cases(),
            'actors' => User::query()
                ->whereIn('id', DefinitionChange::query()->select('actor_id')->whereNotNull('actor_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'systemActor' => self::SYSTEM_ACTOR,
        ]);
    }

    /**
     * @return array{type: ?string, actor: int|string|null, action: ?DefinitionChangeAction, from: ?CarbonImmutable, to: ?CarbonImmutable, root_type: ?string, root_id: ?int}
     */
    private function filters(Request $request): array
    {
        $timezone = config('app.display_timezone');
        $type = $request->query('type');
        $actor = $request->query('actor');
        $action = $request->query('action');
        $rootType = $request->query('root_type');
        $rootId = $this->id($request->query('root_id'));
        $from = $this->date($request->query('from'), $timezone);
        $to = $this->date($request->query('to'), $timezone);

        if ($from !== null && $to !== null && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $hasRoot = is_string($rootType) && array_key_exists($rootType, DefinitionChange::SUBJECT_TYPES) && $rootId !== null;

        return [
            'type' => is_string($type) && array_key_exists($type, DefinitionChange::SUBJECT_TYPES) ? $type : null,
            'actor' => $actor === self::SYSTEM_ACTOR ? self::SYSTEM_ACTOR : $this->id($actor),
            'action' => is_string($action) ? DefinitionChangeAction::tryFrom($action) : null,
            'from' => $from,
            'to' => $to,
            'root_type' => $hasRoot ? $rootType : null,
            'root_id' => $hasRoot ? $rootId : null,
        ];
    }

    /**
     * Geçmişi gösterilen tanım: türü, kısa adı (silinmişse günlükteki son adı) ve sayfası.
     *
     * @param  array{root_type: ?string, root_id: ?int}  $filters
     * @return array{type: string, label: string, url: ?string}|null
     */
    private function definition(array $filters, DefinitionChangeLinks $links): ?array
    {
        ['root_type' => $type, 'root_id' => $id] = $filters;

        if ($type === null) {
            return null;
        }

        $label = DefinitionChange::SUBJECT_TYPES[$type]::query()->find($id)?->definitionChangeLabel()
            ?? DefinitionChange::query()->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->value('subject_label');

        return [
            'type' => DefinitionChange::SUBJECT_LABELS[$type],
            'label' => $label ?? "#{$id}",
            'url' => $links->definition($type, $id),
        ];
    }

    private function databaseTime(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
