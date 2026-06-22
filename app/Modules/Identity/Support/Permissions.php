<?php

namespace App\Modules\Identity\Support;

/**
 * The permission catalog and the system-role definitions (docs/01 §4.1, docs/04 §5).
 *
 * Permission keys are namespaced `context.aggregate.action`. System roles map to sets of
 * these keys; institution-specific custom roles are created at runtime. This is the
 * single source of truth the provisioner seeds from.
 */
final class Permissions
{
    // Question Bank
    public const QB_ITEM_CREATE = 'questionbank.item.create';

    public const QB_ITEM_READ = 'questionbank.item.read';

    public const QB_ITEM_UPDATE = 'questionbank.item.update';

    public const QB_ITEM_REVIEW = 'questionbank.item.review';

    public const QB_ITEM_IMPORT = 'questionbank.item.import';

    // Assessment Authoring
    public const ASSESSMENT_CREATE = 'authoring.assessment.create';

    public const ASSESSMENT_READ = 'authoring.assessment.read';

    public const ASSESSMENT_UPDATE = 'authoring.assessment.update';

    public const ASSESSMENT_PUBLISH = 'authoring.assessment.publish';

    public const BLUEPRINT_MANAGE = 'authoring.blueprint.manage';

    public const SCORING_RULE_MANAGE = 'authoring.scoringrule.manage';

    // Exam Delivery & Scoring
    public const SITTING_ASSIGN = 'delivery.sitting.assign';

    public const SITTING_TAKE = 'delivery.sitting.take';

    public const SCORE_READ = 'scoring.score.read';

    // Analytics & Psychometrics
    public const ANALYTICS_READ = 'analytics.read';

    public const ANALYTICS_COMPUTE = 'analytics.compute';

    // Identity / administration
    public const IAM_USER_MANAGE = 'iam.user.manage';

    public const IAM_ROLE_MANAGE = 'iam.role.manage';

    /** @return string[] every permission key in the catalog */
    public static function all(): array
    {
        return [
            self::QB_ITEM_CREATE, self::QB_ITEM_READ, self::QB_ITEM_UPDATE,
            self::QB_ITEM_REVIEW, self::QB_ITEM_IMPORT,
            self::ASSESSMENT_CREATE, self::ASSESSMENT_READ, self::ASSESSMENT_UPDATE,
            self::ASSESSMENT_PUBLISH, self::BLUEPRINT_MANAGE, self::SCORING_RULE_MANAGE,
            self::SITTING_ASSIGN, self::SITTING_TAKE, self::SCORE_READ,
            self::ANALYTICS_READ, self::ANALYTICS_COMPUTE,
            self::IAM_USER_MANAGE, self::IAM_ROLE_MANAGE,
        ];
    }

    /** The special role whose holders bypass all permission checks (Gate::before). */
    public const ROLE_PLATFORM_SUPER_ADMIN = 'platform_super_admin';

    /**
     * System roles -> permission keys. '*' means "every permission" and is resolved at
     * seed time. Institution Admin gets everything within a tenant; the platform super
     * admin additionally short-circuits the Gate.
     *
     * @return array<string, string[]|string>
     */
    public static function systemRoles(): array
    {
        return [
            self::ROLE_PLATFORM_SUPER_ADMIN => '*',
            'institution_admin' => '*',
            'exam_officer' => [
                self::QB_ITEM_READ, self::QB_ITEM_IMPORT, self::QB_ITEM_CREATE,
                self::ASSESSMENT_CREATE, self::ASSESSMENT_READ, self::ASSESSMENT_UPDATE,
                self::ASSESSMENT_PUBLISH, self::BLUEPRINT_MANAGE, self::SCORING_RULE_MANAGE,
                self::SITTING_ASSIGN, self::SCORE_READ,
                self::ANALYTICS_READ, self::ANALYTICS_COMPUTE,
            ],
            'question_author' => [
                self::QB_ITEM_CREATE, self::QB_ITEM_READ, self::QB_ITEM_UPDATE, self::QB_ITEM_IMPORT,
            ],
            'question_reviewer' => [
                self::QB_ITEM_READ, self::QB_ITEM_REVIEW,
            ],
            'question_approver' => [
                self::QB_ITEM_READ, self::QB_ITEM_REVIEW,
            ],
            'student' => [
                self::SITTING_TAKE, self::SCORE_READ,
            ],
        ];
    }
}
