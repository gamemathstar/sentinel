<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\QuestionBank\Models\ItemVersion;

/**
 * Builds a per-candidate variant from a published assessment (docs/01 §4.5, docs/02 §3).
 *
 * Each candidate gets: items in a per-candidate order (shuffled within each section, so
 * section order is preserved) and, for choice items, a per-candidate option order. The
 * manifest records the option order so the server can map a shuffled answer back to the
 * canonical key at scoring time — meaning "the answer is B" is meaningless across
 * candidates (per-session answer mapping, docs/04 §8).
 *
 * Pre-assembling here (before/at start, not under peak submit load) is the scaling
 * strategy from docs/02 §3.
 */
class VariantAssembler
{
    /** @return array{items: array<int, array{iv:string, type:string, options:array}>} */
    public function assemble(Assessment $assessment): array
    {
        $items = [];

        foreach ($assessment->sections()->with('itemVersions.item')->get() as $section) {
            $versions = $section->itemVersions()->with('item')->get()->all();
            shuffle($versions); // per-candidate question order within the section

            foreach ($versions as $version) {
                $items[] = $this->entryFor($version);
            }
        }

        return ['items' => $items];
    }

    private function entryFor(ItemVersion $version): array
    {
        $type = $version->item->type;
        $optionKeys = array_keys($version->content['options'] ?? []);
        shuffle($optionKeys); // per-candidate option order (display order -> canonical keys)

        return ['iv' => $version->id, 'type' => $type, 'options' => array_values($optionKeys)];
    }

    /**
     * Produce the candidate-facing view of the paper: stems and options in display order,
     * with NO correctness information (that lives only in the vault).
     */
    public function presentation(array $manifest): array
    {
        $ivIds = array_column($manifest['items'], 'iv');
        $versions = ItemVersion::whereHas('item')->whereIn('id', $ivIds)->with('item')->get()->keyBy('id');

        $questions = [];
        foreach ($manifest['items'] as $position => $entry) {
            $version = $versions[$entry['iv']] ?? null;
            if (! $version) {
                continue;
            }
            $allOptions = $version->content['options'] ?? [];
            // Present options in the candidate's display order, labelled by position only.
            $displayed = [];
            foreach ($entry['options'] as $index => $canonicalKey) {
                $displayed[] = ['index' => $index, 'text' => $allOptions[$canonicalKey] ?? null];
            }

            $questions[] = [
                'position' => $position,
                'item_version_id' => $version->id,
                'type' => $entry['type'],
                'stem' => $version->content['stem'] ?? null,
                'options' => $displayed,
            ];
        }

        return $questions;
    }
}
