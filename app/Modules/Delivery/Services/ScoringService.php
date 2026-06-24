<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Authoring\Models\ScoringRule;
use App\Modules\Delivery\Events\ScoreFinalized;
use App\Modules\Delivery\Events\SittingSubmitted;
use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Delivery\Models\GradingTask;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\QuestionBank\Services\AnswerKeyVault;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Submit + scoring — the loop closure (docs/01 §4.6).
 *
 * At submit time the vault is queried JUST-IN-TIME (docs/04 §2.4): for each objective
 * item we map the candidate's shuffled answer back to canonical keys via the variant
 * manifest (per-session answer mapping), ask AnswerKeyVault for the correctness fraction,
 * then apply the assessment's ScoringPolicy. Open-ended items spawn grading tasks instead
 * and leave the score 'under_review'. The score pins the scoring-rule version, so it is
 * reproducible from (responses, rule@version).
 */
class ScoringService
{
    private const CHOICE_TYPES = ['single', 'multiple', 'true_false', 'yes_no'];

    private const OPEN_ENDED_TYPES = ['essay', 'short_answer', 'code'];

    public function __construct(
        private readonly ResponseRecorder $responses,
        private readonly AnswerKeyVault $vault,
    ) {}

    public function submit(Sitting $sitting): Score
    {
        if (! $sitting->isInProgress()) {
            throw new DeliveryError("Cannot submit: sitting is {$sitting->status}.");
        }

        return DB::transaction(function () use ($sitting) {
            $sitting->forceFill(['status' => 'submitted', 'submitted_at' => Carbon::now()])->save();
            SittingSubmitted::dispatch($sitting->id);

            return $this->score($sitting);
        });
    }

    public function score(Sitting $sitting): Score
    {
        $assessment = $sitting->assessment;
        $rule = $assessment->scoring_rule_id ? ScoringRule::findOrFail($assessment->scoring_rule_id) : null;
        $policy = ($rule ?? new ScoringRule(['policy' => []]))->toPolicy();

        $analysis = $this->analyzeSitting($sitting);

        $questions = [];
        $needsManual = false;

        foreach ($analysis as $ivId => $info) {
            if (! $info['objective']) {
                // Open-ended items are excluded from the objective auto-score and routed
                // to manual/AI grading; the score stays under_review until they reconcile.
                if ($this->openGradingTask($sitting, $ivId, $info['type'])) {
                    $needsManual = true;
                }

                continue;
            }
            $questions[] = ['fraction' => $info['fraction'], 'weight' => $info['weight']];
        }

        $result = $policy->evaluate($questions);

        $score = Score::updateOrCreate(
            ['sitting_id' => $sitting->id],
            [
                'scoring_rule_id' => $rule?->id,
                'scoring_rule_version' => $rule?->version,
                'raw_score' => $result['raw'],
                'scaled_score' => $result['scaled'],
                // objective_raw is retained so manual marks can be folded in on reconcile.
                'section_breakdown' => ['objective_raw' => $result['raw'], 'objective_max' => $result['max']],
                'status' => $needsManual ? 'under_review' : 'final',
            ]
        );

        $sitting->forceFill(['status' => 'graded'])->save();
        ScoreFinalized::dispatch($sitting->id, $score->id);

        return $score;
    }

    /**
     * Per-item analysis of a sitting, reused by scoring and by analytics:
     *   iv => { type, objective, fraction|null, chosen: canonical keys[], weight }
     * Objective fractions come from mapping the candidate's shuffled answer back to
     * canonical keys (per-session mapping) and scoring it via the vault (JIT, docs/04 §2).
     *
     * @return array<string, array{type:string, objective:bool, fraction:?float, chosen:array, weight:float}>
     */
    public function analyzeSitting(Sitting $sitting): array
    {
        $manifest = $sitting->manifest;
        $answers = $this->responses->latestAnswers($sitting);
        $ivIds = array_column($manifest->manifest['items'] ?? [], 'iv');
        $versions = ItemVersion::whereHas('item')->whereIn('id', $ivIds)->with('item')->get()->keyBy('id');

        $out = [];
        foreach ($ivIds as $ivId) {
            $version = $versions[$ivId] ?? null;
            if (! $version) {
                continue;
            }
            $type = $version->item->type;
            $objective = ! in_array($type, self::OPEN_ENDED_TYPES, true);
            $answer = $answers[$ivId] ?? null;

            $chosen = [];
            $fraction = null;
            if ($objective && $answer !== null) {
                if (in_array($type, self::CHOICE_TYPES, true)) {
                    $indices = array_map('intval', $answer['selected'] ?? []);
                    $chosen = $manifest->canonicalKeysFor($ivId, $indices);
                    $candidate = $type === 'multiple' ? $chosen : ($chosen[0] ?? '');
                } else {
                    $candidate = $answer['value'] ?? '';
                }
                $fraction = $this->vault->scoreObjective($ivId, $type, $candidate);
            }

            $out[$ivId] = [
                'type' => $type,
                'objective' => $objective,
                'fraction' => $fraction,
                'chosen' => $chosen,
                'weight' => (float) ($version->item->default_weight ?? 1),
            ];
        }

        return $out;
    }

    /**
     * Open (or reuse) a grading task for one open-ended item. Returns true if the item
     * was answered and thus needs manual grading; false if it was left blank.
     */
    private function openGradingTask(Sitting $sitting, string $ivId, string $type): bool
    {
        // The candidate's latest response for this item (responses are partitioned, so
        // this is a logical reference, not a hard FK).
        $responseId = DB::table('responses')
            ->where('sitting_id', $sitting->id)
            ->where('item_version_id', $ivId)
            ->orderByDesc('sequence')
            ->value('id');

        if ($responseId === null) {
            return false; // unanswered
        }

        GradingTask::firstOrCreate(
            ['sitting_id' => $sitting->id, 'item_version_id' => $ivId],
            ['response_id' => $responseId, 'type' => $type, 'status' => 'pending']
        );

        return true;
    }
}
