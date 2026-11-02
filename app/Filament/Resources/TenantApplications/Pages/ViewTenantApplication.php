<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

class ViewTenantApplication extends ViewRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.view-application';

    public ?array $regeneratedSecret = null;

    public function regenerateSecret(): void
    {
        /** @var TenantApplication $app */
        $app = $this->getRecord();
        $env = $app->environment;
        $config = $app->getEnvironmentConfig($env);
        $client = Client::query()->find($config->oauth_client_id);

        if (! $client instanceof Client) {
            Notification::make()->title('No OAuth client found for this environment.')->danger()->send();

            return;
        }

        $newSecret = Str::random(40);
        $client->forceFill(['secret' => $newSecret])->save();

        $this->regeneratedSecret = [
            'environment' => $env,
            'client_id' => (string) $client->getKey(),
            'secret' => $newSecret,
        ];

        session()->flash('regenerated_secret', $this->regeneratedSecret);

        Notification::make()
            ->title('Secret Rotated Successfully')
            ->body('A new client secret has been generated. Copy it now from the credentials banner.')
            ->success()
            ->send();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerateSecret')
                ->label('Rotate Secret')
                ->icon('heroicon-m-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Rotate Client Secret')
                ->modalDescription('Generating a new client secret will immediately invalidate the existing secret for the active environment. Update your application environment variables (.env) immediately to prevent downtime.')
                ->modalSubmitActionLabel('Yes, Rotate Secret')
                ->action(fn () => $this->regenerateSecret()),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
