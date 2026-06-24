<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\StudentEnrollment;
use App\Modules\Tenancy\Models\OrgNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns a selection — "all students", or any mix of faculty / department / programme
 * nodes, optionally narrowed to certain levels — into the concrete set of enrollments.
 *
 * Because faculty -> department -> programme is one materialized-path tree, a selected
 * node of ANY tier matches every enrollment whose programme sits in its subtree. So the
 * same resolver answers "all of Engineering", "the CSC programme", or "300-level
 * Medicine" without special-casing the tier.
 *
 * Selection shape:
 *   ['scope' => 'all']
 *   ['scope' => 'nodes', 'org_node_ids' => [...], 'levels' => ['100','200']]
 */
class SelectionResolver
{
    /** @return Builder<StudentEnrollment> */
    public function query(array $selection): Builder
    {
        $query = StudentEnrollment::query()->where('status', 'active');

        $scope = $selection['scope'] ?? 'all';
        if ($scope === 'nodes') {
            $paths = OrgNode::whereIn('id', $selection['org_node_ids'] ?? [])->pluck('path')->all();
            $query->where(function (Builder $q) use ($paths) {
                if ($paths === []) {
                    $q->whereRaw('1 = 0'); // an empty node selection matches nobody

                    return;
                }
                $q->whereHas('programme', function (Builder $node) use ($paths) {
                    $node->where(function (Builder $inner) use ($paths) {
                        foreach ($paths as $path) {
                            // ancestor-or-self via the materialized path (see OrgNodeService::pathCovers)
                            $inner->orWhere('path', $path)
                                ->orWhere('path', 'like', rtrim($path, '/').'/%');
                        }
                    });
                });
            });
        }

        $levels = $selection['levels'] ?? [];
        if ($levels !== []) {
            $query->whereIn('level', $levels);
        }

        return $query;
    }

    /** Fully-resolved enrollments, ordered so cohorts stay contiguous (programme, level, name). */
    public function resolve(array $selection): Collection
    {
        return $this->query($selection)
            ->with(['student:id,full_name,email', 'programme:id,name,code,type'])
            ->get()
            ->sortBy(fn ($e) => [$e->programme?->name ?? '', $e->level, $e->student?->full_name ?? ''])
            ->values();
    }

    /** Counts grouped by programme + level — the preview an admin sees before mapping. */
    public function summary(array $selection): array
    {
        $rows = $this->resolve($selection);

        $groups = [];
        foreach ($rows as $e) {
            $key = ($e->programme?->name ?? 'Unknown').'|'.$e->level;
            $groups[$key] ??= [
                'programme' => $e->programme?->name ?? 'Unknown',
                'programme_id' => $e->programme_org_node_id,
                'level' => $e->level,
                'count' => 0,
            ];
            $groups[$key]['count']++;
        }

        return [
            'total' => $rows->count(),
            'groups' => array_values($groups),
        ];
    }
}
