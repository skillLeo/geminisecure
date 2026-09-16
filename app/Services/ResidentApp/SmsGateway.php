<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

/**
 * Where a text message goes (13 D3). One implementation today, `LogSmsGateway`,
 * which delivers nothing and is refused in production.
 */
interface SmsGateway
{
    public function send(string $to, string $message): void;
}
