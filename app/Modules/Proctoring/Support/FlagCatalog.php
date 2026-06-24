<?php

namespace App\Modules\Proctoring\Support;

/**
 * The catalog of proctoring flag types and their default risk weights (docs/05 §4, §7).
 * Weights express how strongly a signal indicates misconduct: a tab switch is weak; a
 * detected phone or a virtual machine is strong. A proctoring policy may override these.
 */
final class FlagCatalog
{
    public const DEFAULT_WEIGHT = 0.3;

    /** type => weight in [0,1] */
    public const WEIGHTS = [
        // device / browser (tier 1)
        'tab_switch' => 0.15,
        'app_switch' => 0.2,
        'clipboard_attempt' => 0.25,
        'devtools_open' => 0.6,
        'extension_detected' => 0.3,
        'multi_monitor' => 0.4,
        'vm_detected' => 0.85,
        'remote_desktop' => 0.85,
        // on-device CV (tier 2)
        'gaze_away' => 0.2,
        'face_absent' => 0.5,
        'multiple_faces' => 0.75,
        'headset_detected' => 0.35,
        // server inference (tier 3)
        'phone_detected' => 0.8,
        'external_screen' => 0.6,
        'voice_detected' => 0.4,
        'background_voice' => 0.45,
        'identity_mismatch' => 0.9,
        // cross-session analytics (tier 4)
        'ai_assist_suspected' => 0.7,
        'answer_anomaly' => 0.55,
        'collusion_suspected' => 0.9,
    ];

    public static function isKnown(string $type): bool
    {
        return array_key_exists($type, self::WEIGHTS);
    }

    public static function weight(string $type, array $overrides = []): float
    {
        return (float) ($overrides[$type] ?? self::WEIGHTS[$type] ?? self::DEFAULT_WEIGHT);
    }

    /** @return string[] */
    public static function types(): array
    {
        return array_keys(self::WEIGHTS);
    }
}
