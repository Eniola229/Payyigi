<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'description', // Optional: add description for roles
    ];

    /**
     * Get all admins with this role.
     */
    public function admins()
    {
        return $this->belongsToMany(
            config('auth.providers.admins.model', Admin::class),
            'model_has_roles',
            'role_id',
            'model_id'
        )->where('model_has_roles.model_type', Admin::class);
    }

    /**
     * Check if role is a super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->name === 'super_admin';
    }

    /**
     * Scope a query to only include roles for admin guard.
     */
    public function scopeForAdminGuard($query)
    {
        return $query->where('guard_name', 'admin');
    }

    /**
     * Get role description based on role name.
     */
    public function getDescriptionAttribute(): string
    {
        return match($this->name) {
            'super_admin' => 'Full system access with all permissions',
            'finance_manager' => 'Manages financial operations, transactions, and revenue',
            'fraud_analyst' => 'Detects and investigates fraudulent activities',
            'compliance_officer' => 'Ensures regulatory compliance and handles user restrictions',
            'support_agent' => 'Provides customer support and basic transaction viewing',
            'transaction_monitor' => 'Monitors and flags suspicious transactions',
            default => 'No description available'
        };
    }

    /**
     * Get all permissions grouped by category.
     */
    public function getGroupedPermissionsAttribute(): array
    {
        $permissions = $this->permissions->pluck('name')->toArray();
        
        $groups = [
            'Users' => array_intersect($permissions, [
                'view_users', 'suspend_users', 'unsuspend_users', 'delete_users'
            ]),
            'Transactions' => array_intersect($permissions, [
                'view_transactions', 'flag_transactions', 'reverse_transactions'
            ]),
            'Fraud' => array_intersect($permissions, [
                'view_fraud_flags', 'create_fraud_flags', 'resolve_fraud_flags'
            ]),
            'Withdrawals' => array_intersect($permissions, [
                'view_withdrawals', 'manage_withdrawals'
            ]),
            'Revenue' => array_intersect($permissions, ['view_revenue']),
            'Logs' => array_intersect($permissions, [
                'view_webhooks', 'view_audit_logs'
            ]),
            'Admins' => array_intersect($permissions, [
                'view_admins', 'create_admins', 'edit_admins', 'delete_admins'
            ]),
            'Dashboard' => array_intersect($permissions, ['view_dashboard_stats']),
        ];
        
        return array_filter($groups, fn($group) => !empty($group));
    }
}