<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\EstateSetting;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The estate's mark, and where it lives.
 *
 * THE OLD REASON ASKED FOR THREE THINGS and this is all three: a size and format
 * rule (the constants below, stated on the screen before the press), a stored
 * original (the bytes as uploaded, never re-encoded — a logo re-saved at a
 * smaller size is a logo an estate cannot get back), and somewhere for the old
 * one to go (nowhere: it stays, and the swap is audited with both paths).
 *
 * PRIVATE DISK, ESTATE-PREFIXED PATH. The migration's own comment says one
 * estate's logo "cannot be served from another's URL", and that is enforced by
 * where the file is and by the route being inside the estate's auth group —
 * never by the filename being hard to guess.
 *
 * A PDF EMBEDS THE BYTES AT RENDER TIME, so a statement issued in March keeps
 * March's mark whatever happens here afterwards. That is why nothing deletes.
 */
class EstateBranding
{
    /** 2 MB. A mark on a letterhead, not a photograph. */
    public const MAX_KB = 2048;

    /** @var list<string> */
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg'];

    /** What the screen says before the press, so a refusal is never a surprise. */
    public const RULES = 'PNG, JPG or SVG, up to 2 MB. The file is stored as uploaded and never re-encoded, and the mark you replace is kept — documents already issued keep the logo they were printed with.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(?UploadedFile $file, mixed $by): string
    {
        if ($file === null || ! $file->isValid()) {
            throw new DomainException('That file did not arrive intact. Try it again.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new DomainException('A logo is a PNG, a JPG or an SVG. '.strtoupper($extension).' is not one this platform prints.');
        }

        $setting = EstateSetting::query()->firstOrFail();
        $previous = $setting->logo_path;

        // Random rather than "logo.png": two uploads must not collide, and an
        // old file must stay reachable by the path already recorded.
        $path = $this->prefix().'/logo-'.Str::random(16).'.'.$extension;

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        $setting->forceFill(['logo_path' => $path])->save();

        $this->audit->record(
            action: 'estate.logo_changed',
            entityType: 'EstateSetting',
            entityId: (string) $setting->id,
            before: ['logo_path' => $previous],
            after: ['logo_path' => $path, 'bytes' => $file->getSize()],
        );

        return $path;
    }

    /** The bytes, for a PDF to embed. Null when the estate has no mark yet. */
    public function contents(): ?string
    {
        $path = EstateSetting::query()->value('logo_path');

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }

    /**
     * The logo as a data: URI, which is how a PDF renderer takes it.
     *
     * EMBEDDED, NEVER LINKED. dompdf fetching a URL would mean the renderer
     * making an HTTP request back into the application — from a queue worker,
     * without a session, against a host it may not resolve — and a statement
     * whose logo silently failed to load is one nobody notices until a resident
     * holds it.
     */
    public function dataUri(): ?string
    {
        $bytes = $this->contents();

        if ($bytes === null) {
            return null;
        }

        $path = (string) EstateSetting::query()->value('logo_path');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $type = match ($extension) {
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };

        return 'data:'.$type.';base64,'.base64_encode($bytes);
    }

    public function stream(): StreamedResponse
    {
        $bytes = $this->contents();

        abort_if($bytes === null, 404);

        $path = (string) EstateSetting::query()->value('logo_path');

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'svg' => 'image/svg+xml',
                'jpg', 'jpeg' => 'image/jpeg',
                default => 'image/png',
            },
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Where this estate's files live. Never another estate's. */
    private function prefix(): string
    {
        return 'estates/'.tenant()->getTenantKey().'/branding';
    }
}
