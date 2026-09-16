<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use Illuminate\Support\Facades\Log;

/**
 * A text message written to the log and sent to nobody — development only.
 *
 * The number is logged masked. A sign-in code in a log file is harmless on a
 * developer's machine and a credential anywhere else, which is why production
 * never reaches this class: `ResidentSignIn::smsAvailable()` refuses it there.
 */
final class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver, not delivered) to '.ResidentSignIn::mask($to).': '.$message);
    }
}
