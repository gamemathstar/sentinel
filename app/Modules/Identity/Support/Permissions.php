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

    public const QB_BANK_CREATE = 'questionbank.bank.create';

    public const QB_BANK_SHARE = 'questionbank.bank.share';

    /** Read/manage every bank in the tenant, bypassing per-bank visibility. */
    public const QB_BANK_MANAGE_ALL = 'questionbank.bank.manage_all';

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

    // Grading (open-ended marking)
    public const GRADING_MARK = 'grading.mark';

    public const GRADING_RECONCILE = 'grading.reconcile';

    public const GRADING_READ = 'grading.read';

    // Analytics & Psychometrics
    public const ANALYTICS_READ = 'analytics.read';

    public const ANALYTICS_COMPUTE = 'analytics.compute';

    // Proctoring
    public const PROCTOR_MONITOR = 'proctoring.monitor';

    public const PROCTOR_REVIEW = 'proctoring.review';

    // Certification
    public const CERT_ISSUE = 'certification.issue';

    public const CERT_READ = 'certification.read';

    public const CERT_REVOKE = 'certification.revoke';

    // Reporting
    public const REPORT_GENERATE = 'reporting.generate';

    public const REPORT_READ = 'reporting.read';

    // Notifications
    public const NOTIFICATION_SEND = 'notifications.send';

    public const NOTIFICATION_READ = 'notifications.read';

    // Scheduling & Timetabling
    public const SCHEDULE_MANAGE = 'scheduling.manage';

    public const SCHEDULE_READ = 'scheduling.read';

    // Identity / administration
    public const IAM_USER_MANAGE = 'iam.user.manage';

    public const IAM_ROLE_MANAGE = 'iam.role.manage';

    /** @return string[] every permission key in the catalog */
    public static function all(): array
    {
        return [
            self::QB_ITEM_CREATE, self::QB_ITEM_READ, self::QB_ITEM_UPDATE,
            self::QB_ITEM_REVIEW, self::QB_ITEM_IMPORT,
            self::QB_BANK_CREATE, self::QB_BANK_SHARE, self::QB_BANK_MANAGE_ALL,
            self::ASSESSMENT_CREATE, self::ASSESSMENT_READ, self::ASSESSMENT_UPDATE,
            self::ASSESSMENT_PUBLISH, self::BLUEPRINT_MANAGE, self::SCORING_RULE_MANAGE,
            self::SITTING_ASSIGN, self::SITTING_TAKE, self::SCORE_READ,
            self::GRADING_MARK, self::GRADING_RECONCILE, self::GRADING_READ,
            self::ANALYTICS_READ, self::ANALYTICS_COMPUTE,
            self::PROCTOR_MONITOR, self::PROCTOR_REVIEW,
            self::CERT_ISSUE, self::CERT_READ, self::CERT_REVOKE,
            self::REPORT_GENERATE, self::REPORT_READ,
            self::NOTIFICATION_SEND, self::NOTIFICATION_READ,
            self::SCHEDULE_MANAGE, self::SCHEDULE_READ,
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
                self::QB_BANK_CREATE, self::QB_BANK_SHARE, self::QB_BANK_MANAGE_ALL,
                self::ASSESSMENT_CREATE, self::ASSESSMENT_READ, self::ASSESSMENT_UPDATE,
                self::ASSESSMENT_PUBLISH, self::BLUEPRINT_MANAGE, self::SCORING_RULE_MANAGE,
                self::SITTING_ASSIGN, self::SCORE_READ,
                self::SCHEDULE_MANAGE, self::SCHEDULE_READ,
                self::ANALYTICS_READ, self::ANALYTICS_COMPUTE,
                self::GRADING_RECONCILE, self::GRADING_READ, // senior reconciler
                self::CERT_ISSUE, self::CERT_READ, self::CERT_REVOKE,
                self::PROCTOR_MONITOR, self::PROCTOR_REVIEW,
                self::REPORT_GENERATE, self::REPORT_READ,
                self::NOTIFICATION_SEND, self::NOTIFICATION_READ,
            ],
            'grader' => [
                self::GRADING_MARK, self::GRADING_READ,
            ],
            'proctor' => [
                self::PROCTOR_MONITOR, self::SCHEDULE_READ,
            ],
            'question_author' => [
                self::QB_ITEM_CREATE, self::QB_ITEM_READ, self::QB_ITEM_UPDATE, self::QB_ITEM_IMPORT,
                self::QB_BANK_CREATE, self::QB_BANK_SHARE,
            ],
            'question_reviewer' => [
                self::QB_ITEM_READ, self::QB_ITEM_REVIEW,
            ],
            'question_approver' => [
                self::QB_ITEM_READ, self::QB_ITEM_REVIEW,
            ],
            'student' => [
                self::SITTING_TAKE, self::SCORE_READ, self::NOTIFICATION_READ, self::SCHEDULE_READ,
            ],
        ];
    }
}
