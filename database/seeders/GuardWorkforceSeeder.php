<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Guards and posts, mirroring the approved wireframe's roster.
 *
 * Includes Devon Palmer with a lapsed PSRA licence deliberately. The compliance
 * screen exists for exactly that case, and a seed where every licence is valid
 * would leave it permanently empty and untested.
 */
class GuardWorkforceSeeder extends Seeder
{
    /** [name, employee no, psra, employment, status, post, licence offset in days] */
    private const ROSTER = [
        ['Marcus Whyte', 'GS-1041', 'PSRA-004471', 'full_time', 'active', 'Main Gate', 210],
        ['Renae Cross', 'GS-1052', 'PSRA-004512', 'full_time', 'active', 'Patrol - Phase 2-5', 168],
        ['Devon Palmer', 'GS-1049', 'PSRA-004498', 'full_time', 'licence_expired', 'Service Gate', -14],
        ['Marlon Bailey', 'GS-1063', 'PSRA-004633', 'part_time', 'on_leave', 'Main Gate - relief', 96],
        ['Kadeem Foster', 'GS-1070', 'PSRA-004701', 'full_time', 'active', 'Main Gate', 21],
        ['Andre Simpson', 'GS-1071', 'PSRA-004715', 'full_time', 'active', 'Patrol', 289],
    ];

    public function run(): void
    {
        $estates = Tenant::orderBy('id')->get();

        if ($estates->isEmpty()) {
            $this->command?->warn('No estates provisioned; skipping guard workforce seed.');

            return;
        }

        foreach (self::ROSTER as $i => [$name, $employeeNo, $psra, $type, $status, $postName, $offset]) {
            // Spread the roster across whatever estates exist, so the
            // cross-client roster and the assigned-sites scope both have
            // something real to distinguish.
            $estate = $estates[$i % $estates->count()];

            $post = Post::firstOrCreate(
                ['tenant_id' => $estate->getTenantKey(), 'name' => $postName],
                ['type' => str_contains(strtolower($postName), 'patrol') ? 'patrol' : 'gate'],
            );

            Guard::updateOrCreate(
                ['employee_number' => $employeeNo],
                [
                    'full_name' => $name,
                    'psra_number' => $psra,
                    'psra_expires_on' => now()->addDays($offset)->toDateString(),
                    'employment_type' => $type,
                    'status' => $status,
                    'phone' => '876-555-'.str_pad((string) (2000 + $i), 4, '0', STR_PAD_LEFT),
                    'email' => str($name)->lower()->replace(' ', '.')->append('@geminisecurity.test')->value(),
                    'hired_on' => now()->subMonths(8 + $i)->toDateString(),
                    'tenant_id' => $estate->getTenantKey(),
                    'post_id' => $post->id,
                ],
            );
        }
    }
}
