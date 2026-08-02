<?php

use App\Models\ApiIdempotencyKey;
use App\Models\TeamInvitation;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations')->onOneServer();

Schedule::call(function () {
    ApiIdempotencyKey::query()
        ->where('expires_at', '<', now())
        ->delete();
})->hourly()->description('Delete expired API idempotency keys')->onOneServer();

Schedule::call(function () {
    WebhookDelivery::query()
        ->where('created_at', '<', now()->subDays(config('api.webhook_retention_days')))
        ->delete();
})->daily()->description('Delete webhook deliveries past their retention window')->onOneServer();
