<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\ApiError;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Notifications\ResidentSignInCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Signing in to the Resident App with a one-time code (13 D3).
 *
 * NO PASSWORD, AND NO ANSWER ABOUT WHO EXISTS. A code is sent to whatever address
 * or number is given, for the estate named; the response is the same whether an
 * account exists or not, so the endpoint cannot be used to learn who lives where.
 * Proving the code proves control of the address — and nothing more: the account
 * that results is PENDING until the estate approves a unit claim.
 *
 * THE CODE. Six digits, stored as an HMAC keyed by the application key and the
 * destination, good for ten minutes and five tries, once. A new request retires
 * the previous code; a request within a minute of the last is answered without
 * sending another.
 */
class ResidentSignIn
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function __construct(private readonly SmsGateway $sms) {}

    /** @return array{expires_at: Carbon, resend_after: Carbon} */
    public function requestCode(Tenant $estate, string $channel, string $destination): array
    {
        $destination = self::normalise($channel, $destination);

        if ($channel === 'sms' && ! self::smsAvailable()) {
            throw new ApiError(503, 'sms_unavailable', 'Codes by text message are not available on this platform yet. Sign in with your email address.');
        }

        $hash = $this->destinationHash($destination);
        $now = Carbon::now();

        $latest = DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
            ->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($latest !== null && Carbon::parse((string) $latest->created_at)->addSeconds(self::RESEND_SECONDS)->isFuture()) {
            return [
                'expires_at' => Carbon::parse((string) $latest->expires_at),
                'resend_after' => Carbon::parse((string) $latest->created_at)->addSeconds(self::RESEND_SECONDS),
            ];
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expires = $now->copy()->addMinutes(self::TTL_MINUTES);

        DB::connection('mysql')->transaction(function () use ($estate, $channel, $hash, $code, $now, $expires): void {
            DB::connection('mysql')->table('resident_otps')
                ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            DB::connection('mysql')->table('resident_otps')->insert([
                'tenant_id' => $estate->getTenantKey(),
                'channel' => $channel,
                'destination_hash' => $hash,
                'code_hash' => $this->codeHash($code, $hash),
                'attempts' => 0,
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        if ($channel === 'email') {
            Notification::route('mail', $destination)->notifyNow(new ResidentSignInCode($code, (string) $estate->name, self::TTL_MINUTES));
        } else {
            $this->sms->send($destination, $code.' is your '.$estate->name.' Resident App code. It works once, for '.self::TTL_MINUTES.' minutes.');
        }

        return ['expires_at' => $expires, 'resend_after' => $now->copy()->addSeconds(self::RESEND_SECONDS)];
    }

    /** Prove the code; the account it signs in to, created pending on first sign-in. */
    public function verify(Tenant $estate, string $channel, string $destination, string $code): ResidentAccount
    {
        $destination = self::normalise($channel, $destination);
        $hash = $this->destinationHash($destination);

        $otp = DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
            ->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($otp === null || Carbon::parse((string) $otp->expires_at)->isPast()) {
            throw ApiError::unprocessable('otp_expired', 'That code has expired or was replaced by a newer one. Ask for a new code.');
        }

        if ((int) $otp->attempts >= self::MAX_ATTEMPTS) {
            throw new ApiError(423, 'otp_locked', 'Too many wrong codes. Ask for a new code.');
        }

        if (! hash_equals((string) $otp->code_hash, $this->codeHash(trim($code), $hash))) {
            DB::connection('mysql')->table('resident_otps')->where('id', $otp->id)->increment('attempts', 1, ['updated_at' => Carbon::now()]);
            $left = self::MAX_ATTEMPTS - (int) $otp->attempts - 1;

            throw $left <= 0
                ? new ApiError(423, 'otp_locked', 'Too many wrong codes. Ask for a new code.')
                : ApiError::unprocessable('otp_invalid', 'That code is not right. '.$left.' '.($left === 1 ? 'try' : 'tries').' left.');
        }

        DB::connection('mysql')->table('resident_otps')->where('id', $otp->id)->update(['consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $account = ResidentAccount::query()->firstOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'channel' => $channel, 'destination' => $destination],
            ['status' => ResidentAccount::PENDING],
        );

        $account->forceFill(['verified_at' => Carbon::now()])->save();

        return $account;
    }

    /** Whether text messages can actually be delivered here. */
    public static function smsAvailable(): bool
    {
        $driver = (string) config('services.sms.driver');

        return $driver !== '' && ! ($driver === 'log' && app()->environment('production'));
    }

    public static function normalise(string $channel, string $destination): string
    {
        return $channel === 'email'
            ? mb_strtolower(trim($destination))
            : '+'.preg_replace('/\D+/', '', $destination);
    }

    /** "a•••@example.com", "+1876•••0147". */
    public static function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$local, $domain] = explode('@', $destination, 2);

            return mb_substr($local, 0, 1).'•••@'.$domain;
        }

        return mb_substr($destination, 0, 5).'•••'.mb_substr($destination, -4);
    }

    private function destinationHash(string $destination): string
    {
        return hash_hmac('sha256', $destination, (string) config('app.key'));
    }

    private function codeHash(string $code, string $destinationHash): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key').'|'.$destinationHash);
    }
}
