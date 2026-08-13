<?php

use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Bearer-token auth (auth:sanctum), not the 'web' default — this app has no session/CSRF setup,
// matching every other API route. auth:sanctum resolves either a User or a StaffUser since both
// share the same polymorphic personal_access_tokens table.
Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('application.{applicationId}.report', function ($user, int $applicationId) {
    if ($user instanceof StaffUser) {
        return true;
    }

    if ($user instanceof User) {
        return CreditApplication::where('id', $applicationId)->where('user_id', $user->id)->exists();
    }

    return false;
});
