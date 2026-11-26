<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';

/**
 * Operationalizes RA 10173 / NPC Circular 2023-06 governance helpers for AGAP:
 * privacy notice versioning, consent recording, retention schedule inspection,
 * and security incident logging.
 */
final class PrivacyGovernanceService
{
    public const NOTICE_VERSION = 'RA10173-NPC2023-06-v1';

    /**
     * Approved retention guidelines (in days) aligned with Barangay/KP record rules.
     */
    public const RETENTION_POLICIES = [
        'anonymous_concerns_closed' => 365,
        'soft_deleted_complaints_grace' => 1825, // 5 years preservation before authorized archival review
        'audit_trails' => 3650,                 // 10 years immutable audit retention
        'password_reset_tokens_expired' => 30,
    ];

    public static function noticeSummary(): array
    {
        return [
            'version' => self::NOTICE_VERSION,
            'statute' => 'Republic Act No. 10173 (Data Privacy Act of 2012) & NPC Circular No. 2023-06',
            'controller' => 'Lupong Tagapamayapa, Barangay Tumana, Marikina City',
            'lawful_basis' => 'Performance of a statutory public function under the Katarungang Pambarangay Law (RA 7160 §§399–422) and explicit consent for optional AI-assisted drafting.',
            'purpose' => 'Dispute intake, screening, Paanyaya/Mediation/Conciliation scheduling, official KP form generation, and authorized status tracking.',
            'data_minimization' => 'Only dispute-relevant party identifiers and incident facts are collected; general anonymous concerns do not collect personal identity fields.',
            'security_controls' => [
                'AES-256-GCM authenticated encryption for stored resident/party/account contact & address fields',
                'Role- and assignment-based case authorization (RBAC)',
                'Append-only cryptographic hash-chained audit_trails',
                'Soft deletion restricted to Super Administrator governance',
            ],
        ];
    }

    public static function recordPrivacyIncident(PDO $db, ?int $actorId, string $summary, array $details = []): bool
    {
        $clean = trim($summary);
        if ($clean === '') {
            return false;
        }
        return (new AuditService($db))->log(
            $actorId,
            'Privacy / Security Incident Logged',
            'PrivacyGovernance',
            null,
            [
                'after' => [
                    'summary' => mb_substr($clean, 0, 500),
                    'details' => $details,
                    'notice_version' => self::NOTICE_VERSION,
                ],
            ]
        );
    }

    public static function retentionAuditSummary(PDO $db): array
    {
        $closedAnon = (int) $db->query(
            "SELECT COUNT(*) FROM anonymous_concerns WHERE status = 'Closed' AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::RETENTION_POLICIES['anonymous_concerns_closed'] . ' DAY)'
        )->fetchColumn();

        $softDeleted = (int) $db->query(
            'SELECT COUNT(*) FROM complaints WHERE deleted_at IS NOT NULL'
        )->fetchColumn();

        $expiredTokens = (int) $db->query(
            'SELECT COUNT(*) FROM password_reset_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_POLICIES['password_reset_tokens_expired'] . ' DAY)'
        )->fetchColumn();

        return [
            'notice_version' => self::NOTICE_VERSION,
            'policies_days' => self::RETENTION_POLICIES,
            'eligible_anonymous_closed_for_disposal' => $closedAnon,
            'preserved_soft_deleted_complaints' => $softDeleted,
            'expired_reset_tokens' => $expiredTokens,
        ];
    }
}
