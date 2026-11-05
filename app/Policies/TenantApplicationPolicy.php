<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TenantApplication;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TenantApplicationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TenantApplication');
    }

    public function view(AuthUser $authUser, TenantApplication $tenantApplication): bool
    {
        return $authUser->can('View:TenantApplication');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TenantApplication');
    }

    public function update(AuthUser $authUser, TenantApplication $tenantApplication): bool
    {
        return $authUser->can('Update:TenantApplication');
    }

    public function delete(AuthUser $authUser, TenantApplication $tenantApplication): bool
    {
        return $authUser->can('Delete:TenantApplication');
    }

    public function restore(AuthUser $authUser, TenantApplication $tenantApplication): bool
    {
        return $authUser->can('Restore:TenantApplication');
    }

    public function forceDelete(AuthUser $authUser, TenantApplication $tenantApplication): bool
    {
        return $authUser->can('ForceDelete:TenantApplication');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TenantApplication');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TenantApplication');
    }

    public function replicate(AuthUser $authUser, TenantApplication $tenantApplication): bool
    {
        return $authUser->can('Replicate:TenantApplication');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TenantApplication');
    }
}
