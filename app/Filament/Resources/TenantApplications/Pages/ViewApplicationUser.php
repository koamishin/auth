<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApplicationUserMetadata;
use App\Models\Session;
use App\Models\SocialAccount;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkey;

/**
 * Clerk-style account view for a single user of an application environment:
 * identifiers and their verification state, authentication methods, active
 * sessions, metadata, and membership.
 */
class ViewApplicationUser extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.view-application-user';

    #[Unexposed]
    public int|string $userId;

    #[Unexposed]
    public function mount(int|string $record, int|string $userId): void
    {
        $this->record = $this->resolveRecord($record);
        $this->userId = $userId;

        $this->user();
    }

    public static function canAccess(array $parameters = []): bool
    {
        $application = $parameters['record'] ?? null;

        return $application instanceof TenantApplication
            && TenantApplicationResource::canView($application)
            && UserResource::canViewAny();
    }

    public function getTitle(): string
    {
        return $this->user()->name;
    }

    public function getHeading(): string
    {
        return 'User profile';
    }

    public function getSubheading(): ?string
    {
        return 'Account details, authentication methods, and sessions for '.$this->application()->name.'.';
    }

    public function application(): TenantApplication
    {
        /** @var TenantApplication $application */
        $application = $this->getRecord();

        return $application;
    }

    public function environment(): ?TenantApplicationEnvironment
    {
        return $this->application()->environments()
            ->where('environment', $this->application()->environment)
            ->first();
    }

    /**
     * The enrolled user, scoped to this application and environment.
     */
    public function user(): User
    {
        /** @var User $user */
        $user = $this->application()
            ->users($this->application()->environment)
            ->whereKey($this->userId)
            ->firstOrFail();

        return $user;
    }

    public function membership(): ?object
    {
        return DB::table('tenant_application_user')
            ->where('tenant_application_id', $this->application()->getKey())
            ->where('user_id', $this->user()->getKey())
            ->where('environment', $this->application()->environment)
            ->first();
    }

    public function metadata(): ?ApplicationUserMetadata
    {
        $environment = $this->environment();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return null;
        }

        return ApplicationUserMetadata::query()
            ->where('tenant_application_environment_id', $environment->getKey())
            ->where('user_id', $this->user()->getKey())
            ->first();
    }

    /**
     * @return Collection<int, Passkey>
     */
    public function passkeys(): Collection
    {
        return $this->user()->passkeys()->latest()->get();
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    public function socialAccounts(): Collection
    {
        return $this->user()->socialAccounts()->get();
    }

    /**
     * @return Collection<int, Session>
     */
    public function activeSessions(): Collection
    {
        return $this->user()->sessions()->orderByDesc('last_activity')->get();
    }

    public function tenantRole(): ?string
    {
        $role = DB::table('tenant_user')
            ->where('tenant_id', $this->application()->tenant_id)
            ->where('user_id', $this->user()->getKey())
            ->value('role');

        return is_string($role) ? $role : null;
    }

    /**
     * Terminate a single device session.
     */
    public function revokeSession(string $sessionId): void
    {
        $this->user()->sessions()->whereKey($sessionId)->delete();

        Notification::make()
            ->title('Session terminated')
            ->body('That device has been signed out.')
            ->warning()
            ->send();
    }

    /**
     * Remove a registered passkey.
     */
    public function deletePasskey(int|string $passkeyId): void
    {
        $this->user()->passkeys()->whereKey($passkeyId)->delete();

        Notification::make()
            ->title('Passkey removed')
            ->success()
            ->send();
    }

    /**
     * Disconnect a linked social account.
     */
    public function disconnectSocialAccount(int|string $accountId): void
    {
        $this->user()->socialAccounts()->whereKey($accountId)->delete();

        Notification::make()
            ->title('Account disconnected')
            ->success()
            ->send();
    }

    /**
     * Mark the email address as verified without user action.
     */
    public function verifyEmail(): void
    {
        $this->user()->forceFill(['email_verified_at' => now()])->save();

        Notification::make()->title('Email verified')->success()->send();
    }

    public function unverifyEmail(): void
    {
        $this->user()->forceFill(['email_verified_at' => null])->save();

        Notification::make()->title('Email marked unverified')->warning()->send();
    }

    public function verifyPhone(): void
    {
        $this->user()->forceFill(['phone_verified_at' => now()])->save();

        Notification::make()->title('Phone verified')->success()->send();
    }

    public function sendVerificationEmail(): void
    {
        $this->user()->sendEmailVerificationNotification();

        Notification::make()->title('Verification email sent')->success()->send();
    }

    public function revokeAllSessions(): void
    {
        $this->user()->revokeAllSessions();

        Notification::make()
            ->title('Sessions terminated')
            ->body('All active sessions and tokens have been cleared.')
            ->warning()
            ->send();
    }

    public function updateMetadata(array $public, array $private): void
    {
        $environment = $this->environment();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return;
        }

        $errors = $environment->validateMetadata(['public' => $public, 'private' => $private]);

        if ($errors !== []) {
            throw ValidationException::withMessages(['metadata' => array_values($errors)]);
        }

        $metadata = $this->metadata() ?? new ApplicationUserMetadata([
            'tenant_application_environment_id' => $environment->getKey(),
            'user_id' => $this->user()->getKey(),
        ]);

        $metadata->public_metadata = (object) $public;
        $metadata->private_metadata = (object) $private;
        $metadata->save();

        Notification::make()->title('Metadata saved')->success()->send();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('All users')
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(fn (): string => TenantApplicationResource::getUrl('users', [
                    'tenant' => $this->application()->tenant,
                    'record' => $this->application(),
                ])),

            Action::make('impersonate')
                ->label('Impersonate')
                ->icon('heroicon-o-user-plus')
                ->url(fn (): string => route('impersonate', $this->user()->getKey()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($admin = Auth::user()) instanceof User
                    && $admin->hasRole('admin')
                    && $this->user()->canBeImpersonated()),

            Action::make('editMetadata')
                ->label('Edit metadata')
                ->icon('heroicon-o-code-bracket-square')
                ->color('gray')
                ->fillForm(fn (): array => [
                    'public' => (array) ($this->metadata()->public_metadata ?? []),
                    'private' => (array) ($this->metadata()->private_metadata ?? []),
                ])
                ->form([
                    KeyValue::make('public')
                        ->label('Public metadata')
                        ->keyLabel('Attribute')
                        ->valueLabel('Value')
                        ->helperText('Returned in tokens and the management API.'),
                    KeyValue::make('private')
                        ->label('Private metadata')
                        ->keyLabel('Attribute')
                        ->valueLabel('Value')
                        ->helperText('Readable by the application backend only.'),
                ])
                ->action(fn (array $data) => $this->updateMetadata(
                    (array) ($data['public'] ?? []),
                    (array) ($data['private'] ?? []),
                )),

            Action::make('changeRole')
                ->label('Change role')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->fillForm(fn (): array => ['role' => $this->membership()->role ?? 'member'])
                ->form([
                    Select::make('role')
                        ->label('Application role')
                        ->options([
                            'owner' => 'Owner',
                            'admin' => 'Admin',
                            'editor' => 'Editor',
                            'member' => 'Member',
                            'viewer' => 'Viewer',
                        ])
                        ->default('member')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->application()->users($this->application()->environment)
                        ->updateExistingPivot($this->user()->getKey(), ['role' => $data['role']]);

                    Notification::make()->title('Role updated')->success()->send();
                }),

            Action::make('ban')
                ->label('Suspend')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => ! $this->user()->isBanned())
                ->modalHeading(fn (): string => 'Suspend '.$this->user()->name)
                ->modalDescription('Suspending revokes every active session and token immediately.')
                ->form([
                    TextInput::make('reason')
                        ->label('Suspension reason')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->user()->ban($data['reason'] ?? null);

                    Notification::make()->title('User suspended')->danger()->send();
                }),

            Action::make('unban')
                ->label('Restore')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (): bool => $this->user()->isBanned())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->user()->unban();

                    Notification::make()->title('User restored')->success()->send();
                }),
        ];
    }
}
