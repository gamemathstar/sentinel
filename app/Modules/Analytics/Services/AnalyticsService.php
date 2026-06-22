<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\AssessmentReliability;
use App\Modules\Analytics\Models\ItemStatistics;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\QuestionBank\Services\AnswerKeyVault;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compiles classical-test-theory analytics for an assessment from its FINALIZED sittings
 * (docs/01 §4.8) and writes the read models, never touching the exam hot path. It also
 * closes the authoring feedback loop: each item's measured facility/discrimination is
 * written back to `items`, so future blueprint draws band items by real difficulty.
 *
 * An item is scored dichotomously here (correct iff fraction == 1) — the standard basis
 * for KR-20.
 */
class AnalyticsService
{
    public function __construct(
        private readonly ScoringService $scoring,
        private readonly PsychometricsCalculator $calc,
        private readonly AnswerKeyVault $vault,
    ) {}

    public function compileAssessment(Assessment $assessment): AssessmentReliability
    {
        $sittings = Sitting::where('assessment_id', $assessment->id)
            ->whereIn('status', ['graded'])
            ->with('manifest')
            ->get();

        if ($sittings->isEmpty()) {
            throw new RuntimeException('No graded sittings to analyse for this assessment.');
        }

        // Stable item universe: the versions pinned into the assessment's sections.
        $itemVersionIds = $this->pinnedItemVersionIds($assessment);
        $versions = ItemVersion::whereHas('item')->whereIn('id', $itemVersionIds)->with('item')->get()->keyBy('id');

        // Build the correctness matrix and per-candidate totals.
        $correctness = array_fill_keys($itemVersionIds, []); // iv => [0/1,...]
        $chosen = array_fill_keys($itemVersionIds, []);       // iv => [[canonical keys], ...]
        $totals = [];

        foreach ($sittings as $sitting) {
            $analysis = $this->scoring->analyzeSitting($sitting);
            $total = 0;
            foreach ($itemVersionIds as $iv) {
                $info = $analysis[$iv] ?? null;
                $correct = ($info && $info['fraction'] !== null && $info['fraction'] >= 1.0) ? 1 : 0;
                $correctness[$iv][] = $correct;
                $chosen[$iv][] = $info['chosen'] ?? [];
                $total += $correct;
            }
            $totals[] = $total;
        }

        return DB::transaction(fn () => $this->persist($assessment, $itemVersionIds, $versions, $correctness, $chosen, $totals));
    }

    private function persist(Assessment $assessment, array $itemVersionIds, $versions, array $correctness, array $chosen, array $totals): AssessmentReliability
    {
        $k = count($itemVersionIds);
        $n = count($totals);
        $totalVariance = $this->calc->variance(array_map('floatval', $totals));
        $totalSd = sqrt($totalVariance);

        $pValues = [];
        $itemVariances = [];

        foreach ($itemVersionIds as $iv) {
            $version = $versions[$iv] ?? null;
            if (! $version) {
                continue;
            }
            $scores = array_map('floatval', $correctness[$iv]);
            $p = $this->calc->facilityIndex($scores);
            $discrimination = $this->calc->pointBiserial($scores, array_map('floatval', $totals));
            $pValues[] = $p;
            $itemVariances[] = $p * (1 - $p); // dichotomous item variance

            ItemStatistics::updateOrCreate(
                ['item_id' => $version->item_id],
                [
                    'sample_n' => $n,
                    'facility_index' => round($p, 4),
                    'discrimination_index' => round($discrimination, 4),
                    'distractor_analysis' => $this->distractorsFor($version, $chosen[$iv]),
                ]
            );

            // Feedback loop into the bank (docs spec): measured difficulty drives future
            // blueprint banding (Authoring's DifficultyBand reads items.difficulty).
            Item::whereKey($version->item_id)->update([
                'difficulty' => round($p, 4),
                'discrimination' => round($discrimination, 4),
            ]);
        }

        $kr20 = $this->calc->kr20($pValues, $totalVariance, $k);
        $alpha = $this->calc->cronbachAlpha($itemVariances, $totalVariance, $k);
        $sem = $this->calc->sem($totalSd, $kr20);

        return AssessmentReliability::updateOrCreate(
            ['assessment_id' => $assessment->id],
            ['kr20' => round($kr20, 4), 'cronbach_alpha' => round($alpha, 4), 'sem' => round($sem, 4)]
        );
    }

    /**
     * Distractor counts per canonical option for a choice item (null for non-choice).
     *
     * @param  array<int, array>  $chosenPerCandidate  each entry is the canonical keys chosen
     */
    private function distractorsFor(ItemVersion $version, array $chosenPerCandidate): array
    {
        $options = $version->content['options'] ?? [];
        if ($options === []) {
            return [];
        }

        $counts = array_fill_keys(array_keys($options), 0);
        $responders = 0;
        foreach ($chosenPerCandidate as $keys) {
            if ($keys === []) {
                continue;
            }
            $responders++;
            foreach ($keys as $key) {
                if (array_key_exists($key, $counts)) {
                    $counts[$key]++;
                }
            }
        }

        $correctKeys = $this->vault->fetch($version->id)['correct'] ?? [];

        return $this->calc->distractorAnalysis($counts, $correctKeys, $responders);
    }

    /** @return string[] */
    private function pinnedItemVersionIds(Assessment $assessment): array
    {
        return DB::table('section_item')
            ->join('assessment_sections', 'assessment_sections.id', '=', 'section_item.section_id')
            ->where('assessment_sections.assessment_id', $assessment->id)
            ->orderBy('section_item.position')
            ->pluck('section_item.item_version_id')
            ->all();
    }
}
