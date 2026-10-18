<?php

namespace App\Support\Teams;

use App\Models\League;
use App\Models\Setting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The department's league tree. On first use it is filled with the usual
 * German levels; admins adapt it to their regional association.
 */
class LeagueTree
{
    /** Default levels, highest first. */
    public const TEMPLATE = [
        '1. Bundesliga', '2. Bundesliga', '3. Liga', 'Regionalliga', 'Oberliga', 'Verbandsliga',
        'Landesliga', 'Bezirksliga', 'Bezirksklasse', 'Kreisliga', 'Kreisklasse',
    ];

    public function __construct(private TenantContext $tenants) {}

    /**
     * Flat list in tree order with depth, for selects and the admin page.
     *
     * @return list<array{id: string, parent_id: ?string, name: string, level: int, depth: int, team_count: int}>
     */
    public function flat(): array
    {
        $this->ensureSeeded();

        $leagues = League::query()->withCount('children')->orderBy('level')->orderBy('sort_order')->orderBy('name')->get();
        $teamCounts = DB::table('teams')->where('tenant_id', $this->tenants->id())->whereNull('archived_at')
            ->whereNotNull('league_id')->selectRaw('league_id, count(*) as n')->groupBy('league_id')->pluck('n', 'league_id');
        $byParent = $leagues->groupBy(fn (League $l) => $l->parent_id ?? '');

        $out = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, $byParent, $teamCounts) {
            /** @var Collection<int, League> $children */
            $children = $byParent->get($parent, collect());
            foreach ($children as $league) {
                $out[] = [
                    'id' => $league->id,
                    'parent_id' => $league->parent_id,
                    'name' => $league->name,
                    'level' => $league->level,
                    'depth' => $depth,
                    'team_count' => (int) ($teamCounts[$league->id] ?? 0),
                ];
                $walk($league->id, $depth + 1);
            }
        };
        $walk('', 0);

        return $out;
    }

    public function ensureSeeded(): void
    {
        $key = 'leagues.seeded.'.$this->tenants->id();
        if (Setting::read($key) !== null) {
            return;
        }

        DB::transaction(function () use ($key) {
            if (! League::query()->exists()) {
                foreach (self::TEMPLATE as $i => $name) {
                    League::create(['name' => $name, 'level' => $i + 1, 'sort_order' => $i]);
                }
            }
            Setting::write($key, true);
        });
    }
}
