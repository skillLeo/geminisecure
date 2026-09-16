<?php

namespace App\Providers;

use App\Support\MailReadiness;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->refuseUndeliverableMailInProduction();
    }

    /**
     * Production does not boot on a mailer that delivers nowhere (13 B7).
     *
     * Checked once, at boot, from the configured values — which turns "nobody
     * got their invitation" from a month-long mystery into an error on the
     * first request after a bad deploy. See `MailReadiness`.
     */
    private function refuseUndeliverableMailInProduction(): void
    {
        $mailer = config('mail.default');

        $problem = MailReadiness::problem(
            (string) $this->app->environment(),
            is_string($mailer) ? $mailer : null,
            is_string($host = config('mail.mailers.smtp.host')) ? $host : null,
            is_string($from = config('mail.from.address')) ? $from : null,
        );

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }
}
