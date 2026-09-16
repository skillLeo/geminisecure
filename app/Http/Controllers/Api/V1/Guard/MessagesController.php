<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DispatchMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Messages between a guard and dispatch (13 D2). Board guard-app-04 screen 17.
 *
 * What a guard reads: broadcasts to their estate, messages dispatch addressed to
 * them, and what they sent. Never another guard's conversation with dispatch.
 */
class MessagesController extends Controller
{
    public function index(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate(['since' => ['nullable', 'date']]);

        $rows = DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->where(fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->when($data['since'] ?? null, fn ($q, string $since) => $q->where('sent_at', '>', Carbon::parse($since)))
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $rows->map(fn (object $m): array => $this->shape($m))->values()->all(),
            'unread' => $rows->filter(static fn (object $m): bool => $m->direction !== DispatchMessage::INBOUND && $m->read_at === null)->count(),
        ]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $now = Carbon::now();

        $id = DB::connection('mysql')->table('dispatch_messages')->insertGetId([
            'tenant_id' => $context->tenantId,
            'direction' => DispatchMessage::INBOUND,
            'guard_id' => $guard->id,
            'body' => $data['body'],
            'sent_at' => $context->serverTime,
            'device_time' => $context->deviceTime,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Opening the thread to write is reading it.
        DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->where(fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->where('direction', '!=', DispatchMessage::INBOUND)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        $row = DB::connection('mysql')->table('dispatch_messages')->where('id', $id)->first();

        return response()->json([
            ...$this->shape($row),
            'server_time' => $context->serverTime->toIso8601String(),
            'device_time' => $context->deviceTime?->toIso8601String(),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function shape(object $m): array
    {
        return [
            'id' => (int) $m->id,
            'direction' => (string) $m->direction,
            'from' => $m->direction === DispatchMessage::INBOUND ? 'you' : 'dispatch',
            'body' => (string) $m->body,
            'sent_at' => Carbon::parse((string) $m->sent_at)->toIso8601String(),
            'read_at' => $m->read_at === null ? null : Carbon::parse((string) $m->read_at)->toIso8601String(),
        ];
    }
}
