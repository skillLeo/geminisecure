<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\CategoryB;
use App\Support\SourceBadge;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Category B — 13 C3
|--------------------------------------------------------------------------
|
| "Define it: any screen whose primary dataset originates on a mobile device.
| Enumerate them in docs/CATEGORY_B.md, then assert in a test that every one
| renders SourceBadge."
|
| Both directions, so the list and the code cannot drift: every listed page
| renders the badge, and no page renders the badge without being listed.
|
*/

function categoryBPage(string $component): string
{
    return (string) file_get_contents(resource_path('js/Pages/'.$component.'.vue'));
}

it('renders SourceBadge on every Category B screen, from a required prop', function () {
    foreach (array_keys(CategoryB::SCREENS) as $component) {
        $page = categoryBPage($component);

        expect(str_contains($page, '<SourceBadge v-bind="sourceBadge" />'))->toBeTrue("{$component} is Category B and renders no source badge")
            ->and(preg_match('/sourceBadge:\s*\{\s*type:\s*Object,\s*required:\s*true\s*\}/', $page))->toBe(1, "{$component} does not require the sourceBadge prop");
    }
});

it('lists every screen that renders SourceBadge, so the badge cannot appear on a screen nobody classified', function () {
    $root = resource_path('js/Pages').DIRECTORY_SEPARATOR;
    $rendering = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->getExtension() === 'vue' && str_contains((string) file_get_contents($file->getPathname()), '<SourceBadge')) {
            $rendering[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root), -4));
        }
    }

    sort($rendering);
    $listed = array_keys(CategoryB::SCREENS);
    sort($listed);

    expect($rendering)->toBe($listed)
        ->and(array_intersect($listed, array_keys(CategoryB::EXCLUDED)))->toBe([]);
});

it('names every Category B screen and every near miss in docs/CATEGORY_B.md', function () {
    $doc = (string) file_get_contents(base_path('docs/CATEGORY_B.md'));

    foreach ([...array_keys(CategoryB::SCREENS), ...array_keys(CategoryB::EXCLUDED)] as $component) {
        expect($doc)->toContain('`'.$component.'`');
    }

    expect($doc)->toContain('## The screens: '.count(CategoryB::SCREENS));
});

it('sends the badge from the server on the Category B screens, computed from the rows', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    try {
        $this->withoutVite();

        $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        foreach (['/dispatch/requests', '/dispatch/requests/history', '/guards/incidents'] as $path) {
            $this->actingAs($director)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('sourceBadge.source', SourceBadge::GUARD)->has('sourceBadge.simulated'));
        }

        $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
        $ticket = DB::connection('tenant')->table('maintenance_tickets')->orderBy('id')->first();
        $booking = DB::connection('tenant')->table('amenity_bookings')->orderBy('id')->first();

        $estatePaths = [
            '/facilities/maintenance',
            '/facilities/amenities/bookings',
            '/residents/claims',
            '/governance/elections/'.now()->year,
            '/governance/elections/'.now()->year.'/results',
        ];

        if ($ticket !== null) {
            $estatePaths[] = '/facilities/maintenance/'.$ticket->number;
        }

        if ($booking !== null) {
            $estatePaths[] = '/facilities/amenities/bookings/'.$booking->id;
        }

        foreach ($estatePaths as $path) {
            $this->actingAs($admin)
                ->get(FacilitiesFixture::url($path))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('sourceBadge.source', SourceBadge::RESIDENT)->has('sourceBadge.simulated'));

            FacilitiesFixture::boot();
        }

        // Computed from the rows: flag one ticket as simulated and the queue says so.
        if ($ticket !== null) {
            DB::connection('tenant')->table('maintenance_tickets')->where('id', $ticket->id)->update(['is_simulated' => true]);

            try {
                $this->actingAs($admin)
                    ->get(FacilitiesFixture::url('/facilities/maintenance'))
                    ->assertInertia(fn (AssertableInertia $page) => $page->where('sourceBadge.simulated', true));
            } finally {
                FacilitiesFixture::boot();
                DB::connection('tenant')->table('maintenance_tickets')->where('id', $ticket->id)->update(['is_simulated' => false]);
            }
        }
    } finally {
        DB::connection('mysql')->rollBack();
    }
});
