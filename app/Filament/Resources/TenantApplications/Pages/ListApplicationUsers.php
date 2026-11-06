<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApplicationInvitation;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\ApplicationInvitationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Users enrolled in an application environment, with the account management
 * tools needed to support them: verification state, authentication methods,
 * sessions, suspension, and impersonation.
 */
class ListApplicationUsers extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = TenantApplicationResource::class;

    protected static ?string $title = 'Users';

    protected string $view = 'filament.resources.tenant-applications.pages.list-application-users';

    #[Unexposed]
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $application = $parameters['record'] ?? null;

        return $application instanceof TenantApplication
            && TenantApplicationResource::canView($application)
            && UserResource::canViewAny();
    }

    public function getSubheading(): ?string
    {
        $application = $this->getRecord();

        return 'Accounts that can sign in to '.$application->name.' in the '.$application->environment.' environment.';
    }

    /**
     * Invite an address to this application, or manage the invitations that are
     * still outstanding.
     *
     * @return array<int, Action>
     */
    #[	Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite user')
                ->icon('heroicon-o-paper-airplane')
                ->modalHeading(fn (): string => 'Invite someone to '.$this->getRecord()->name)
                ->modalDescription('They receive an email with a link. Only that address can redeem it.')
                ->form([
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->maxLength(255),

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
                    /** @var TenantApplication $application */
                    $application = $this->getRecord();
                    $environment = $application->environments()
                        ->where('environment', $application->environment)
                        ->firstOrFail();

                    app(ApplicationInvitationService::class)->invite(
                        $environment,
                        $data['email'],
                        $data['role'],
                        Auth::user() instanceof User ? Auth::user() : null,
                    );

                    Notification::make()
                        ->title('Invitation sent')
                        ->body($data['email'].' can now join this application.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Invitations that have not been redeemed yet.
     *
     * @return Collection<int, ApplicationInvitation>
     */
    public function pendingInvitations(): Collection
    {
        /** @var TenantApplication $application */
        $application = $this->getRecord();
        $environment = $application->environments()
            ->where('environment', $application->environment)
            ->first();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return collect();
        }

        return ApplicationInvitation::query()
            ->where('tenant_application_environment_id', $environment->getKey())
            ->pending()
            ->where('expires_at', '>', now())
            ->latest()
            ->get();
    }

    /**
     * Send a fresh link for an invitation whose email was lost.
     */
    public function resendInvitation(int|string $invitationId): void
    {
        $invitation = $this->pendingInvitations()->firstWhere('id', $invitationId);

        if (! $invitation instanceof ApplicationInvitation) {
            return;
        }

        app(ApplicationInvitationService::class)->resend($invitation);

        Notification::make()
            ->title('Invitation resent')
            ->body('A new link was sent to '.$invitation->email.'.')
            ->success()
            ->send();
    }

    /**
     * Cancel an invitation that has not been redeemed.
     */
    public function revokeInvitation(int|string $invitationId): void
    {
        $invitation = $this->pendingInvitations()->firstWhere('id', $invitationId);

        if (! $invitation instanceof ApplicationInvitation) {
            return;
        }

        $invitation->revoke();

        Notification::make()
            ->title('Invitation revoked')
            ->body('The link for '.$invitation->email.' no longer works.')
            ->warning()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->relationship(function (): BelongsToMany {
                /** @var TenantApplication $application */
                $application = $this->getRecord();

                return $application->users($application->environment);
            })
            ->inverseRelationship('applications')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['passkeys', 'socialAccounts']))
            ->columns([
                TextColumn::make('name')
                    ->label('User')
                    ->searchable(['name', 'email', 'username'])
                    ->description(fn (User $record): string => $record->email)
                    ->weight('medium')
                    ->sortable(),

                TextColumn::make('username')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        'owner', 'admin' => 'primary',
                        'editor' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->state(fn (User $record): string => $record->isBanned() ? 'Suspended' : 'Active')
                    ->color(fn (User $record): string => $record->isBanned() ? 'danger' : 'success')
                    ->tooltip(fn (User $record): ?string => $record->isBanned() ? $record->ban_reason : null),

                IconColumn::make('email_verified_at')
                    ->label('Email')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (User $record): string => $record->hasVerifiedEmail() ? 'Email verified' : 'Email not verified'),

                IconColumn::make('phone_verified_at')
                    ->label('Phone')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (User $record): string => $record->hasVerifiedPhone() ? 'Phone verified' : 'No verified phone'),

                TextColumn::make('auth_methods')
                    ->label('Auth methods')
                    ->badge()
                    ->state(fn (User $record): array => $this->authMethodLabels($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Passkey' => 'success',
                        '2FA' => 'info',
                        'Social' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('Password only'),

                TextColumn::make('pivot.last_sign_in_at')
                    ->label('Last sign in')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('pivot.created_at')
                    ->label('Enrolled')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Application role')
                    ->options(fn (): array => $this->applicationRoles())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->wherePivot('role', $data['value']),
                    )),

                TernaryFilter::make('banned_at')
                    ->label('Account status')
                    ->placeholder('All accounts')
                    ->trueLabel('Suspended only')
                    ->falseLabel('Active only')
                    ->nullable(),

                TernaryFilter::make('email_verified_at')
                    ->label('Email verification')
                    ->placeholder('Any verification state')
                    ->trueLabel('Verified')
                    ->falseLabel('Unverified')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('viewProfile')
                    ->label('View profile')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (User $record): string => TenantApplicationResource::getUrl('user', [
                        'tenant' => $this->getRecord()->tenant,
                        'record' => $this->getRecord(),
                        'userId' => $record->getKey(),
                    ])),

                Action::make('impersonate')
                    ->label('Impersonate')
                    ->icon('heroicon-o-user-plus')
                    ->url(fn (User $record): string => route('impersonate', $record->getKey()))
                    ->openUrlInNewTab()
                    ->visible(fn (User $record): bool => ($admin = Auth::user()) instanceof User
                        && $admin->hasRole('admin')
                        && $record->canBeImpersonated()),

                Action::make('ban')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => ! $record->isBanned())
                    ->modalHeading(fn (User $record): string => 'Suspend '.$record->name)
                    ->modalDescription('Suspending revokes every active session and token immediately.')
                    ->form([
                        TextInput::make('reason')
                            ->label('Suspension reason')
                            ->placeholder('Violation of terms, suspicious activity')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->ban($data['reason'] ?? null);

                        Notification::make()
                            ->title('User suspended')
                            ->body("{$record->name} has been suspended and all sessions terminated.")
                            ->danger()
                            ->send();
                    }),

                Action::make('unban')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->isBanned())
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->unban();

                        Notification::make()
                            ->title('User restored')
                            ->body("{$record->name} can sign in again.")
                            ->success()
                            ->send();
                    }),

                Action::make('revokeSessions')
                    ->label('Revoke sessions')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->revokeAllSessions();

                        Notification::make()
                            ->title('Sessions terminated')
                            ->body("All active sessions and tokens for {$record->name} have been cleared.")
                            ->warning()
                            ->send();
                    }),

                Action::make('verifyEmail')
                    ->label('Verify email')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (User $record): bool => ! $record->hasVerifiedEmail())
                    ->requiresConfirmation()
                    ->modalDescription('Mark this email address as verified without the user confirming it.')
                    ->action(function (User $record): void {
                        $record->forceFill(['email_verified_at' => now()])->save();

                        Notification::make()
                            ->title('Email verified')
                            ->success()
                            ->send();
                    }),

                Action::make('sendVerification')
                    ->label('Send verification email')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (User $record): bool => ! $record->hasVerifiedEmail())
                    ->action(function (User $record): void {
                        $record->sendEmailVerificationNotification();

                        Notification::make()
                            ->title('Verification email sent')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('banSelected')
                        ->label('Suspend selected')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->form([
                            TextInput::make('reason')
                                ->label('Suspension reason')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (User $user) => $user->ban($data['reason'] ?? null));

                            Notification::make()
                                ->title($records->count().' users suspended')
                                ->danger()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unbanSelected')
                        ->label('Restore selected')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(fn (User $user) => $user->unban());

                            Notification::make()
                                ->title($records->count().' users restored')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('revokeSelectedSessions')
                        ->label('Revoke sessions')
                        ->icon('heroicon-o-arrow-left-on-rectangle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(fn (User $user) => $user->revokeAllSessions());

                            Notification::make()
                                ->title('Sessions terminated for '.$records->count().' users')
                                ->warning()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No users enrolled')
            ->emptyStateDescription('Users enrolled in this application and environment will appear here.');
    }

    /**
     * Distinct application roles currently in use, for the role filter.
     *
     * @return array<string, string>
     */
    public function applicationRoles(): array
    {
        /** @var TenantApplication $application */
        $application = $this->getRecord();

        return DB::table('tenant_application_user')
            ->where('tenant_application_id', $application->getKey())
            ->where('environment', $application->environment)
            ->whereNotNull('role')
            ->distinct()
            ->orderBy('role')
            ->pluck('role')
            ->mapWithKeys(fn (string $role): array => [$role => ucfirst($role)])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function authMethodLabels(User $user): array
    {
        $methods = [];

        if ($user->hasMfa()) {
            $methods[] = '2FA';
        }

        if ($user->hasPasskeysEnabled()) {
            $methods[] = 'Passkey';
        }

        if (($user->social_accounts_count ?? 0) > 0) {
            $methods[] = 'Social';
        }

        return $methods;
    }
}
