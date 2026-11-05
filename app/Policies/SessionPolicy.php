<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Session;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Session');
    }

    public function view(AuthUser $authUser, Session $session): bool
    {
        return $authUser->can('View:Session');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Session');
    }

    public function update(AuthUser $authUser, Session $session): bool
    {
        return $authUser->can('Update:Session');
    }

    public function delete(AuthUser $authUser, Session $session): bool
    {
        return $authUser->can('Delete:Session');
    }

    public function restore(AuthUser $authUser, Session $session): bool
    {
        return $authUser->can('Restore:Session');
    }

    public function forceDelete(AuthUser $authUser, Session $session): bool
    {
        return $authUser->can('ForceDelete:Session');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Session');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Session');
    }

    public function replicate(AuthUser $authUser, Session $session): bool
    {
        return $authUser->can('Replicate:Session');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Session');
    }
}
