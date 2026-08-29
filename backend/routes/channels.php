<?php

use App\Broadcasting\AdminChannel;
use App\Broadcasting\ApplicationChannel;
use App\Broadcasting\ApplicationReportChannel;
use App\Broadcasting\StaffChannel;
use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

// Bearer-token auth (auth:sanctum), not the 'web' default — this app has no session/CSRF setup,
// matching every other API route. auth:sanctum resolves either a User or a StaffUser since both
// share the same polymorphic personal_access_tokens table.
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Legacy model-event channel kept for backwards compatibility.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Realtime channels. Every channel here is a PRIVATE channel: the frontends subscribe via
 * echo.private('...'), which sends a channel_name prefixed with "private-", and the Pusher/
 * Reverb broadcaster strips that prefix before matching it against the patterns registered
 * here. So the patterns below use the BARE names (no "private-" prefix) — the prefix is what
 * makes the authorization actually enforced, because Reverb treats any channel WITHOUT the
 * private-/presence- prefix as PUBLIC and never consults these handlers. A pattern that carried
 * the prefix itself would never match a normalized subscription and everything would 403.
 *
 * Each handler is a dedicated class in App\Broadcasting so tests can re-register the very same
 * class on a broadcaster that actually runs the authorization logic.
 */
Broadcast::channel('user.{id}', UserChannel::class);
Broadcast::channel('application.{applicationId}', ApplicationChannel::class);
Broadcast::channel('application.{applicationId}.report', ApplicationReportChannel::class);
Broadcast::channel('staff', StaffChannel::class);
Broadcast::channel('admin', AdminChannel::class);
