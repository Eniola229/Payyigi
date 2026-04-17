<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions ───────────────────────────────────────────────────────
        $permissions = [
            // Users
            'view_users', 'suspend_users', 'unsuspend_users', 'delete_users',

            // Transactions
            'view_transactions', 'flag_transactions', 'reverse_transactions',

            // Fraud
            'view_fraud_flags', 'create_fraud_flags', 'resolve_fraud_flags',

            // Withdrawals
            'view_withdrawals', 'manage_withdrawals',

            // Revenue
            'view_revenue',

            // Webhooks & Logs
            'view_webhooks', 'view_audit_logs',

            // Admins
            'view_admins', 'create_admins', 'edit_admins', 'delete_admins',

            // Dashboard
            'view_dashboard_stats',
        ];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'admin');
        }

        // ── Roles ─────────────────────────────────────────────────────────────
        $superAdmin = Role::findOrCreate('super_admin', 'admin');
        $superAdmin->givePermissionTo(Permission::where('guard_name', 'admin')->get());

        $financeManager = Role::findOrCreate('finance_manager', 'admin');
        $financeManager->givePermissionTo([
            'view_dashboard_stats', 'view_transactions',
            'view_withdrawals', 'view_revenue', 'view_users',
        ]);

        $fraudAnalyst = Role::findOrCreate('fraud_analyst', 'admin');
        $fraudAnalyst->givePermissionTo([
            'view_dashboard_stats', 'view_transactions', 'view_users',
            'flag_transactions', 'view_fraud_flags',
            'create_fraud_flags', 'resolve_fraud_flags',
            'suspend_users',
        ]);

        $complianceOfficer = Role::findOrCreate('compliance_officer', 'admin');
        $complianceOfficer->givePermissionTo([
            'view_dashboard_stats', 'view_users', 'view_transactions',
            'view_fraud_flags', 'resolve_fraud_flags',
            'suspend_users', 'unsuspend_users', 'view_audit_logs',
        ]);

        $supportAgent = Role::findOrCreate('support_agent', 'admin');
        $supportAgent->givePermissionTo([
            'view_users', 'view_transactions', 'view_withdrawals',
        ]);

        $transactionMonitor = Role::findOrCreate('transaction_monitor', 'admin');
        $transactionMonitor->givePermissionTo([
            'view_dashboard_stats', 'view_transactions',
            'view_withdrawals', 'view_fraud_flags', 'create_fraud_flags',
        ]);

        // ── Create first Super Admin ──────────────────────────────────────────
        $admin = Admin::firstOrCreate(
            ['email' => 'joshua@payyigi.com'],
            [
                'first_name' => 'Joshua',
                'last_name'  => 'Adeyemi',
                'password'   => '123456',
                'is_active'  => true,
            ]
        );

        $admin->assignRole('super_admin');
    }
}