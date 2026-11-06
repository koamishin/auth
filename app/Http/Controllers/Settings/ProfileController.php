<?php

namespace App\Http\Controllers\Settings;

use App\Features\FeatureRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\TenantApplicationEnvironment;
use App\Support\ApplicationContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        FeatureRegistry::initialize();

        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'collectsNameParts' => $this->environment($request)?->collectsNameParts() ?? false,
            'allowsAccountDeletion' => $this->environment($request)?->allowsAccountDeletion() ?? true,
        ]);
    }

    /**
     * The application environment the request is being served for, if any.
     */
    private function environment(Request $request): ?TenantApplicationEnvironment
    {
        return ApplicationContext::environment($request);
    }

    public function update(ProfileUpdateRequest $profileUpdateRequest): RedirectResponse
    {
        $user = $profileUpdateRequest->user();

        if (! FeatureRegistry::isFeatureAvailableForUser($user, 'settings_profile')) {
            return to_route('profile.edit')->with('error', 'Profile editing is not available for your role');
        }

        $data = $profileUpdateRequest->validated();

        // Applications that collect a first and last name keep the display name
        // in step with them.
        $displayName = $profileUpdateRequest->displayNameFrom($data, $user->name);

        if (is_string($displayName) && $displayName !== '') {
            $data['name'] = $displayName;
        }

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:1024'],
        ]);

        $user = $request->user();

        if (! FeatureRegistry::isFeatureAvailableForUser($user, 'settings_profile')) {
            return to_route('profile.edit')->with('error', 'Profile editing is not available for your role');
        }

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $request->file('photo')->store('profile-photos', 'public');

        $user->update(['profile_photo_path' => $path]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo updated.')]);

        return to_route('profile.edit');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! FeatureRegistry::isFeatureAvailableForUser($user, 'settings_profile')) {
            return to_route('profile.edit')->with('error', 'Profile editing is not available for your role');
        }

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->update(['profile_photo_path' => null]);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo removed.')]);
        }

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $profileDeleteRequest): RedirectResponse
    {
        $user = $profileDeleteRequest->user();

        // An application can forbid its own users from deleting their accounts.
        $environment = $profileDeleteRequest->attributes->get('koamishin.application_environment');

        if ($environment instanceof TenantApplicationEnvironment && ! $environment->allowsAccountDeletion()) {
            return to_route('profile.edit')->with('error', 'This application does not allow accounts to be deleted.');
        }

        if (! FeatureRegistry::isFeatureAvailableForUser($user, 'settings_account_deletion')) {
            return to_route('profile.edit')->with('error', 'Account deletion is not available for your role');
        }

        Auth::logout();

        $user->delete();

        $profileDeleteRequest->session()->invalidate();
        $profileDeleteRequest->session()->regenerateToken();

        return redirect('/');
    }
}
