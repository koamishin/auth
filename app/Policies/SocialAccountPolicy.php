<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialAccount;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SocialAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SocialAccount');
    }

    public function view(AuthUser $authUser, SocialAccount $socialAccount): bool
    {
        return $authUser->can('View:SocialAccount');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SocialAccount');
    }

    public function update(AuthUser $authUser, SocialAccount $socialAccount): bool
    {
        return $authUser->can('Update:SocialAccount');
    }

    public function delete(AuthUser $authUser, SocialAccount $socialAccount): bool
    {
        return $authUser->can('Delete:SocialAccount');
    }

    public function restore(AuthUser $authUser, SocialAccount $socialAccount): bool
    {
        return $authUser->can('Restore:SocialAccount');
    }

    public function forceDelete(AuthUser $authUser, SocialAccount $socialAccount): bool
    {
        return $authUser->can('ForceDelete:SocialAccount');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SocialAccount');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SocialAccount');
    }

    public function replicate(AuthUser $authUser, SocialAccount $socialAccount): bool
    {
        return $authUser->can('Replicate:SocialAccount');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SocialAccount');
    }
}
