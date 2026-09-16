<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Api\ApiError;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A retried write is answered, not repeated (13 D2).
 *
 *   idempotent            `Idempotency-Key` is required
 *   idempotent:optional   honoured when sent (the first seven endpoints)
 *
 * A HANDSET AT A GATE LOSES SIGNAL CONSTANTLY, and the one thing worse than a
 * request that failed is one that succeeded and whose answer was lost — the app
 * sends it again. The key is chosen by the app BEFORE the first attempt and
 * reused on every retry. The first attempt's response is stored against the
 * handset and the key; every retry gets that same response back, byte for byte,
 * with `Idempotent-Replayed: true`, and nothing happens twice.
 *
 *   same key, same request        the stored response, replayed
 *   same key, different request   422 `idempotency_key_reused` — a bug in the app
 *   same key, first still running 409 `request_in_progress` — wait and retry
 *
 * A 5xx is not stored: the server failed, and the retry deserves a real attempt.
 */
final class Idempotent
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw ApiError::unprocessable('idempotency_key_required', 'Send an Idempotency-Key on every write, chosen before the first attempt and reused on every retry.');
            }

            return $next($request);
        }

        $key = (string) $key;

        if (strlen($key) > 64) {
            throw ApiError::unprocessable('idempotency_key_invalid', 'An Idempotency-Key is at most 64 characters.');
        }

        $principal = Auth::guard('sanctum')->user();
        $type = $principal === null ? 'anonymous' : $principal->getMorphClass();
        $id = $principal === null ? 0 : (int) $principal->getKey();
        $hash = $this->hash($request);
        $route = (string) ($request->route()?->getName() ?? $request->path());

        $connection = DB::connection('mysql');

        try {
            $rowId = $connection->table('api_idempotency_keys')->insertGetId([
                'tokenable_type' => $type,
                'tokenable_id' => $id,
                'idempotency_key' => $key,
                'route' => $route,
                'request_hash' => $hash,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (QueryException $duplicate) {
            if (! str_contains($duplicate->getMessage(), 'api_idempotency_unique') && ($duplicate->errorInfo[1] ?? null) !== 1062) {
                throw $duplicate;
            }

            $existing = $connection->table('api_idempotency_keys')
                ->where('tokenable_type', $type)
                ->where('tokenable_id', $id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                throw $duplicate;
            }

            if ($existing->route !== $route || $existing->request_hash !== $hash) {
                throw ApiError::unprocessable('idempotency_key_reused', 'This Idempotency-Key was already used for a different request. Choose a new key for a new act.');
            }

            if ($existing->status_code === null) {
                throw new ApiError(409, 'request_in_progress', 'The first attempt with this key is still being handled. Retry in a moment with the same key.');
            }

            return (new JsonResponse(json_decode((string) $existing->response_body, true), (int) $existing->status_code))
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $failure) {
            $status = $failure instanceof ApiError ? $failure->status : 500;

            if ($failure instanceof ApiError) {
                $this->store($rowId, $status, $failure->body());
            } else {
                $connection->table('api_idempotency_keys')->where('id', $rowId)->delete();
            }

            throw $failure;
        }

        if ($response->getStatusCode() >= 500) {
            $connection->table('api_idempotency_keys')->where('id', $rowId)->delete();

            return $response;
        }

        $body = json_decode((string) $response->getContent(), true);
        $this->store($rowId, $response->getStatusCode(), is_array($body) ? $body : []);

        return $response;
    }

    /** @param  array<mixed>  $body */
    private function store(int $rowId, int $status, array $body): void
    {
        DB::connection('mysql')->table('api_idempotency_keys')->where('id', $rowId)->update([
            'status_code' => $status,
            'response_body' => json_encode($body),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * What makes two attempts "the same request". The device time is left out:
     * a retry is sent later than the first attempt, and that must not make it a
     * different request. Files count by name, size and content hash.
     */
    private function hash(Request $request): string
    {
        $input = $request->except(['idempotency_key', 'device_time']);

        foreach ($request->allFiles() as $field => $files) {
            $input['__files'][$field] = array_map(
                static fn (UploadedFile $file): array => [$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', (string) $file->getRealPath())],
                is_array($files) ? $files : [$files],
            );
        }

        ksort($input);

        return hash('sha256', $request->method().' '.$request->path().' '.json_encode($input));
    }
}
