<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final class RoutePolicyRegistry
{
    /** @var array<string, array{role: AdministrativeRole, methods: list<string>, sensitive: bool}> */
    private const POLICIES = [
        'admin_dashboard' => ['role' => AdministrativeRole::Auditor, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_logout' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => false],
        'admin_reauthenticate' => ['role' => AdministrativeRole::Auditor, 'methods' => ['GET', 'HEAD', 'POST'], 'sensitive' => false],
        'admin_profile' => ['role' => AdministrativeRole::Auditor, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_password_change' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => true],
        'admin_sessions' => ['role' => AdministrativeRole::Auditor, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_session_revoke' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => true],
        'admin_mfa' => ['role' => AdministrativeRole::Auditor, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_mfa_start' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => true],
        'admin_mfa_confirm' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => true],
        'admin_mfa_disable' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => true],
        'admin_mfa_recovery' => ['role' => AdministrativeRole::Auditor, 'methods' => ['POST'], 'sensitive' => true],
        'admin_users' => ['role' => AdministrativeRole::Administrator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_user_create' => ['role' => AdministrativeRole::Administrator, 'methods' => ['POST'], 'sensitive' => true],
        'admin_user_update' => ['role' => AdministrativeRole::Administrator, 'methods' => ['POST'], 'sensitive' => true],
        'admin_user_delete' => ['role' => AdministrativeRole::Administrator, 'methods' => ['POST'], 'sensitive' => true],
        'admin_audit' => ['role' => AdministrativeRole::Auditor, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_contacts' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_contact_create' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_contact_update' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_contact_archive' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_contact_reveal' => ['role' => AdministrativeRole::Administrator, 'methods' => ['POST'], 'sensitive' => true],
        'admin_contact_field_create' => ['role' => AdministrativeRole::Administrator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_lists' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_list_create' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_list_archive' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_membership_add' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_membership_remove' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_imports' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_import_upload' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_import_details' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_import_preview' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_import_commit' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_import_report' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_contact_export' => ['role' => AdministrativeRole::Administrator, 'methods' => ['POST'], 'sensitive' => true],
        'admin_suppressions' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_suppression_add' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_templates' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_template_create' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_template_versions' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_template_version_create' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_template_publish' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_template_archive' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_template_preview' => ['role' => AdministrativeRole::Operator, 'methods' => ['GET', 'HEAD'], 'sensitive' => false],
        'admin_template_test' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
        'admin_template_asset_upload' => ['role' => AdministrativeRole::Operator, 'methods' => ['POST'], 'sensitive' => false],
    ];

    /** @return array{role: AdministrativeRole, methods: list<string>, sensitive: bool}|null */
    public function policy(string $route): ?array
    {
        return self::POLICIES[$route] ?? null;
    }

    /** @return array<string, array{role: AdministrativeRole, methods: list<string>, sensitive: bool}> */
    public function all(): array
    {
        return self::POLICIES;
    }
}
