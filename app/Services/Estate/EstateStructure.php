<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phases and the addresses in them — board 3's two writes (12 §2, Wave 1).
 *
 * AN ADDRESS IS WHAT A HOUSEHOLD IS FILED AT AND DUES ARE BILLED TO, so both
 * writes here are all-or-nothing. A phase is added with its lot range decided
 * together and every lot created in one transaction; a unit list is validated
 * whole before one row of it is written, and a bad file is rejected whole. A
 * half-imported estate is a dues run that bills some households and not
 * others.
 *
 * NOTHING HERE DELETES. A unit with a ledger cannot go, and the estate has
 * never had a path that removes one.
 */
class EstateStructure
{
    /** The most rows one import may carry — enough for any estate on this platform, and a ceiling on a runaway file. */
    public const IMPORT_MAX_ROWS = 5000;

    public const TYPES = ['residential', 'commercial'];

    public const STATUSES = ['occupied', 'vacant'];

    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------------------------------------------ */
    /* add phase */
    /* ------------------------------------------------------------------ */

    /**
     * Add a phase, and the lots in it.
     *
     * The blocks and the lot range are decided together, as the reason on the
     * old inert control said they had to be. A phase may be added with no
     * lots — an estate taking on land before the addresses exist — and take
     * them from an import later. Every lot reference is checked against the
     * estate before any is written, so a range that overlaps an existing lot
     * creates nothing.
     *
     * @return array{phase: Phase, units: int}
     */
    public function addPhase(
        string $name,
        int $blockCount,
        ?int $lotFrom,
        ?int $lotTo,
        ?string $street,
        User $by,
    ): array {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('A phase has a name — Phase 6, or Hillside — and every lot in it is filed under that name.');
        }

        if (Phase::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
            throw new DomainException($name.' already exists. Two phases with one name would file two sets of lots under it.');
        }

        if (Unit::query()->where('block', $name)->exists()) {
            throw new DomainException(sprintf(
                'Units already stand in a block called %s. Adding it as a phase would claim them; import them under it instead.',
                $name,
            ));
        }

        if ($blockCount < 0 || $blockCount > 200) {
            throw new DomainException('A phase has between none and two hundred blocks.');
        }

        if (($lotFrom === null) !== ($lotTo === null)) {
            throw new DomainException('A lot range has both ends, or neither.');
        }

        $references = [];

        if ($lotFrom !== null && $lotTo !== null) {
            if ($lotFrom < 1 || $lotTo < $lotFrom || $lotTo - $lotFrom >= self::IMPORT_MAX_ROWS) {
                throw new DomainException('A lot range runs upward from 1, and no phase has more than five thousand lots.');
            }

            for ($lot = $lotFrom; $lot <= $lotTo; $lot++) {
                $references[] = 'Lot '.$lot;
            }

            $taken = Unit::query()->whereIn('reference', $references)->orderBy('reference')->pluck('reference');

            if ($taken->isNotEmpty()) {
                throw new DomainException(sprintf(
                    '%d of those lots already exist — %s%s. A range that overlaps the estate creates nothing; choose lots the estate does not have.',
                    $taken->count(),
                    $taken->take(5)->implode(', '),
                    $taken->count() > 5 ? ', …' : '',
                ));
            }
        }

        return DB::connection('tenant')->transaction(function () use ($name, $blockCount, $street, $references): array {
            $phase = Phase::create([
                'name' => $name,
                'sequence' => ((int) Phase::query()->max('sequence')) + 1,
                'block_count' => $blockCount,
                'officers_assigned' => null,
                'status' => Phase::NEW,
                'footnote' => 'officers pending',
            ]);

            $now = now();

            $rows = array_map(static fn (string $reference): array => [
                'reference' => $reference,
                'block' => $name,
                'street' => $street === null || trim($street) === '' ? null : trim($street),
                'type' => 'residential',
                'status' => 'vacant',
                'created_at' => $now,
                'updated_at' => $now,
            ], $references);

            foreach (array_chunk($rows, 500) as $chunk) {
                Unit::query()->insert($chunk);
            }

            $this->audit->record(
                action: 'estate.phase_added',
                entityType: 'Phase',
                entityId: (string) $phase->id,
                after: ['name' => $name, 'blocks' => $blockCount, 'units' => count($references)],
            );

            return ['phase' => $phase, 'units' => count($references)];
        });
    }

    /* ------------------------------------------------------------------ */
    /* import CSV */
    /* ------------------------------------------------------------------ */

    /**
     * Parse and validate a unit list. Nothing is written.
     *
     * COLUMNS: reference, phase, street, type, status — a header row names
     * them in any order; only reference and phase are required. Every row is
     * checked, every problem is reported against its line, and the file is
     * valid only when no row has one. A phase named in the file that does not
     * exist is created on commit and reported here as new.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, new_phases: list<string>, valid: bool}
     */
    public function parseUnits(string $contents): array
    {
        $lines = preg_split('/\R/', trim($contents)) ?: [];

        if (count($lines) < 2) {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'The file has no rows under its header.']], 'new_phases' => [], 'valid' => false];
        }

        $header = array_map(static fn (string $h): string => strtolower(trim($h, " \t\"'")), str_getcsv(array_shift($lines)));
        $required = ['reference', 'phase'];
        $errors = [];

        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                $errors[] = ['line' => 1, 'message' => 'The header has no "'.$column.'" column. Expected: reference, phase, street, type, status.'];
            }
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors, 'new_phases' => [], 'valid' => false];
        }

        if (count($lines) > self::IMPORT_MAX_ROWS) {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'The file has more than '.number_format(self::IMPORT_MAX_ROWS).' rows. Split it.']], 'new_phases' => [], 'valid' => false];
        }

        $known = Phase::query()->pluck('name')->map(static fn (string $n): string => strtolower($n))->flip();
        $existing = Unit::query()->pluck('reference')->map(static fn (string $r): string => strtolower($r))->flip();

        $rows = [];
        $seen = [];
        $newPhases = [];

        foreach ($lines as $index => $line) {
            $lineNo = $index + 2;

            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $row = [];

            foreach ($header as $i => $column) {
                $row[$column] = trim((string) ($cells[$i] ?? ''));
            }

            $reference = $row['reference'] ?? '';
            $phase = $row['phase'] ?? '';
            $type = strtolower($row['type'] ?? '') ?: 'residential';
            $status = strtolower($row['status'] ?? '') ?: 'vacant';

            if ($reference === '' || strlen($reference) > 32) {
                $errors[] = ['line' => $lineNo, 'message' => 'A reference is required and runs to 32 characters.'];
            } elseif (isset($existing[strtolower($reference)])) {
                $errors[] = ['line' => $lineNo, 'message' => $reference.' already exists in this estate.'];
            } elseif (isset($seen[strtolower($reference)])) {
                $errors[] = ['line' => $lineNo, 'message' => $reference.' appears twice in the file (first on line '.$seen[strtolower($reference)].').'];
            }

            $seen[strtolower($reference)] = $lineNo;

            if ($phase === '' || strlen($phase) > 64) {
                $errors[] = ['line' => $lineNo, 'message' => 'A phase is required and runs to 64 characters.'];
            } elseif (! isset($known[strtolower($phase)])
                && ! in_array(strtolower($phase), array_map('strtolower', $newPhases), true)) {
                // First spelling wins: "Phase 7" on line 2 and "phase 7" on
                // line 3 are one new phase, named the way line 2 wrote it.
                $newPhases[] = $phase;
            }

            if (! in_array($type, self::TYPES, true)) {
                $errors[] = ['line' => $lineNo, 'message' => 'Type is residential or commercial, not "'.$type.'".'];
            }

            if (! in_array($status, self::STATUSES, true)) {
                $errors[] = ['line' => $lineNo, 'message' => 'Status is occupied or vacant, not "'.$status.'".'];
            }

            $rows[] = [
                'line' => $lineNo,
                'reference' => $reference,
                'phase' => $phase,
                'street' => ($row['street'] ?? '') === '' ? null : substr($row['street'], 0, 120),
                'type' => $type,
                'status' => $status,
            ];
        }

        if ($rows === []) {
            $errors[] = ['line' => 2, 'message' => 'The file has no rows under its header.'];
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'new_phases' => $newPhases,
            'valid' => $errors === [],
        ];
    }

    /**
     * Commit a validated unit list — all of it, or none of it.
     *
     * Re-validated here, not trusted from the preview: the estate may have
     * changed between the two presses, and a reference that was free a minute
     * ago may not be now. The whole file is refused if a single row fails.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{units: int, phases: int}
     */
    public function importUnits(array $rows, User $by): array
    {
        $contents = "reference,phase,street,type,status\n";

        foreach ($rows as $row) {
            $contents .= implode(',', array_map(
                static fn ($v): string => '"'.str_replace('"', '""', (string) ($v ?? '')).'"',
                [$row['reference'], $row['phase'], $row['street'] ?? '', $row['type'], $row['status']],
            ))."\n";
        }

        $parsed = $this->parseUnits($contents);

        if (! $parsed['valid']) {
            throw new DomainException(
                'The list no longer passes: '.($parsed['errors'][0]['message'] ?? 'a row failed').' Nothing was imported — preview it again.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($parsed): array {
            $sequence = (int) Phase::query()->max('sequence');

            foreach ($parsed['new_phases'] as $name) {
                Phase::create([
                    'name' => $name,
                    'sequence' => ++$sequence,
                    'block_count' => 0,
                    'officers_assigned' => null,
                    'status' => Phase::NEW,
                    'footnote' => 'officers pending',
                ]);
            }

            // The phase name as the estate spells it, so "phase 2" in a file
            // files under the existing "Phase 2" rather than beside it — and
            // under the phase this import just created, spelt the way its
            // first row spelt it.
            $canonical = Phase::query()->pluck('name')->keyBy(static fn (string $n): string => strtolower($n));

            $now = now();
            $units = array_map(static fn (array $row): array => [
                'reference' => $row['reference'],
                'block' => (string) ($canonical[strtolower($row['phase'])] ?? $row['phase']),
                'street' => $row['street'],
                'type' => $row['type'],
                'status' => $row['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $parsed['rows']);

            foreach (array_chunk($units, 500) as $chunk) {
                Unit::query()->insert($chunk);
            }

            $this->audit->record(
                action: 'estate.units_imported',
                entityType: 'Unit',
                entityId: null,
                after: ['units' => count($units), 'phases_created' => count($parsed['new_phases'])],
            );

            return ['units' => count($units), 'phases' => count($parsed['new_phases'])];
        });
    }
}
