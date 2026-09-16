<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Support\MailReadiness;

/*
|--------------------------------------------------------------------------
| Outbound mail in production — 13 B7
|--------------------------------------------------------------------------
|
| "Assert in a test that MAIL_MAILER is not log when APP_ENV=production."
| Asserted three ways: the rule itself, the application refusing to boot on
| it, and the example environment a deploy starts from.
|
*/

afterEach(function () {
    $this->app['env'] = 'testing';
});

it('names log and array as undeliverable in production, and nowhere else', function () {
    foreach (MailReadiness::UNDELIVERABLE as $mailer) {
        expect(MailReadiness::problem('production', $mailer, 'smtp.relay.jm', 'no-reply@geminisecure.com'))
            ->toContain('MAIL_MAILER')
            ->and(MailReadiness::problem('local', $mailer, null, null))->toBeNull()
            ->and(MailReadiness::problem('testing', $mailer, null, null))->toBeNull();
    }

    expect(MailReadiness::problem('production', null, null, null))->not->toBeNull()
        ->and(MailReadiness::problem('production', 'smtp', 'smtp.replace-with-your-provider.example', 'no-reply@geminisecure.com'))->toContain('MAIL_HOST')
        ->and(MailReadiness::problem('production', 'smtp', 'smtp.relay.jm', 'no-reply@replace-with-your-domain.example'))->toContain('MAIL_FROM_ADDRESS')
        ->and(MailReadiness::problem('production', 'smtp', 'smtp.relay.jm', 'no-reply@geminisecure.com'))->toBeNull();
});

it('refuses to boot in production when MAIL_MAILER is log', function () {
    $this->app['env'] = 'production';

    config([
        'mail.default' => 'log',
        'mail.mailers.smtp.host' => 'smtp.relay.jm',
        'mail.from.address' => 'no-reply@geminisecure.com',
    ]);

    expect(fn () => (new AppServiceProvider($this->app))->boot())
        ->toThrow(RuntimeException::class, 'MAIL_MAILER is "log" in production');

    config(['mail.default' => 'smtp']);

    (new AppServiceProvider($this->app))->boot();

    expect(config('mail.default'))->toBe('smtp');
});

it('ships an example environment with a real mailer and placeholders, never log', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    preg_match('/^MAIL_MAILER=(.*)$/m', $example, $mailer);

    expect($mailer[1] ?? null)->toBe('smtp')
        ->and($example)->toMatch('/^MAIL_HOST=.*replace-with/m')
        ->and($example)->toMatch('/^MAIL_USERNAME=replace-with/m')
        ->and($example)->toMatch('/^MAIL_PASSWORD=replace-with/m');

    // And with nothing set at all, the framework default is smtp, not log.
    expect((string) file_get_contents(config_path('mail.php')))->toContain("env('MAIL_MAILER', 'smtp')");
});
