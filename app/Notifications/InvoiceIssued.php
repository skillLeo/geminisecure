<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invoice, sent to whoever holds the estate's account.
 *
 * NO FIGURES A RECIPIENT CANNOT ALREADY SEE. The mail names the reference, the
 * period, the total and the due date — the four things somebody needs to find
 * the invoice and pay it — and nothing about the estate's own books. An email
 * is forwarded, printed and left on desks, and what it carries is what leaves
 * the building with it.
 *
 * NO LINK TO THE CONSOLE. The Gemini Console is the security company's, not the
 * client's; sending an estate officer a link they cannot open would be an email
 * that reads as a fault.
 */
class InvoiceIssued extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $reference,
        private readonly string $period,
        private readonly int $totalMinor,
        private readonly string $currency,
        private readonly string $dueOn,
        private readonly string $estate,
        private readonly bool $isResend,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = MoneyFormatter::fromMinor($this->totalMinor, $this->currency);

        return (new MailMessage)
            ->subject(($this->isResend ? 'Copy of invoice ' : 'Invoice ').$this->reference.' — '.$this->estate)
            ->greeting('Invoice '.$this->reference)
            ->line($this->estate.' — '.$this->period.'.')
            ->line('Total due: '.$total)
            ->line('Payable by '.$this->dueOn.'.')

            /*
             * Said plainly on a resend. Somebody who has already paid and is
             * sent the invoice again will otherwise ring to ask whether they
             * are being chased, and that call is the cost of not saying it.
             */
            ->lineIf($this->isResend, 'This is a copy of an invoice already issued. If it has been settled, no action is needed.')

            ->line('Gemini Security Limited will confirm receipt once payment is recorded.')
            ->salutation('Gemini Security Limited');
    }
}
