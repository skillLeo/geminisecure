<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * Access and audit log, Super Admin screen 41.
 *
 * Read-only, and it offers no way to change anything — not even a disabled
 * one. A greyed-out delete button says deletion is a thing that could be
 * switched on, and for this table it cannot be: audit_log is append-only at a
 * withheld grant, at a database trigger and in App\Models\AuditEntry. So this
 * screen has no edit control, no delete control, and no route that would
 * accept one.
 *
 * Everything the reader can change — the search, the three filters, the sort
 * and the page — is a GET parameter, resolved here and applied in the query.
 * That makes any view of the log a URL somebody else can be sent, which is
 * what an audit trail is usually being read for.
 *
 * Requested parameters are sanitised rather than validated. A hand-edited or
 * stale URL should show the log it can make sense of, with the filters it
 * understood named above the table, rather than bounce the reader to an error.
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request, AuditLogger $log): Response
    {
        $user = $request->user();

        $filters = [
            'q' => $this->text($request, 'q'),
            'actor' => $this->text($request, 'actor'),
            'category' => $this->text($request, 'category'),
            'from' => $this->date($request, 'from'),
            'to' => $this->date($request, 'to'),
        ];

        $sortKey = AuditLogger::sortKey($this->text($request, 'sort'));
        $direction = AuditLogger::direction($this->text($request, 'dir'));

        /*
         * A site-scoped role sees entries for its estates, plus platform-level
         * entries that concern no single estate. Passed into the query, never
         * applied to its result: sorting, filtering or paging past the list
         * must not widen what it can reach.
         */
        $estateIds = $user->widestScope() === AccessScope::AssignedSites
            ? $user->accessibleEstateIds()
            : null;

        $result = $log->search($filters, $sortKey, $direction, $estateIds);

        return inertia('Gemini/Audit/Index', [
            'rows' => $result['rows'],
            'columns' => AuditLogger::columns(),
            'filters' => $filters,
            'sort' => ['key' => $sortKey, 'direction' => $direction],
            'pagination' => [
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
                'previous_url' => $result['previous_url'],
                'next_url' => $result['next_url'],
            ],
            'basePath' => route('gemini.access_audit_log', [], false),

            /*
             * The console shell owns the top-bar search field, and searches
             * whatever URL it is handed. Handing it this screen's current
             * filters and sort — everything except the term itself and the
             * page number — is what makes searching narrow the view already on
             * screen rather than replace it, and start again at page one.
             */
            'searchRoute' => route('gemini.access_audit_log', array_filter([
                'actor' => $filters['actor'],
                'category' => $filters['category'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'sort' => $sortKey === AuditLogger::DEFAULT_SORT ? null : $sortKey,
                'dir' => $direction === AuditLogger::DEFAULT_DIRECTION ? null : $direction,
            ], fn (?string $value): bool => $value !== null), false),
        ]);
    }

    /** A trimmed, length-capped query parameter, or null when it is absent or empty. */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 120, '');
    }

    /**
     * A calendar date, or null.
     *
     * Checked for shape and for existence rather than parsed. The value only
     * ever reaches a whereDate(), so a well-formed real date is all that is
     * needed, and a parser here would raise on '2026-02-31' in a pasted URL
     * that should simply be ignored.
     */
    private function date(Request $request, string $key): ?string
    {
        $value = $this->text($request, $key);

        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
