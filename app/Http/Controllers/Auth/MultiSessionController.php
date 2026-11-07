<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\MultiSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Switches the browser between the accounts it is signed in to.
 *
 * These endpoints are reachable signed out as well as signed in: the registry
 * cookie is what authorizes a switch, so "Continue as" works after signing out
 * of the current account while others remain.
 */
class MultiSessionController extends Controller
{
    public function __construct(private readonly MultiSession $sessions) {}

    public function switch(Request $request): RedirectResponse
    {
        $request->validate(['user_id' => ['required']]);

        $user = $this->sessions->userFor($request, $request->input('user_id'));

        if (!$user instanceof \App\Models\User) {
            return back()->withErrors(['account' => 'That account is no longer available in this browser.']);
        }

        $this->sessions->switchTo($request, $user);

        return redirect()->to('/dashboard');
    }

    public function remove(Request $request): RedirectResponse
    {
        $request->validate(['user_id' => ['required']]);

        if (! $this->sessions->remove($request, $request->input('user_id'))) {
            return back()->withErrors(['account' => 'That account is no longer available in this browser.']);
        }

        if (Auth::guard(config('fortify.guard', 'web'))->guest()) {
            return redirect()->route('login')->with('status', 'You have been signed out.');
        }

        return back()->with('status', 'Account removed.');
    }

    public function flush(Request $request): RedirectResponse
    {
        $this->sessions->flush($request);

        return redirect()->route('login')->with('status', 'You have been signed out of all accounts.');
    }
}
