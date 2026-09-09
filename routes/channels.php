<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization
|--------------------------------------------------------------------------
|
| A private channel is only private because of what is in this file. Without
| an entry here every subscription is refused, which presents as "the socket
| connects but nothing ever arrives" — the exact symptom that kept the alert
| screens on a 3-second poll.
|
| Alerts are broadcast per estate, never on one global channel. A single
| channel would push every estate's alerts to every connected console and
| rely on the client to filter, which is not a boundary at all: the data has
| already crossed the wire by the time the filter runs.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

/**
 * estate.{tenantId}.alerts — the live duress and panic feed for one estate.
 *
 * Two kinds of listener are legitimate, and they are authorised differently:
 *
 *   Gemini staff who hold the dispatch module. Dispatch is a platform-wide
 *   function — one control room watches every client — so it is the PERMISSION
 *   that scopes them, not an estate assignment. A Gemini accountant, who holds
 *   no dispatch permission, is refused.
 *
 *   Estate users assigned to THAT estate, and only that estate. canAccessEstate
 *   already encodes the assignment and active-status rules; asking it again
 *   here keeps one answer to the question rather than two.
 *
 * Returning false rather than throwing: a refused subscription is a normal
 * outcome for a user who simply is not on that estate, not an error.
 */
Broadcast::channel('estate.{tenantId}.alerts', function (User $user, string $tenantId): bool {
    if ($user->isGeminiStaff()) {
        return $user->can('gemini.dispatch.view');
    }

    return $user->canAccessEstate($tenantId);
});
