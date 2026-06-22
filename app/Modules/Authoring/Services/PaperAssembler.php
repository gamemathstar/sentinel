<?php

namespace App\Modules\Authoring\Services;

use App\Modules\Authoring\Exceptions\AssemblyShortfall;
use App\Modules\Authoring\Models\Blueprint;
use App\Modules\Authoring\Support\DifficultyBand;
use App\Modules\QuestionBank\Models\Item;

/**
 * Turns a blueprint into a concrete, balanced set of item versions drawn from the bank
 * (docs spec: "the system automatically generates balanced papers"). Only APPROVED
 * items (status = active, with a pinned current version) are eligible. If a difficulty
 * band cannot be filled, assembly fails with an explicit per-band shortfall rather than
 * silently producing an unbalanced paper.
 *
 * The result is a list of item_version_ids to pin into a section.
 */
class PaperAssembler
{
    /**
     * @return string[] selected item_version_ids
     *
     * @throws AssemblyShortfall
     */
    public function assemble(Blueprint $blueprint): array
    {
        $c = $blueprint->constraints;
        $total = (int) $c['total'];
        $types = $c['types'] ?? null;

        // Candidate pool: approved items in the tenant, of allowed types, with a pinned version.
        $pool = Item::query()
            ->where('status', 'active')
            ->whereNotNull('current_version_id')
            ->when($types, fn ($q) => $q->whereIn('type', $types))
            ->get(['id', 'type', 'difficulty', 'current_version_id']);

        // No difficulty constraint: just draw `total` from the pool.
        if (empty($c['difficulty'])) {
            if ($pool->count() < $total) {
                throw new AssemblyShortfall(['total' => ['needed' => $total, 'available' => $pool->count()]]);
            }

            return $pool->shuffle()->take($total)->pluck('current_version_id')->all();
        }

        return $this->assembleByDifficulty($pool, $total, $c['difficulty']);
    }

    /** @return string[] */
    private function assembleByDifficulty($pool, int $total, array $difficulty): array
    {
        // Bucket the pool by difficulty band.
        $banded = [DifficultyBand::EASY => [], DifficultyBand::MEDIUM => [], DifficultyBand::HARD => []];
        foreach ($pool as $item) {
            $band = DifficultyBand::fromFacility($item->difficulty !== null ? (float) $item->difficulty : null);
            if ($band !== null) {
                $banded[$band][] = $item->current_version_id;
            }
        }

        // Largest-remainder apportionment so the per-band counts sum exactly to `total`.
        $needed = $this->apportion($total, $difficulty);

        // Detect shortfalls up front across all bands, then report them together.
        $shortfall = [];
        foreach ($needed as $band => $count) {
            $available = count($banded[$band] ?? []);
            if ($available < $count) {
                $shortfall[$band] = ['needed' => $count, 'available' => $available];
            }
        }
        if ($shortfall !== []) {
            throw new AssemblyShortfall($shortfall);
        }

        $selected = [];
        foreach ($needed as $band => $count) {
            shuffle($banded[$band]);
            array_push($selected, ...array_slice($banded[$band], 0, $count));
        }

        return $selected;
    }

    /**
     * Largest-remainder method: floor each share, then hand the leftover seats to the
     * bands with the biggest fractional remainders, so the counts sum to exactly $total.
     *
     * @param  array<string,float>  $fractions
     * @return array<string,int>
     */
    private function apportion(int $total, array $fractions): array
    {
        $exact = [];
        $floors = [];
        $assigned = 0;
        foreach ($fractions as $band => $fraction) {
            $exact[$band] = $total * $fraction;
            $floors[$band] = (int) floor($exact[$band]);
            $assigned += $floors[$band];
        }

        $remainder = $total - $assigned;
        if ($remainder > 0) {
            // Order bands by descending fractional part.
            uksort($floors, function ($a, $b) use ($exact) {
                return ($exact[$b] - floor($exact[$b])) <=> ($exact[$a] - floor($exact[$a]));
            });
            foreach (array_keys($floors) as $band) {
                if ($remainder <= 0) {
                    break;
                }
                $floors[$band]++;
                $remainder--;
            }
        }

        return $floors;
    }
}
