<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Delivery\Models\Response;
use App\Modules\Delivery\Models\Sitting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records candidate answers as APPEND-ONLY rows (docs/01 §4.5, docs/03 §4). A correction
 * is a new row with a higher sequence; the latest sequence per (sitting, item) wins.
 * Writes are guarded by the server-authoritative deadline and sitting state (docs/04 §8).
 */
class ResponseRecorder
{
    public function record(Sitting $sitting, string $itemVersionId, array $answer, ?float $confidence = null, ?int $timeSpentMs = null): Response
    {
        if (! $sitting->isInProgress()) {
            throw new DeliveryError("Cannot record a response: sitting is {$sitting->status}.");
        }
        if ($sitting->isPastDeadline()) {
            throw new DeliveryError('The exam deadline has passed; responses are closed.');
        }
        if (! $this->itemBelongsToVariant($sitting, $itemVersionId)) {
            throw new DeliveryError('That question is not part of this sitting.');
        }

        // Monotonic sequence per sitting; concurrency is bounded by the single candidate.
        $next = ((int) Response::where('sitting_id', $sitting->id)->max('sequence')) + 1;

        return Response::create([
            'sitting_id' => $sitting->id,
            'item_version_id' => $itemVersionId,
            'sequence' => $next,
            'answer' => $answer,
            'confidence' => $confidence,
            'time_spent_ms' => $timeSpentMs,
            'answered_at' => Carbon::now(),
        ]);
    }

    /**
     * The effective answer per item: the row with the highest sequence wins.
     *
     * @return array<string, array> item_version_id => answer payload
     */
    public function latestAnswers(Sitting $sitting): array
    {
        // DISTINCT ON keeps only the newest row per item_version_id.
        $rows = DB::table('responses')
            ->select('item_version_id', 'answer')
            ->where('sitting_id', $sitting->id)
            ->orderBy('item_version_id')
            ->orderByDesc('sequence')
            ->distinct('item_version_id')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->item_version_id] = json_decode($row->answer, true);
        }

        return $latest;
    }

    private function itemBelongsToVariant(Sitting $sitting, string $itemVersionId): bool
    {
        $manifest = $sitting->manifest;

        return $manifest !== null
            && in_array($itemVersionId, array_column($manifest->manifest['items'] ?? [], 'iv'), true);
    }
}
