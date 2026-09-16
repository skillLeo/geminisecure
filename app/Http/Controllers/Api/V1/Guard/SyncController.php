<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\Catalogue;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DispatchMessage;
use App\Models\Estate\VisitorPass;
use App\Models\GuardRequest;
use App\Models\Shift;
use App\Services\Passes\PassSigningKeys;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The offline queue, uploaded and reconciled (13 D2).
 *
 * BATCH REPLAYS THE QUEUE THROUGH THE REAL ENDPOINTS. Each queued operation names
 * its catalogue endpoint and carries its own Idempotency-Key and its own device
 * time — the moment it happened, not the moment signal came back. The batch hands
 * each one to the HTTP kernel as its own request with the handset's own token, so
 * an operation synced later passes the same ability, validation, idempotency and
 * invariant checks it would have passed live, and a batch that is retried answers
 * each operation from its stored response instead of doing it twice.
 *
 * IN ORDER, AND IT STOPS AT A SERVER FAILURE. A clock-out queued after a clock-in
 * must not be attempted if the clock-in failed on the server; the remaining
 * operations come back `not_attempted` and the app sends them again. A refusal
 * (4xx) does not stop the batch: it is an answer, and the app shows it.
 *
 * PULL IS WHAT THE HANDSET MISSED, including the passes revoked while it was
 * offline — the list that turns an offline verdict into a reconciled one.
 */
class SyncController extends Controller
{
    public const MAX_OPERATIONS = 50;

    public function batch(Request $request, DeviceContext $context, HttpKernel $kernel): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS],
            'operations.*.id' => ['required', 'string', 'max:64'],
            'operations.*.endpoint' => ['required', 'string', 'max:64'],
            'operations.*.params' => ['nullable', 'array'],
            'operations.*.body' => ['nullable', 'array'],
            'operations.*.idempotency_key' => ['required', 'string', 'max:64'],
            'operations.*.device_time' => ['required', 'date'],
        ]);

        $endpoints = Catalogue::endpoints();
        $outer = $request;
        $results = [];
        $halted = false;
        $sanctum = Auth::guard('sanctum');

        foreach ($data['operations'] as $operation) {
            $name = $operation['endpoint'];
            $entry = $endpoints[$name] ?? null;

            if ($halted) {
                $results[] = $this->result($operation, 'not_attempted', null, false, null);

                continue;
            }

            if ($entry === null || ! self::batchable($name, $entry, $context->app)) {
                $results[] = $this->result($operation, 'refused', 422, false, ['error' => ['code' => 'endpoint_not_batchable', 'message' => 'Only the queued writes this app makes can be synced in a batch.']]);

                continue;
            }

            $uri = $this->uri($entry, (array) ($operation['params'] ?? []));

            if ($uri === null) {
                $results[] = $this->result($operation, 'refused', 422, false, ['error' => ['code' => 'params_invalid', 'message' => 'The operation\'s path parameters do not match its endpoint.']]);

                continue;
            }

            $inner = Request::create('http://localhost/api/v1/'.$uri, $entry['method'], [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ], (string) json_encode((object) ($operation['body'] ?? [])));

            $inner->headers->set('Authorization', (string) $outer->header('Authorization'));
            $inner->headers->set('Idempotency-Key', $operation['idempotency_key']);
            $inner->headers->set('X-Device-Time', Carbon::parse($operation['device_time'])->toIso8601String());

            if ($sanctum instanceof RequestGuard) {
                $sanctum->forgetUser();
                $sanctum->setRequest($inner);
            }

            try {
                $response = $kernel->handle($inner);
            } finally {
                if ($sanctum instanceof RequestGuard) {
                    $sanctum->forgetUser();
                    $sanctum->setRequest($outer);
                }

                app()->instance('request', $outer);
                app()->instance(DeviceContext::class, $context);
            }

            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getContent(), true);
            $outcome = $status >= 500 ? 'failed' : ($status >= 400 ? 'refused' : 'ok');
            $halted = $status >= 500;

            $results[] = $this->result($operation, $outcome, $status, $response->headers->get('Idempotent-Replayed') === 'true', is_array($body) ? $body : null);
        }

        return response()->json([
            'results' => $results,
            'counts' => [
                'ok' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'ok')),
                'refused' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'refused')),
                'failed' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'failed')),
                'not_attempted' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'not_attempted')),
            ],
            'server_time' => $context->serverTime->toIso8601String(),
        ]);
    }

    public function pull(Request $request, DeviceContext $context, PassSigningKeys $keys): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate(['since' => ['nullable', 'date']]);
        $since = isset($data['since']) ? Carbon::parse($data['since']) : Carbon::now()->subDays(7);
        $now = $context->serverTime;

        $shifts = Shift::query()->with(['post', 'estate'])
            ->where('guard_id', $guard->id)
            ->where('updated_at', '>', $since)
            ->where('rostered_end', '>=', $now->copy()->subDay())
            ->where('rostered_start', '<=', $now->copy()->addDays(14))
            ->orderBy('rostered_start')
            ->get()
            ->map(static fn (Shift $s): array => [
                'id' => $s->id,
                'status' => $s->status,
                'post' => ['id' => $s->post_id, 'name' => (string) $s->post?->name],
                'site' => ['id' => $s->tenant_id, 'name' => (string) ($s->estate->name ?? $s->tenant_id)],
                'rostered_start' => $s->rostered_start->toIso8601String(),
                'rostered_end' => $s->rostered_end->toIso8601String(),
                'actual_start' => $s->actual_start?->toIso8601String(),
                'actual_end' => $s->actual_end?->toIso8601String(),
            ])->all();

        $orders = DB::connection('mysql')->table('standing_order_versions as v')
            ->join('standing_order_sets as s', static fn ($j) => $j->on('s.id', '=', 'v.standing_order_set_id')->on('s.version', '=', 'v.version'))
            ->where(static fn ($q) => $q->whereNull('s.post_id')->when($guard->post_id !== null, static fn ($w) => $w->orWhere('s.post_id', $guard->post_id)))
            ->where('v.created_at', '>', $since)
            ->get(['s.id as set_id', 'v.id as version_id', 'v.version', 'v.title', 'v.effective_on', 's.post_id'])
            ->map(static fn (object $o): array => [
                'set_id' => (int) $o->set_id,
                'version_id' => (int) $o->version_id,
                'version' => (int) $o->version,
                'title' => (string) $o->title,
                'effective_on' => (string) $o->effective_on,
                'requires_acknowledgement' => $o->post_id !== null,
            ])->all();

        $messages = DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->where(static fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->where('direction', '!=', DispatchMessage::INBOUND)
            ->where('sent_at', '>', $since)
            ->orderBy('sent_at')
            ->limit(100)
            ->get()
            ->map(static fn (object $m): array => [
                'id' => (int) $m->id,
                'direction' => (string) $m->direction,
                'body' => (string) $m->body,
                'sent_at' => Carbon::parse((string) $m->sent_at)->toIso8601String(),
            ])->all();

        $checks = DB::connection('mysql')->table('alertness_checks')
            ->where('guard_id', $guard->id)->where('outcome', 'pending')->where('respond_by', '>=', $now)
            ->get()
            ->map(static fn (object $c): array => [
                'check_id' => (int) $c->id,
                'issued_at' => Carbon::parse((string) $c->issued_at)->toIso8601String(),
                'respond_by' => Carbon::parse((string) $c->respond_by)->toIso8601String(),
            ])->all();

        $revoked = VisitorPass::query()
            ->where('valid_to', '>=', $now)
            ->where(static fn ($q) => $q
                ->where(static fn ($c) => $c->where('status', VisitorPass::CANCELLED)->where('cancelled_at', '>', $since))
                ->orWhere(static fn ($u) => $u->where('status', VisitorPass::USED)->where('single_use', true)->where('used_at', '>', $since)))
            ->get()
            ->map(static fn (VisitorPass $p): array => [
                'pass_id' => $p->pass_id,
                'status' => $p->status,
                'at' => ($p->status === VisitorPass::CANCELLED ? $p->cancelled_at : $p->used_at)?->toIso8601String(),
            ])->all();

        $decisions = GuardRequest::query()->where('guard_id', $guard->id)->whereNotNull('decided_at')->where('decided_at', '>', $since)
            ->get()
            ->map(static fn (GuardRequest $r): array => [
                'id' => $r->id,
                'kind' => $r->kind,
                'status' => $r->status,
                'decided_at' => $r->decided_at?->toIso8601String(),
                'decision_note' => $r->decision_note,
            ])->all();

        $claims = DB::connection('mysql')->table('shift_claims')->where('guard_id', $guard->id)->whereNotNull('decided_at')->where('decided_at', '>', $since)
            ->get()
            ->map(static fn (object $c): array => [
                'claim_id' => (int) $c->id,
                'shift_id' => (int) $c->shift_id,
                'status' => (string) $c->status,
                'decided_at' => Carbon::parse((string) $c->decided_at)->toIso8601String(),
            ])->all();

        $current = $keys->currentVersion($context->tenantId);

        return response()->json([
            'since' => $since->toIso8601String(),
            'server_time' => $now->toIso8601String(),
            'next_since' => $now->toIso8601String(),
            'shifts' => $shifts,
            'orders' => $orders,
            'messages' => $messages,
            'alertness_checks' => $checks,
            'passes' => [
                'revoked' => $revoked,
                'keys' => collect($keys->publicKeys($context->tenantId))
                    ->map(static fn (string $key, int $version): array => ['version' => $version, 'public_key' => $key, 'current' => $version === $current])
                    ->values()->all(),
            ],
            'requests' => $decisions,
            'claims' => $claims,
        ]);
    }

    /**
     * A catalogue entry a queued operation may name: a write, for this app, that
     * is not itself a sync or an enrolment and takes no file.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function batchable(string $name, array $entry, string $app): bool
    {
        return $entry['write'] === true
            && in_array($app, $entry['apps'], true)
            && ! str_starts_with($name, 'sync.')
            && ($entry['batch'] ?? true) === true;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $params
     */
    private function uri(array $entry, array $params): ?string
    {
        $uri = (string) $entry['uri'];
        $where = (array) ($entry['where'] ?? []);

        $valid = true;

        $resolved = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params, $where, &$valid): string {
            $value = is_scalar($params[$m[1]] ?? null) ? (string) $params[$m[1]] : '';
            $pattern = $where[$m[1]] ?? '[^/]+';

            if ($value === '' || preg_match('#^'.$pattern.'$#', $value) !== 1) {
                $valid = false;
            }

            return rawurlencode($value);
        }, $uri);

        return $valid ? $resolved : null;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<mixed>|null  $body
     * @return array<string, mixed>
     */
    private function result(array $operation, string $outcome, ?int $status, bool $replayed, ?array $body): array
    {
        return [
            'id' => $operation['id'],
            'endpoint' => $operation['endpoint'],
            'outcome' => $outcome,
            'status' => $status,
            'replayed' => $replayed,
            'body' => $body,
        ];
    }
}
