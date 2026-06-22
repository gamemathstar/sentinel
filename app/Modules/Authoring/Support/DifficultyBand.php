<?php

namespace App\Modules\Authoring\Support;

/**
 * Maps an item's facility index (proportion of candidates who answer correctly, 0..1)
 * to a difficulty band. A HIGH facility means an EASY item. Bands are the vocabulary
 * blueprints speak in ("40% easy, 40% medium, 20% hard").
 */
final class DifficultyBand
{
    public const EASY = 'easy';

    public const MEDIUM = 'medium';

    public const HARD = 'hard';

    public const ALL = [self::EASY, self::MEDIUM, self::HARD];

    public static function fromFacility(?float $facility): ?string
    {
        if ($facility === null) {
            return null; // un-banded items are excluded from blueprint draws
        }
        if ($facility >= 0.66) {
            return self::EASY;
        }
        if ($facility >= 0.33) {
            return self::MEDIUM;
        }

        return self::HARD;
    }
}
