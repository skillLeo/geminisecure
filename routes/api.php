<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /api/v1 - the mobile client API
|--------------------------------------------------------------------------
|
| REGISTERED FROM THE CATALOGUE, AND ONLY FROM IT (13 D1). Every route's path,
| method, middleware and ability comes from its entry in `App\Api\Catalogue`,
| and the ability is COMPUTED — `AppMatrix::ability(capability, access)` — never
| written here. So an endpoint cannot exist without being described, cannot ask
| for an ability no app holds, and cannot drift from MOBILE_HANDOFF.md, which is
| written from the same entries.
|
| The middleware each authenticated route carries, in order:
|
|   auth:sanctum            a handset token
|   ability:{cap}:{access}  the token carries the ability the matrix gives this app
|   device:{apps}           the principal is the right app's, active, and its
|                           estate is opened — the estate comes from the token
|   throttle:{limiter}      per device, not per address
|   idempotent              writes: a retry is answered, never repeated
|   device.time             writes: the handset's clock beside the server's
|
| INVARIANT 2 still holds and is now checked, not only argued: no endpoint a
| guard's token reaches returns a household's financial position. Each route's
| `response` in the catalogue is its allowlist, and the guard contract test
| fails on any key not listed.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    foreach (Catalogue::endpoints() as $name => $endpoint) {
        $middleware = [];

        if ($endpoint['capability'] !== null) {
            $middleware[] = 'auth:sanctum';
            $middleware[] = 'ability:'.AppMatrix::ability($endpoint['capability'], $endpoint['access']);
            $middleware[] = 'device:'.implode(',', [...$endpoint['apps'], ...(($endpoint['pending'] ?? false) ? ['pending'] : [])]);
        }

        $middleware[] = 'throttle:'.$endpoint['throttle'];

        if ($endpoint['write']) {
            $mode = ($endpoint['legacy'] ?? false) ? ':optional' : '';
            $middleware[] = 'idempotent'.$mode;
            $middleware[] = 'device.time'.$mode;
        }

        Route::match([$endpoint['method']], $endpoint['uri'], $endpoint['action'])
            ->name($name)
            ->where($endpoint['where'] ?? [])
            ->middleware($middleware);
    }
});
