<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\MultiSessionAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Keeps the accounts a browser is signed in to.
 *
 * A browser can hold several accounts at once: every completed sign-in adds the
 * account to an encrypted cookie, and a switch signs the browser in as one of
 * them without a password. The raw switch tokens only ever live in that cookie;
 * the database stores their hashes, so revoking an account's sessions also
 * revokes every switch token it holds.
 *
 * Signing out of one account removes just that account; the others stay signed
 * in, which is what makes switching cheap.
 *
 * @phpstan-type RegistryEntry array{id: int, name: string, email: string, token: string}
 */
class MultiSession
{
    /** The encrypted cookie holding this browser's signed-in accounts. */
    public const COOKIE = 'koamishin_accounts';

    /** How long a switch token stays valid, in days. */
    public const LIFETIME_DAYS = 30;

    /** The most accounts one browser can hold; the oldest are dropped. */
    public const MAX_ACCOUNTS = 8;

    /**
     * Record a freshly authenticated account on this browser.
     *
     * Every sign-in mints a new switch token and replaces any the account
     * already had here, so signing in again always extends access.
     */
    public function attach(User $user, ?Request $request = null): void
    {
        $request ??= request();

        // One sign-in can fire the Login event once per guard; the account must
        // be registered exactly once, so only the first event counts.
        $attached = $request->attributes->get('koamishin.multi_session_attached', []);

        if (in_array((int) $user->getKey(), array_map(intval(...), $attached), true)) {
            return;
        }

        $request->attributes->set('koamishin.multi_session_attached', [...$attached, (int) $user->getKey()]);

        $entries = $this->entries($request);

        foreach ($entries as $index => $entry) {
            if ((int) $entry['id'] === (int) $user->getKey()) {
                $this->deleteToken($entry['token']);
                unset($entries[$index]);
            }
        }

        $token = Str::random(48);

        MultiSessionAccount::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hash($token),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'created_at' => now(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        $entries[] = [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'token' => $token,
        ];

        foreach (array_slice($entries, 0, max(0, count($entries) - self::MAX_ACCOUNTS)) as $dropped) {
            $this->deleteToken($dropped['token']);
        }

        $this->store($request, array_slice($entries, -self::MAX_ACCOUNTS));
    }

    /**
     * The accounts this browser can switch to, with the signed-in one marked.
     *
     * @return array<int, array{id: int, name: string, email: string, current: bool}>
     */
    public function accounts(?Request $request = null): array
    {
        $request ??= request();
        $entries = $this->entries($request);

        if ($entries === []) {
            return [];
        }

        $rows = MultiSessionAccount::query()
            ->with('user')
            ->whereIn('token_hash', array_map(fn (array $entry): string => $this->hash($entry['token']), $entries))
            ->get()
            ->keyBy('token_hash');

        $currentId = (int) (Auth::guard(config('fortify.guard', 'web'))->id() ?? 0);
        $accounts = [];
        $alive = [];

        foreach ($entries as $entry) {
            $row = $rows->get($this->hash($entry['token']));
            $user = $row?->user;

            if ($row === null || $row->isExpired() || $user === null || $user->isBanned()) {
                continue;
            }

            $alive[] = $entry;
            $accounts[] = [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'current' => (int) $user->getKey() === $currentId,
            ];
        }

        if (count($alive) !== count($entries)) {
            $this->store($request, $alive);
        }

        return $accounts;
    }

    /**
     * The account behind a registry entry, if this browser may still use it.
     */
    public function userFor(Request $request, mixed $userId): ?User
    {
        $entry = $this->entryFor($request, $userId);

        if ($entry === null) {
            return null;
        }

        $row = MultiSessionAccount::query()
            ->where('token_hash', $this->hash($entry['token']))
            ->first();

        $user = $row?->user;

        if ($row === null || $row->isExpired() || $user === null || $user->isBanned()) {
            return null;
        }

        $row->forceFill(['last_used_at' => now()])->save();

        return $user;
    }

    /**
     * Sign this browser in as one of its accounts.
     *
     * The account-specific session state is dropped, so nothing leaks from the
     * account being left behind, but the pinned session lifetime is kept: the
     * session itself continues, and an administrator changing the limits must
     * not sign people out mid-use.
     */
    public function switchTo(Request $request, User $user): void
    {
        $session = $request->session();
        $pinned = $session->get(EnforceSessionLifetime::SESSION_KEY);

        $session->flush();

        if (is_array($pinned)) {
            $session->put(EnforceSessionLifetime::SESSION_KEY, $pinned);
        }

        $session->regenerateToken();
        $session->regenerate();

        Auth::guard(config('fortify.guard', 'web'))->login($user);

        if ($user->canAccessPanel(Filament::getPanel('admin'))) {
            Auth::guard('portal')->login($user);
        } else {
            // A portal login left behind by the previous account must not
            // survive the switch.
            Auth::guard('portal')->logout();
        }
    }

    /**
     * Drop one account from this browser.
     *
     * Removing the signed-in account ends the session as well; the remaining
     * accounts stay signed in.
     */
    public function remove(Request $request, mixed $userId): bool
    {
        $entry = $this->entryFor($request, $userId);

        if ($entry === null) {
            return false;
        }

        $this->deleteToken($entry['token']);
        $this->store($request, array_values(array_filter(
            $this->entries($request),
            fn (array $candidate): bool => (int) $candidate['id'] !== (int) $entry['id'],
        )));

        if ((int) $entry['id'] === (int) Auth::guard(config('fortify.guard', 'web'))->id()) {
            Auth::guard('portal')->logout();
            Auth::guard(config('fortify.guard', 'web'))->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return true;
    }

    /**
     * Drop one account's tokens without touching the session.
     *
     * Used when a sign-out ends the session by other means; the account simply
     * stops being switchable here.
     */
    public function forget(User $user, ?Request $request = null): void
    {
        $request ??= request();
        $entries = $this->entries($request);
        $kept = [];

        foreach ($entries as $entry) {
            if ((int) $entry['id'] === (int) $user->getKey()) {
                $this->deleteToken($entry['token']);
            } else {
                $kept[] = $entry;
            }
        }

        if (count($kept) !== count($entries)) {
            $this->store($request, $kept);
        }
    }

    /**
     * Sign this browser out of every account.
     */
    public function flush(Request $request): void
    {
        foreach ($this->entries($request) as $entry) {
            $this->deleteToken($entry['token']);
        }

        $this->store($request, []);

        Auth::guard('portal')->logout();
        Auth::guard(config('fortify.guard', 'web'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * @return array<int, RegistryEntry>
     */
    private function entries(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function (mixed $entry): bool {
            return is_array($entry)
                && isset($entry['id'], $entry['name'], $entry['email'], $entry['token'])
                && is_string($entry['token']);
        }));
    }

    /**
     * @param  array<int, RegistryEntry>  $entries
     */
    private function store(Request $request, array $entries): void
    {
        $cookie = $entries === []
            ? cookie(self::COOKIE, null, -1)
            : cookie(self::COOKIE, json_encode(array_values($entries)), self::LIFETIME_DAYS * 24 * 60, httpOnly: true, sameSite: 'lax');

        app('cookie')->queue($cookie);
    }

    /**
     * @return RegistryEntry|null
     */
    private function entryFor(Request $request, mixed $userId): ?array
    {
        foreach ($this->entries($request) as $entry) {
            if ((int) $entry['id'] === (int) $userId) {
                return $entry;
            }
        }

        return null;
    }

    private function deleteToken(string $token): void
    {
        MultiSessionAccount::query()->where('token_hash', $this->hash($token))->delete();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
