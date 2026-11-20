<?php

use App\Models\ApplicationInvitation;
use App\Models\EmailVerificationCode;
use App\Models\MultiSessionAccount;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('passport:purge')->daily();

Schedule::call(function (): void {
    EmailVerificationCode::query()->where('expires_at', '<', now()->subDay())->delete();
    ApplicationInvitation::query()->where('expires_at', '<', now()->subDays(30))->whereNull('accepted_at')->delete();
    MultiSessionAccount::query()->where('expires_at', '<', now())->delete();
    WebhookDelivery::query()->where('created_at', '<', now()->subDays(30))->delete();
})->name('koamishin:prune-expired-auth-records')->daily()->withoutOverlapping();
