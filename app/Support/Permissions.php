<?php

namespace App\Support;

/**
 * Granular admin permissions and their default role mapping
 * (roles from backend-handoff I.2: member, content_editor, finance, admin, super_admin).
 */
final class Permissions
{
    public const ALL = [
        'dashboard.view' => 'View the executive dashboard',
        'users.view' => 'View members and their activity',
        'users.manage' => 'Suspend, reactivate and sign out members',
        'content.view' => 'View content',
        'content.manage' => 'Create, edit and publish content (hymns, devotionals, lessons, events, branches, pages, Bible)',
        'finance.view' => 'View payments, subscriptions and financial reports',
        'finance.manage' => 'Re-verify payments, record refunds, manage plans',
        'notifications.view' => 'View push campaigns and delivery logs',
        'notifications.send' => 'Create, schedule and send push notifications',
        'feedback.view' => 'View member feedback',
        'feedback.manage' => 'Triage and resolve feedback',
        'app.manage' => 'Manage mobile app configuration, versions and announcements',
        'reports.view' => 'View reports',
        'reports.export' => 'Export reports (CSV)',
        'system.audit' => 'View audit and integration logs',
        'system.integrations' => 'View integration status and run connection tests',
        'system.jobs' => 'Monitor and retry background jobs',
        'system.admins' => 'Manage administrators, roles and permissions',
    ];

    public const ROLES = [
        'member' => [],
        'content_editor' => [
            'dashboard.view', 'content.view', 'content.manage', 'feedback.view', 'notifications.view',
        ],
        'finance' => [
            'dashboard.view', 'users.view', 'finance.view', 'reports.view', 'reports.export',
        ],
        'admin' => [
            'dashboard.view', 'users.view', 'users.manage', 'content.view', 'content.manage',
            'finance.view', 'finance.manage', 'notifications.view', 'notifications.send',
            'feedback.view', 'feedback.manage', 'app.manage', 'reports.view', 'reports.export',
            'system.audit', 'system.integrations', 'system.jobs',
        ],
        'super_admin' => [], // Gate::before grants everything
    ];

    public const ROLE_LABELS = [
        'member' => 'Member',
        'content_editor' => 'Content editor',
        'finance' => 'Finance',
        'admin' => 'Administrator',
        'super_admin' => 'Super administrator',
    ];
}
