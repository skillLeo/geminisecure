<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Enums\AccessLevel;
use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\Estate\EstateFeature;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\NotificationDefault;
use App\Models\Estate\Unit;
use App\Models\EstateAssignment;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Subscription;
use App\Models\User;
use App\Support\MoneyFormatter;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The estate's own settings — boards 21 through 24, 30, 33 and 40.
 *
 * SEVEN SCREENS, AND STILL ONLY THREE OF THEM OWN ANY DATA OF THEIR OWN.
 *
 *   21  Estate profile      the client record, READ from `tenants` and from
 *                           this estate's own units; the contact details, owned
 *                           here and written here
 *   22  Users & roles        entirely central — users, roles, assignments
 *   23  Feature toggles      catalogue central, the estate's overrides local
 *   24  Role access matrix   entirely central, and READ-ONLY
 *   30  Notification defaults the estate's own eighteen switches, owned here
 *                           and written here
 *   33  Data & privacy       FOUR POLICY STATEMENTS AND NO STORAGE AT ALL. Every
 *                           value is either fixed copy this screen carries or a
 *                           fact read back from the permission model; none of it
 *                           is a column, because nobody has been asked to make
 *                           any of it configurable
 *   40  Billing & subscription entirely CENTRAL, and READ-ONLY — see the class
 *                           docblock on `billingBoard()` for why this one is not
 *                           like the other six
 *
 * THE MATRIX IS READ, NEVER WRITTEN, AND THAT IS THE WHOLE SECURITY ARGUMENT OF
 * THIS MODULE.
 * ======================================================================
 *
 * Board 24 draws sixty pills and no control that changes one — no button, no
 * dropdown, no save. That is not an omission in the drawing. Changing what a
 * role may do is an `approve`-level act on the Settings module by the rule this
 * system already applies to every irreversible thing (D-013), and the seeded
 * estate matrix gives `estate.settings.approve` to NOBODY: the Settings row is
 * Full for the Community Super Admin and View for the President and Vice
 * President, and `can_approve` is false in every one of those cells.
 *
 * So the privilege-escalation route does not exist in three independent ways at
 * once, and each would have to be reversed deliberately to open it:
 *
 *   1. no route in `routes/tenant.php` writes `role_module_access`
 *   2. this class has no method that writes it either
 *   3. `matrixBoard()` reports `can_edit` from `estate.settings.approve`, which
 *      no estate role holds — so even the Community Super Admin, the widest
 *      role there is, is told the matrix is not editable from this console
 *
 * A settings screen is exactly where a privilege escalation would be built by
 * accident, which is why it is refused three times rather than once.
 * `EstateSettingsTest` walks all seven estate roles and proves each of the
 * three, rather than assuming any of them.
 *
 * THE MODEL WINS OVER THE DRAWING, ALWAYS (D-044). Board 24 draws the Property
 * Manager with View on Dues & ledger and on Accounting; Ruling 1 (D-010) locks
 * that role out of both, and the seeder refuses to grant them. The matrix this
 * screen renders is read from `role_module_access` — thirteen modules and seven
 * roles, not the drawn ten and six — so what a committee reads here is what the
 * console actually enforces. The discrepancies are recorded in DECISIONS.md and
 * resolved in neither direction here.
 *
 * NOTHING ON BOARD 21 THAT ALREADY EXISTS IS COPIED. The estate name, its
 * address, its unit count and its phase count are the central client record and
 * this estate's own units, read at the boundary and shown read-only with the
 * reason on each field. A form that wrote them would give the community a
 * second name, free to diverge from the one on Gemini's invoices and on the
 * dispatch board that sends a supervisor to the site.
 */
class Settings
{
    /**
     * Board 21's seven-item sub-navigation, in the order every settings board
     * draws it.
     *
     * FOUR OF THE SEVEN ARE BUILT. The other three are shown and inert for the
     * same reason `EstateNavigation` shows an unbuilt module: the point of a
     * settings index is that an administrator can see what this console holds,
     * and a link into a 404 is worse than a caption saying "not yet".
     *
     * @var list<array{key: string, label: string, href: string|null}>
     */
    /*
     * All seven are built and all seven link. A section added ahead of its
     * route turned this strip into three 404s on every settings screen once --
     * the same fault EstateNavigation warns about, in the opposite direction --
     * so a new one goes live in the same change that registers its route.
     */
    private const SECTIONS = [
        ['key' => 'profile', 'label' => 'Estate profile', 'href' => '/settings/profile'],
        ['key' => 'users', 'label' => 'Users & roles', 'href' => '/settings/users'],
        ['key' => 'features', 'label' => 'Feature toggles', 'href' => '/settings/features'],
        ['key' => 'roles', 'label' => 'Role access matrix', 'href' => '/settings/roles'],
        ['key' => 'notifications', 'label' => 'Notification defaults', 'href' => '/settings/notifications'],
        ['key' => 'billing', 'label' => 'Billing & subscription', 'href' => '/settings/billing'],
        ['key' => 'privacy', 'label' => 'Data & privacy', 'href' => '/settings/privacy'],
    ];

    /**
     * Board 23's own copy, verbatim, keyed by the catalogue's feature key.
     *
     * THE SECOND LINE ON THIS SCREEN IS NOT THE SECOND LINE ON BOARD 43. The
     * central catalogue's `sub_label` is a column caption in a package-builder
     * grid — "GL, AP/AR, bank import" — and this is a sentence for a committee
     * member deciding whether to switch something off: "General ledger, AP/AR,
     * bank reconciliation". They describe one feature to two audiences, and
     * collapsing them into one string would make one of the two screens read
     * wrongly.
     *
     * It lives here rather than on `package_features` for the same reason board
     * 28's scope banner lives in its controller: it is copy belonging to this
     * screen in this release, and adding a column to the central catalogue to
     * hold it would put a second description in front of the package builder
     * that nothing there draws.
     *
     * `estate_payroll` is absent deliberately — its line states the two
     * routings, so it is computed rather than written down.
     *
     * @var array<string, string>
     */
    private const FEATURE_COPY = [
        'guard_app' => "Patrol and incident visibility from Gemini Security's guards",
        'evoting' => 'The Election Control Room and resident ballots',
        'gated_meetings' => 'AGM/EGM scheduling visible only to owners in good standing',
        'accounting_core' => 'General ledger, AP/AR, bank reconciliation',
        'ai_drafting' => 'Draft notices, minutes, and summaries — always reviewed before sending',
        'custom_branding' => 'Logo, hero image, and colour theme on resident-facing screens',
    ];

    /** Board 23's line under all three locked rows. */
    private const CORE_COPY = 'Always on — cannot be disabled at any tier';

    /**
     * Which plan key a tier badge is drawn from, and what the badge reads.
     *
     * A feature's tier is the LOWEST plan that includes it, computed from
     * `plan_features` rather than stored on the feature. Storing it would be a
     * second answer to "is this in Standard?", and the package builder — which
     * edits the grid — would be free to disagree with it.
     *
     * @var array<string, string>
     */
    private const TIER_LABELS = [
        'essential' => 'Core',
        'standard' => 'Standard+',
        'premium' => 'Premium',
    ];

    /** @var array<string, string> tier key => the group heading board 23 draws */
    private const TIER_GROUPS = [
        'essential' => 'Core — every tier',
        'standard' => 'Standard & above',
        'premium' => 'Premium only',
    ];

    /**
     * Board 23's audit note, verbatim.
     *
     * It is a statement about how this platform behaves rather than a
     * description of the screen, so it is carried with the release rather than
     * written into a template — the same reason board 28's scope banner is.
     */
    public const FEATURES_AUDIT_NOTE = 'Every toggle change writes an audit record with actor, timestamp, and reason. '.
        'Gemini Security can set tenant-level overrides above your plan defaults; anything above is what your '.
        'community controls itself.';

    /** Board 24's audit note, verbatim. */
    public const MATRIX_AUDIT_NOTE = "Super Admin (God mode) isn't shown here — it has Full access to everything for ".
        "this tenant only, and is typically held by the property management company's lead or a technically-designated ".
        'committee member. Every role assignment and permission change writes to an immutable audit log.';

    /**
     * Why the matrix cannot be edited here, in the words a committee member
     * needs.
     *
     * Named rather than assembled at the call site: it is the answer to the
     * single most likely question this console will be asked, and it must read
     * the same wherever it is shown.
     */
    public const MATRIX_READ_ONLY = 'The role access matrix is read-only in the Estate Console. Changing what a role '.
        'may do is an approve-level act, and no estate role holds approve on Settings — including the Community '.
        'Super Admin. A permission change is made by Gemini Security against the platform matrix, so that one '.
        'community cannot quietly widen its own access.';

    /** Board 24's legend, and the four descriptions it prints beside the pills. */
    private const LEGEND = [
        ['key' => 'full', 'label' => 'Full', 'description' => 'Create, edit, approve'],
        ['key' => 'view', 'label' => 'View', 'description' => 'Read-only'],
        ['key' => 'entry', 'label' => 'Entry', 'description' => 'Data entry, no approval'],
        ['key' => 'none', 'label' => '—', 'description' => 'No access'],
    ];

    /**
     * Board 30's six events, three groups, verbatim.
     *
     * A CONSTANT AND NOT A TABLE, on the same reasoning `FEATURE_COPY` already
     * carries for board 23: the six events, their grouping and their copy are
     * this SCREEN's, not a catalogue any estate administers. Nothing on this
     * platform lets a community invent a seventh event or rename "Dues
     * reminders" — the events are what THIS PRODUCT sends, and only the
     * eighteen (event, channel) answers are the estate's own to decide. See
     * the `notification_defaults` migration for the fuller argument.
     *
     * `group_sort` and `sort` are carried explicitly rather than trusted to
     * array order, because `notificationsBoard()` groups this list by
     * `group` before it iterates it and a `foreach` over a grouped collection
     * does not promise to preserve the order the array was written in.
     *
     * @var list<array{key: string, group: string, group_sort: int, sort: int, label: string, description: string}>
     */
    private const NOTIFICATION_EVENTS = [
        [
            'key' => 'dues_reminders', 'group' => 'Financial', 'group_sort' => 1, 'sort' => 1,
            'label' => 'Dues reminders', 'description' => 'Sent to residents before and after a due date',
        ],
        [
            'key' => 'payment_plan_updates', 'group' => 'Financial', 'group_sort' => 1, 'sort' => 2,
            'label' => 'Payment plan updates', 'description' => 'Instalment due dates and confirmations',
        ],
        [
            'key' => 'new_notices_posted', 'group' => 'Community', 'group_sort' => 2, 'sort' => 1,
            'label' => 'New notices posted', 'description' => 'Estate-wide announcements from Governance',
        ],
        [
            'key' => 'meetings_elections', 'group' => 'Community', 'group_sort' => 2, 'sort' => 2,
            'label' => 'Meetings & elections', 'description' => 'AGM/EGM scheduling and ballot openings',
        ],
        [
            'key' => 'ticket_status_changes', 'group' => 'Maintenance & facilities', 'group_sort' => 3, 'sort' => 1,
            'label' => 'Ticket status changes',
            'description' => 'When a maintenance ticket is assigned, in progress, or closed',
        ],
        [
            'key' => 'amenity_booking_confirmations', 'group' => 'Maintenance & facilities', 'group_sort' => 3, 'sort' => 2,
            'label' => 'Amenity booking confirmations', 'description' => 'Deposit received, booking confirmed or cancelled',
        ],
    ];

    /**
     * Board 30's own starting values, seeded once by `SettingsSeeder` and never
     * again — see that seeder for why a second run must not touch a committee's
     * own choice.
     *
     * `[event_key][channel] => bool`, thirteen on and five off, transcribed from
     * the board rather than chosen: this is a picture of a real estate's
     * defaults, not a platform recommendation this file is entitled to revise.
     *
     * @var array<string, array<string, bool>>
     */
    public const NOTIFICATION_SEED = [
        'dues_reminders' => ['email' => true, 'sms' => true, 'push' => true],
        'payment_plan_updates' => ['email' => true, 'sms' => false, 'push' => true],
        'new_notices_posted' => ['email' => true, 'sms' => false, 'push' => true],
        'meetings_elections' => ['email' => true, 'sms' => true, 'push' => true],
        'ticket_status_changes' => ['email' => false, 'sms' => false, 'push' => true],
        'amenity_booking_confirmations' => ['email' => true, 'sms' => false, 'push' => true],
    ];

    /* ------------------------------------------------------------------ */
    /* the shell every settings screen shares */
    /* ------------------------------------------------------------------ */

    /**
     * The sub-navigation, with one item marked current.
     *
     * ALL SEVEN SECTIONS ARE BUILT, so every one of these carries a real href
     * and `pending` is false throughout. The key is kept on the payload rather
     * than dropped because the pages still branch on it: an eighth section added
     * before its screen exists must arrive here as pending and be drawn inert,
     * never as a link to a route that answers 404. That is the fault this strip
     * already had once, when three sections were set live ahead of their routes.
     *
     * @return list<array{key: string, label: string, href: string, active: bool, pending: bool}>
     */
    public function sections(string $active, string $tenantKey = ''): array
    {
        $items = [];

        foreach (self::SECTIONS as $section) {
            $items[] = [
                'key' => $section['key'],
                'label' => $section['label'],
                'href' => $this->path($section['href'], $tenantKey),
                'active' => $section['key'] === $active,
                'pending' => false,
            ];
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /* board 21 — estate profile */
    /* ------------------------------------------------------------------ */

    /**
     * The profile form, every field already resolved and already formatted.
     *
     * TWO KINDS OF FIELD, AND THE DIFFERENCE IS STATED ON EACH. The client
     * record — name, address, units, phases — is read from where it already
     * lives and carries `editable: false` with the reason; the estate's own
     * contact details are editable and carry none. A page built from this draws
     * the same form the board draws and simply disables four inputs.
     *
     * @return array<string, mixed>
     */
    public function profileBoard(): array
    {
        $setting = EstateSetting::current();
        $estate = tenant();

        $name = $estate === null ? '' : (string) $estate->name;
        $address = $estate === null ? null : $estate->site_address;

        /*
         * "Waterloo Road, St. Andrew, Jamaica" — the board's third part.
         *
         * The country is appended rather than stored: every estate on this
         * platform is Jamaican, the currency ruling says so (D-020), and a
         * `country` column populated with one value on every row would be a
         * column that tells nobody anything.
         */
        if ($address !== null) {
            $address .= ', Jamaica';
        }

        return [
            'estate' => [
                'name' => $name,
                'address' => $address,
            ],
            'logo' => [
                'path' => $setting->logo_path,
                'caption' => 'Estate logo — shown on resident notices and receipts',
                'action' => 'Upload new logo',
            ],
            'groups' => [
                [
                    'title' => 'Estate details',
                    'fields' => [
                        [
                            'key' => 'name',
                            'label' => 'Estate name',
                            'value' => $name,
                            'type' => 'text',
                            'span' => 'full',
                            'editable' => false,
                            'reason' => self::NO_RENAME_HERE,
                        ],
                        [
                            'key' => 'address',
                            'label' => 'Address',
                            'value' => $address ?? '',
                            'type' => 'text',
                            'span' => 'full',
                            'editable' => false,
                            'reason' => self::NO_RENAME_HERE,
                        ],
                        [
                            /*
                             * COUNTED, not stored. 450 units is `SELECT
                             * COUNT(*) FROM units` in this database, which is
                             * the same 450 board 2's phase table adds up to and
                             * the same 450 the subscription is priced on. A
                             * stored copy would be a fourth number free to
                             * disagree with three.
                             */
                            'key' => 'total_units',
                            'label' => 'Total units',
                            'value' => (string) Unit::query()->count(),
                            'type' => 'number',
                            'span' => 'half',
                            'editable' => false,
                            'reason' => self::NO_COUNT_HERE,
                        ],
                        [
                            // Derived from the phase STRUCTURE on the client
                            // record, exactly as D-033 ruled: phases have names
                            // residents use, and the count follows from them.
                            'key' => 'phase_count',
                            'label' => 'Number of phases',
                            'value' => (string) ($estate === null ? 0 : $estate->phase_count),
                            'type' => 'number',
                            'span' => 'half',
                            'editable' => false,
                            'reason' => self::NO_COUNT_HERE,
                        ],
                    ],
                ],
                [
                    'title' => 'Contact',
                    'fields' => [
                        [
                            'key' => 'enquiries_email',
                            'label' => 'General enquiries email',
                            'value' => (string) ($setting->enquiries_email ?? ''),
                            'type' => 'email',
                            'span' => 'half',
                            'editable' => true,
                            'reason' => null,
                        ],
                        [
                            'key' => 'enquiries_phone',
                            'label' => 'Phone',
                            'value' => (string) ($setting->enquiries_phone ?? ''),
                            'type' => 'tel',
                            'span' => 'half',
                            'editable' => true,
                            'reason' => null,
                        ],
                    ],
                ],
                [
                    'title' => 'Security provider',
                    'fields' => [
                        [
                            'key' => 'security_provider',
                            'label' => 'Provider',
                            'value' => $setting->security_provider,
                            'type' => 'text',
                            'span' => 'full',
                            'editable' => true,
                            'reason' => null,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Why the four client-record fields are shown and not edited. */
    private const NO_RENAME_HERE = 'The estate name and address are the security company\'s client record — they '.
        'appear on invoices and on the board that sends a supervisor to this site. Changing one is a request to '.
        'Gemini Security, so that a rename here cannot quietly rename the client there.';

    private const NO_COUNT_HERE = 'Counted, never typed. The unit total is the number of units on record in this '.
        'estate and the phase count follows from the phase structure, so neither can drift from what the rest of '.
        'the console reports.';

    /**
     * Save the estate's own contact details.
     *
     * ONLY THREE FIELDS REACH THE DATABASE, and the allowlist is here rather
     * than in the request so that a second caller — the /api/v1 endpoint the
     * mobile apps will use — cannot widen it by validating differently. Every
     * held-back flag is unreachable twice over: it is not in this list and it is
     * not in the model's `$fillable` either.
     *
     * @param  array<string, string|null>  $input
     */
    public function updateProfile(array $input, User $by): EstateSetting
    {
        $setting = EstateSetting::current();

        $before = [
            'enquiries_email' => $setting->enquiries_email,
            'enquiries_phone' => $setting->enquiries_phone,
            'security_provider' => $setting->security_provider,
        ];

        $provider = trim((string) ($input['security_provider'] ?? ''));

        if ($provider === '') {
            throw new DomainException(
                'An estate has a security provider. Leaving the field empty would leave a resident notice with '.
                'nobody named on it as the company standing at the gate.'
            );
        }

        $setting->fill([
            // Empty means "not published", which is a real answer for an estate
            // that takes enquiries by phone only — so it is stored as null
            // rather than as an empty string nobody can distinguish from unset.
            'enquiries_email' => $this->nullIfBlank($input['enquiries_email'] ?? null),
            'enquiries_phone' => $this->nullIfBlank($input['enquiries_phone'] ?? null),
            'security_provider' => $provider,
        ])->save();

        $this->audit(
            action: 'estate.settings.profile_updated',
            by: $by,
            entityType: 'estate_setting',
            entityId: (string) $setting->getKey(),
            before: $before,
            after: [
                'enquiries_email' => $setting->enquiries_email,
                'enquiries_phone' => $setting->enquiries_phone,
                'security_provider' => $setting->security_provider,
            ],
        );

        return $setting;
    }

    /* ------------------------------------------------------------------ */
    /* board 22 — users & roles */
    /* ------------------------------------------------------------------ */

    /**
     * Everybody who may reach this estate, and what each of them may reach.
     *
     * THE MODULE LIST IS THE MATRIX, READ BACK. Board 22 prints "Dues & ledger,
     * Accounting, Payroll" against the Treasurer and "All modules" against the
     * President. The first is a summary somebody wrote and the second is a
     * derivation; only one of the two can be kept true as the matrix changes,
     * so both are derived here — a role reaches a module when its cell is above
     * `none`, and "All modules" is what it reads when that is true of every
     * module. D-044: where the drawn cells and the model disagree, the model
     * wins, and the difference is recorded rather than reconciled.
     *
     * @return array<string, mixed>
     */
    public function usersBoard(string $tenantKey = ''): array
    {
        $moduleCount = Module::query()->where('console', Console::Estate->value)->count();

        /*
         * ESTATE ROLES ONLY, AND THIS FILTER IS NOT COSMETIC.
         *
         * Gemini's own Head of Security holds an active assignment to Phoenix
         * Park — that is how a platform role is narrowed to assigned sites, and
         * it is what makes them visible on the estate's own dispatch. They are
         * not a member of this community's committee, they hold no estate role,
         * and listing them on a screen headed "Users & roles" beside a Manage
         * link would invite a committee to try to change the access of somebody
         * who does not work for them. Their module list would also be Gemini's
         * — Clients, Dispatch, Guard workforce — which are not this console's
         * modules at all.
         */
        $assignments = EstateAssignment::query()
            ->with(['user', 'role.moduleAccess.module'])
            ->where('tenant_id', $tenantKey)
            ->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->where('console', Console::Estate->value))
            ->get()
            ->sortBy(fn (EstateAssignment $a): int => $a->role->sort)
            ->values();

        /*
         * The amber "Owner" badge on exactly one row, and it is DERIVED.
         *
         * The estate's owner is whoever holds the seniormost estate role here —
         * the Community Super Admin where one has been designated, and the
         * President where none has. There is no `is_owner` column, because a
         * stored flag would be a second answer to a question the role already
         * answers, and the two would disagree the first time a committee
         * changed hands. Null on an estate with nobody assigned at all, which
         * draws no badge rather than an arbitrary one.
         */
        $owner = $assignments->first();

        $rows = [];

        foreach ($assignments as $assignment) {
            $user = $assignment->user;
            $role = $assignment->role;

            $reachable = $role->moduleAccess
                ->filter(fn (RoleModuleAccess $cell): bool => $cell->level->isVisible())
                ->sortBy(fn (RoleModuleAccess $cell): int => $cell->module->sort)
                ->map(fn (RoleModuleAccess $cell): string => $cell->module->label)
                ->values()
                ->all();

            $rows[] = [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $this->initials($user->name),
                'role' => $role->name,
                'role_label' => (string) ($role->label ?? $role->name),

                // "President · Owner" — the middot is literal on the board, and
                // it is assembled here so no template has to know the rule.
                'role_badge' => $assignment->is($owner)
                    ? (string) ($role->label ?? $role->name).' · Owner'
                    : (string) ($role->label ?? $role->name),
                'is_owner' => $assignment->is($owner),

                'modules' => count($reachable) === $moduleCount && $moduleCount > 0
                    ? 'All modules'
                    : implode(', ', $reachable),
                'module_list' => $reachable,

                'status' => $user->status,
                'status_label' => ucfirst($user->status),

                /*
                 * Board 22 draws a "Manage" link per row and a topbar "Invite
                 * user". Neither is built, and neither is a link into a route
                 * that answers 404 — the reasons below say why, in the same
                 * shape the payables screens use.
                 */
                'manage_href' => null,
            ];
        }

        return [
            'rows' => $rows,
            'owner_role' => $owner === null ? null : $owner->role->name,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 23 — feature toggles */
    /* ------------------------------------------------------------------ */

    /**
     * Every feature this estate's plan offers it, and what the estate decided.
     *
     * THE EFFECTIVE ANSWER IS RESOLVED, NEVER READ FROM ONE PLACE:
     *
     *     locked core   → on, and not switchable at any tier
     *     override      → whatever this community decided, with who and why
     *     otherwise     → whether the estate's plan includes it
     *
     * That ordering is why `estate_features` holds overrides only. An estate
     * with a row per feature would look decided when nothing had been decided,
     * and the day the plan changed, eleven stale copies of its old answer would
     * outvote it.
     *
     * @return array<string, mixed>
     */
    public function featuresBoard(string $tenantKey = ''): array
    {
        $setting = EstateSetting::current();
        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantKey)->first();
        $planKey = $subscription === null ? null : $subscription->plan->key;

        $overrides = $this->overrides();
        $catalogue = $this->catalogue();

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($catalogue as $feature) {
            $tier = $feature['tier'];

            // A feature no plan includes at all has no tier and no home on this
            // screen. It is a catalogue row somebody is still drafting, and
            // drawing it would offer a community something nobody sells.
            if ($tier === null) {
                continue;
            }

            $override = $overrides[$feature['key']] ?? null;
            $available = $feature['is_core'] || $this->planIncludes($planKey, $feature['key']);

            $grouped[$tier][] = $feature['key'] === 'estate_payroll'
                ? $this->payrollRoutingRow($feature, $setting, $available)
                : $this->featureRow(
                    $feature,
                    $override,
                    $available,
                    $feature['kind'] === 'tier_value' ? $this->tierValueFor($planKey, $feature['key']) : null,
                );

            /*
             * Board 23 draws payroll as TWO rows, and only one of them is a
             * catalogue feature. The second is the guards', it is Gemini's, and
             * it is drawn immediately beneath — so it is emitted here rather
             * than left to a template to remember.
             */
            if ($feature['key'] === 'estate_payroll') {
                $grouped[$tier][] = $this->guardPayrollRow($setting);
            }
        }

        $groups = [];

        foreach (self::TIER_GROUPS as $tier => $heading) {
            if (! isset($grouped[$tier])) {
                continue;
            }

            $groups[] = ['key' => $tier, 'heading' => $heading, 'rows' => $grouped[$tier]];
        }

        $groups[] = [
            'key' => 'held_back',
            'heading' => 'Held back in this release',
            'rows' => $this->heldBackRows($setting),
        ];

        return [
            'plan' => [
                'key' => $planKey,
                'name' => $subscription === null ? null : $subscription->plan->name,

                // "Phoenix Park Village 1 is on the Premium plan" — the estate's
                // name and the plan's, joined here so the page never has to.
                'banner' => $subscription === null
                    ? 'This estate has no subscription on record.'
                    : sprintf('%s is on the %s plan', (string) tenant()?->name, $subscription->plan->name),
                'note' => 'Every module below is included. Downgrading greys out features but never deletes their data.',
            ],
            'groups' => $groups,
            'audit_note' => self::FEATURES_AUDIT_NOTE,
        ];
    }

    /**
     * Switch one feature on or off for this estate.
     *
     * FOUR REFUSALS, AND NONE OF THEM IS VALIDATION. A core feature is not
     * optional at any tier; a feature the plan does not include cannot be
     * switched on by the community that is not paying for it; a feature the
     * catalogue does not offer is not a feature; and a change with no stated
     * reason is one nobody can question a year from now. Each throws
     * `DomainException` naming the rule, because the person who hits it has to
     * be told what to do instead.
     */
    public function setFeature(string $key, bool $enabled, string $reason, User $by, string $tenantKey = ''): EstateFeature
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'A feature change needs a stated reason. Turning a module off changes what several hundred '.
                'households can do, and an unexplained change to that is exactly what an audit has to be able '.
                'to question.'
            );
        }

        $feature = null;

        foreach ($this->catalogue() as $row) {
            if ($row['key'] === $key) {
                $feature = $row;

                break;
            }
        }

        if ($feature === null) {
            throw new DomainException(
                "No feature [{$key}] in the platform catalogue. A switch for something that is not on offer would ".
                'record a decision about nothing.'
            );
        }

        if ($feature['is_core']) {
            throw new DomainException(sprintf(
                '"%s" is a core feature and is on at every tier. The panic button, visitor passes and amenity '.
                'booking are not behind a price tier and are not an estate setting.',
                $feature['label'],
            ));
        }

        if ($feature['kind'] === 'tier_value') {
            throw new DomainException(sprintf(
                '"%s" is not a switch. Every tier has it and the tiers differ in how much of it — how much this '.
                'estate gets is what its plan grants, so there is nothing here to turn off.',
                $feature['label'],
            ));
        }

        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantKey)->first();

        if ($enabled && ! $this->planIncludes($subscription?->plan->key, $key)) {
            throw new DomainException(sprintf(
                '"%s" is not included in this estate\'s plan, so it cannot be switched on here. Adding it is a '.
                'commercial change and is made by Gemini Security against the subscription.',
                $feature['label'],
            ));
        }

        $existing = EstateFeature::query()->where('feature_key', $key)->first();
        $was = $existing?->enabled;

        $record = EstateFeature::updateOrCreate(
            ['feature_key' => $key],
            [
                'enabled' => $enabled,
                'changed_by' => $by->getKey(),
                'changed_by_name' => $by->name,
                'reason' => $reason,
            ],
        );

        $this->audit(
            action: 'estate.settings.feature_toggled',
            by: $by,
            entityType: 'estate_feature',
            entityId: $key,
            before: ['enabled' => $was],
            after: ['enabled' => $enabled, 'reason' => $reason],
        );

        return $record;
    }

    /**
     * Choose which system runs the estate's own staff payroll.
     *
     * THE GUARDS' ROUTING IS NOT REACHABLE THROUGH THIS. Guards are Gemini
     * Security Limited's employees, paid by Gemini, filed under Gemini's TRN.
     * An estate that routed guard pay in-house would be claiming to employ
     * people it does not employ and posting a payroll liability it has no
     * obligation to settle — so the key is refused by name rather than being
     * left to a template not to offer it.
     */
    public function setRouting(string $key, string $option, string $reason, User $by): EstateSetting
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A routing change needs a stated reason.');
        }

        if ($key === 'security_payroll') {
            throw new DomainException(
                'Security guard payroll is always run in the Gemini Console. Guards are Gemini Security Limited\'s '.
                'employees and their statutory deductions are filed under Gemini\'s TRN — an estate cannot route '.
                'the pay of people it does not employ.'
            );
        }

        if ($key !== 'estate_payroll') {
            throw new DomainException("No payroll routing [{$key}]. The estate routes its own staff payroll and nothing else.");
        }

        if (! in_array($option, [EstateSetting::IN_HOUSE, EstateSetting::GEMINI_MANAGED], true)) {
            throw new DomainException(
                "No routing option [{$option}]. Staff payroll is run in-house or managed in the Gemini Console."
            );
        }

        $setting = EstateSetting::current();
        $was = $setting->staff_payroll_routing;

        $setting->fill(['staff_payroll_routing' => $option])->save();

        $this->audit(
            action: 'estate.settings.payroll_routing_changed',
            by: $by,
            entityType: 'estate_setting',
            entityId: (string) $setting->getKey(),
            before: ['staff_payroll_routing' => $was],
            after: ['staff_payroll_routing' => $option, 'reason' => $reason],
        );

        return $setting;
    }

    /* ------------------------------------------------------------------ */
    /* board 24 — the role access matrix, read from the permission model */
    /* ------------------------------------------------------------------ */

    /**
     * The matrix as this console actually enforces it.
     *
     * THIRTEEN MODULES AND SEVEN ROLES, where the board draws ten and six. The
     * three extra modules are the Ruling 1 split (D-010/D-014) — `payments`,
     * `vendor_costs` and `maintenance_budget` were separated out so a Property
     * Manager could see the costs they commission without ever seeing a
     * resident's financial position — and the seventh role is the Community
     * Super Admin, which board 24's own audit note says it deliberately omits.
     *
     * Showing them is the more honest screen. A matrix that hid three modules
     * and a role would be a permission screen that does not describe the
     * permissions, which is the one thing a permission screen must not be.
     *
     * NOT EDITABLE, AND THE PAYLOAD SAYS SO RATHER THAN LEAVING IT IMPLIED.
     * `can_edit` is `estate.settings.approve`, which no estate role holds — see
     * this class's own docblock for why that is three refusals rather than one.
     *
     * @return array<string, mixed>
     */
    public function matrixBoard(User $viewer): array
    {
        $roles = Role::query()
            ->where('console', Console::Estate->value)
            ->orderBy('sort')
            ->get();

        $modules = Module::query()
            ->where('console', Console::Estate->value)
            ->orderBy('sort')
            ->get();

        /** @var array<int, array<int, RoleModuleAccess>> $cells role id => module id => cell */
        $cells = [];

        foreach (RoleModuleAccess::query()->whereIn('role_id', $roles->modelKeys())->get() as $cell) {
            $cells[$cell->role_id][$cell->module_id] = $cell;
        }

        $rows = [];

        foreach ($modules as $module) {
            $line = [];

            foreach ($roles as $role) {
                $cell = $cells[$role->getKey()][$module->getKey()] ?? null;

                /*
                 * A missing cell is NO ACCESS, and it is not a defect to
                 * repair on read. `role_module_access` is written by the
                 * matrix seeder for every pair it knows about; a pair it has
                 * not written is a role that has never been granted the module,
                 * and the safe reading of "nobody has said" is "no".
                 */
                /*
                 * `->` and not `?->`, on the left of `??`. The null coalescing
                 * operator already suppresses the whole left-hand expression
                 * when any part of it is null, so a nullsafe here would be
                 * belt-and-braces that reads as though the two operators did
                 * different jobs. `pillLabel()` below keeps its `?->` because
                 * that rule covers property reads and not method calls.
                 */
                $level = $cell->level ?? AccessLevel::None;
                $canApprove = $cell->can_approve ?? false;

                $line[] = [
                    'role' => $role->name,
                    'level' => $level->value,
                    'label' => $level->label(),
                    'can_approve' => $canApprove,

                    // "Full · Approver" as one string, for a page that draws the
                    // pill without the nested tag.
                    'pill' => $cell?->pillLabel() ?? $level->label(),
                    'scope' => $cell?->scope->value ?? 'all',
                ];
            }

            $rows[] = [
                'key' => $module->key,
                'label' => $module->label,
                'section' => $module->section,

                /*
                 * Which rows Ruling 1 locks. Carried so the screen can say WHY
                 * the Property Manager's cell reads "—" where board 24 drew
                 * "View": it is a platform invariant and not this estate's
                 * preference, and a committee reading the matrix is exactly who
                 * needs to be told the difference.
                 */
                'locked_financial' => $module->is_locked_financial,
                'cells' => $line,
            ];
        }

        return [
            'legend' => self::LEGEND,
            'roles' => $roles->map(fn (Role $role): array => [
                'name' => $role->name,
                'label' => (string) ($role->label ?? $role->name),
                'scope' => $role->scope_default->value,
                'scope_label' => $role->scope_default->label(),

                // The role board 24 does not draw, flagged rather than dropped.
                'is_super_admin' => $role->name === Role::COMMUNITY_SUPER_ADMIN,
            ])->values()->all(),
            'rows' => $rows,
            'audit_note' => self::MATRIX_AUDIT_NOTE,

            'can_edit' => $viewer->can('estate.settings.approve'),
            'read_only_reason' => self::MATRIX_READ_ONLY,

            /*
             * The Property Manager lock, named on the screen. Board 24 draws
             * those two cells as View; D-010 refuses them, the seeder throws
             * rather than granting them, and `EstateNavigationTest` proves the
             * sidebar honours it. Saying so here is what stops somebody reading
             * the difference as a bug.
             */
            'invariant_note' => 'Dues & ledger, Payments, Accounting and Payroll & HR are closed to the Property '.
                'Manager by platform invariant, not by this estate\'s choice: whoever commissions work must never '.
                'be able to pay for it, nor see a resident\'s financial position.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 30 — notification defaults */
    /* ------------------------------------------------------------------ */

    /**
     * Board 30's eighteen switches, grouped exactly as the board draws them.
     *
     * ONE ROW PER (EVENT, CHANNEL), READ BACK INTO A GRID. The six events are
     * `NOTIFICATION_EVENTS`, a constant; `notification_defaults` holds only
     * whether this estate's committee has each of the eighteen on. A pair the
     * table has no row for is not reachable from this screen — `SettingsSeeder`
     * writes all eighteen once, so the only way a row is missing here is a
     * fresh estate that has not been seeded yet, and it is treated as OFF
     * rather than guessed at: a switch this screen cannot prove is on must
     * not be drawn on.
     *
     * @return array<string, mixed>
     */
    public function notificationsBoard(): array
    {
        /** @var array<string, array<string, bool>> $stored [event_key][channel] => enabled */
        $stored = [];

        foreach (NotificationDefault::query()->get() as $row) {
            $stored[$row->event_key][$row->channel] = $row->enabled;
        }

        $byGroup = [];

        foreach (self::NOTIFICATION_EVENTS as $event) {
            $byGroup[$event['group']]['sort'] = $event['group_sort'];
            $byGroup[$event['group']]['rows'][] = [
                'key' => $event['key'],
                'label' => $event['label'],
                'description' => $event['description'],
                'channels' => array_map(
                    fn (string $channel): array => [
                        'key' => $channel,
                        'label' => ucfirst($channel),
                        'enabled' => $stored[$event['key']][$channel] ?? false,
                    ],
                    NotificationDefault::CHANNELS,
                ),
            ];
        }

        uasort($byGroup, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $groups = [];

        foreach ($byGroup as $heading => $group) {
            $groups[] = ['heading' => $heading, 'rows' => $group['rows']];
        }

        return ['groups' => $groups];
    }

    /**
     * Save all eighteen of board 30's switches in one submit.
     *
     * ONE SUBMIT, ONE AUDIT ENTRY, exactly as the board draws it — a single
     * topbar "Save changes" rather than Features' per-row arm-and-confirm,
     * because nothing here disables a module or changes what a household may
     * do; it decides who is emailed, texted or pushed about something that
     * already happened. That is a contact preference in the same register as
     * the estate's own enquiries mailbox on board 21, not a `configure`-level
     * act, so this is gated the same way `updateProfile()` is.
     *
     * THE ALLOWLIST IS `NOTIFICATION_EVENTS`' OWN KEYS, not whatever arrives in
     * the request. An unrecognised event key is dropped rather than stored,
     * the same refusal `setFeature()` gives a feature the catalogue does not
     * offer — a switch for an event this product does not send would record a
     * decision about nothing.
     *
     * @param  array<string, array<string, bool>>  $input  [event_key][channel] => enabled
     */
    public function saveNotifications(array $input, User $by): void
    {
        $before = $this->notificationSnapshot();

        foreach (self::NOTIFICATION_EVENTS as $event) {
            $key = $event['key'];

            foreach (NotificationDefault::CHANNELS as $channel) {
                if (! isset($input[$key][$channel])) {
                    continue;
                }

                NotificationDefault::updateOrCreate(
                    ['event_key' => $key, 'channel' => $channel],
                    ['enabled' => (bool) $input[$key][$channel]],
                );
            }
        }

        $after = $this->notificationSnapshot();

        // A change nobody made writes no entry. Pressing Save on a form
        // nothing was typed into is not an event an audit has to explain.
        if ($before === $after) {
            return;
        }

        $this->audit(
            action: 'estate.settings.notifications_updated',
            by: $by,
            entityType: 'notification_default',
            entityId: 'grid',
            before: $before,
            after: $after,
        );
    }

    /**
     * Every stored (event, channel) answer, flattened for the audit log and
     * for the before/after comparison `saveNotifications()` uses to decide
     * whether anything actually changed.
     *
     * @return array<string, bool>
     */
    private function notificationSnapshot(): array
    {
        return NotificationDefault::query()
            ->get()
            ->mapWithKeys(fn (NotificationDefault $row): array => ["{$row->event_key}.{$row->channel}" => $row->enabled])
            ->sortKeys()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* board 33 — data & privacy */
    /* ------------------------------------------------------------------ */

    /**
     * Board 33's four fields, in its own three sections — and every one of
     * them is a POLICY STATEMENT, not a form.
     *
     * NO `estate_privacy_setting` TABLE EXISTS, AND NONE IS ADDED. The board
     * draws four `.m-input span` values and a "Save changes" button with no
     * editable input anywhere on the screen — D-045's own lesson runs in the
     * other direction here: a control that is not drawn live must not be BUILT
     * live, whatever a generic settings-page instinct suggests. Two of the
     * four are fixed copy this screen carries, because retention and sharing
     * with Gemini for the security grant are legal and contractual facts
     * nobody on this console is authorised to change from a form; a resident's
     * request channel is the same kind of fact, naming the Secretary as the
     * role. Making any of the three "editable" would be inventing an edit path
     * for a policy nobody has been asked to let an estate set.
     *
     * ONE OF THE FOUR IS DERIVED, AND IT DISAGREES WITH THE BOARD (D-044). The
     * board's own text reads "President, Property Manager, Secretary" for who
     * may export the resident list. The permission matrix says otherwise:
     * `export` is one of the seven verbs and only `AccessLevel::Full` grants
     * it (`AccessLevel::View`, which is all the President holds on Residents,
     * does not). Full-on-Residents belongs to the Community Super Admin, the
     * Secretary and the Property Manager (D-053's `A · V · V · F · A · V · E`)
     * — the President is not among them, and the Community Super Admin, which
     * board 24's own audit note admits it omits, is. Printing the board's own
     * sentence here would tell a committee a role could export a list of
     * every household in the estate when the platform would 403 that role the
     * moment it tried. THE MODEL WINS, exactly as it does on board 24, and the
     * discrepancy is recorded rather than reconciled — see DECISIONS.md.
     *
     * @return array<string, mixed>
     */
    public function privacyBoard(): array
    {
        return [
            'groups' => [
                [
                    'title' => 'Resident data',
                    'fields' => [
                        [
                            'key' => 'retention',
                            'label' => 'Data retention after a resident moves out',
                            'value' => self::PRIVACY_RETENTION,
                        ],
                        [
                            'key' => 'export_roles',
                            'label' => 'Who can export resident lists',
                            'value' => $this->residentExportRoles(),
                        ],
                    ],
                ],
                [
                    'title' => 'Sharing with Gemini Security',
                    'fields' => [
                        [
                            'key' => 'security_grant',
                            'label' => 'Data shared for the security service grant',
                            'value' => self::PRIVACY_SECURITY_GRANT,
                        ],
                    ],
                ],
                [
                    'title' => 'Resident rights',
                    'fields' => [
                        [
                            'key' => 'access_requests',
                            'label' => 'Data access requests',
                            'value' => self::PRIVACY_ACCESS_REQUESTS,
                        ],
                    ],
                ],
            ],

            // Always the reason, never a live save — see the class docblock.
            // Not gated on the viewer's own permission, because there is
            // nothing behind this button for even the Community Super Admin
            // to unlock: the refusal is the same for every viewer of every
            // level.
            'save_disabled_reason' => self::PRIVACY_NO_EDIT,
        ];
    }

    /** Board 33's first field, verbatim. */
    private const PRIVACY_RETENTION = '7 years — matches statutory record-keeping requirements';

    /** Board 33's third field, verbatim. */
    private const PRIVACY_SECURITY_GRANT = 'Unit occupancy status, registered vehicles — read-only, revocable';

    /** Board 33's fourth field, verbatim. */
    private const PRIVACY_ACCESS_REQUESTS = 'Residents can request their own data via a notice to the Secretary';

    /** Why the button the board draws saves nothing, for anybody who presses it. */
    public const PRIVACY_NO_EDIT = 'Every field on this screen is a stated policy — a retention period, who the '.
        'platform actually authorises to export a resident list, what is shared under the security grant, and how '.
        'a resident requests their own data. None of the four is a setting this console offers a form for, so '.
        'there is nothing here for Save changes to write.';

    /**
     * Who may actually export the resident register, read from the matrix
     * rather than transcribed from the board — see this method's caller for
     * why the two disagree.
     *
     * "Community Super Admin, Secretary, Property Manager", in the matrix's
     * own role order rather than the board's, because that order is the one
     * fact about this list the model gets to assert.
     */
    private function residentExportRoles(): string
    {
        $labels = Role::query()
            ->where('console', Console::Estate->value)
            ->whereHas(
                'moduleAccess',
                fn ($query) => $query->where('level', AccessLevel::Full->value)
                    ->whereHas('module', fn ($module) => $module->where('key', 'residents')),
            )
            ->orderBy('sort')
            ->get()
            ->map(fn (Role $role): string => (string) ($role->label ?? $role->name));

        return $labels->implode(', ');
    }

    /* ------------------------------------------------------------------ */
    /* board 40 — billing & subscription */
    /* ------------------------------------------------------------------ */

    /**
     * The estate's own subscription, hero card and invoice history —
     * READ FROM THE CENTRAL RECORD AND NEVER COPIED INTO THIS DATABASE.
     *
     * THIS IS THE ONE SCREEN IN THIS MODULE THAT DOES NOT BELONG TO THIS
     * ESTATE'S DATABASE AT ALL. `App\Models\Subscription`, `Plan`, `Invoice`
     * and `InvoiceLine` all carry spatie's sibling trick — `CentralConnection`
     * — for the same reason `Role` and `Permission` do (D-012): a figure two
     * databases could each hold a copy of is a figure free to disagree with
     * itself, and the one Gemini bills against has to be the one a treasurer
     * reading this screen sees. So this method opens no tenant table, writes
     * nothing anywhere, and reads `tenant_id = $tenantKey` off the same rows
     * `App\Http\Controllers\Gemini\BillingController` and `BillingOverview`
     * already read for the platform's own billing screens. Two consoles, one
     * ledger, never two.
     *
     * THE AMOUNT SHOWN EXCLUDES THE GUARD LINE, AND THAT IS THE WHOLE POINT
     * OF THIS SCREEN'S SECOND INFO PANEL. `BillingSeeder` posts Phoenix Park's
     * invoice as TWO lines — the software subscription and a "Security
     * Provider add-on" for the guards Gemini staffs the gate with — because
     * both are billed on one invoice centrally. Board 40 draws that boundary
     * in its own words: guard staffing "is priced separately from this
     * software subscription... That relationship and its invoices live in
     * Accounting, not here." Printing the invoice's posted TOTAL here would
     * show a treasurer $171,000 on a screen captioned "$340 per unit x 450
     * units = $153,000" two inches above it — an arithmetic contradiction on
     * one page. So every figure below is summed from LINES whose description
     * names the subscription, never from the invoice's own posted
     * `total_minor`, which is both lines together. The guard line is not
     * hidden; it is simply not this screen's bill. `App\Services\Estate\
     * Payables` is where an estate's own vendor bills live, and Gemini's own
     * guard-services invoice is Gemini's, read in the Gemini Console — this
     * estate does not receive it and this screen does not owe an accounting
     * for money it was never charged.
     *
     * INVOICE NUMBERS ARE DERIVED, NOT THE STORED `reference`. The central
     * table's reference carries an internal prefix
     * (`{estate}-INV-{YYYYMM}`, e.g. "PH-INV-202609") that has never been
     * shown to an estate — board 40's own format is "INV-2026-09", built here
     * from the invoice's period the same way the design brief states it:
     * "format INV-YYYY-MM, so derivable from period".
     *
     * @return array<string, mixed>
     */
    public function billingBoard(string $tenantKey): array
    {
        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantKey)->first();

        if ($subscription === null) {
            return ['subscription' => null, 'invoices' => []];
        }

        $plan = $subscription->plan;
        $monthlyMinor = $subscription->unit_count * $plan->price_per_unit_minor;

        $invoices = Invoice::query()
            ->with('lines')
            ->where('tenant_id', $tenantKey)
            ->orderByDesc('period_start')
            ->limit(3)
            ->get()
            ->map(fn (Invoice $invoice): array => $this->billingInvoiceRow($invoice))
            ->all();

        return [
            'subscription' => [
                'plan_name' => (string) $plan->name,
                'unit_count' => $subscription->unit_count,
                'billing_cadence' => 'Billed monthly in advance',
                'price_per_unit' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, $plan->currency),
                'monthly_total' => MoneyFormatter::whole($monthlyMinor, $plan->currency),
                'next_invoice' => $subscription->renews_on?->format('M j') ?? '—',
                'contract_renewal' => $subscription->contract_renewal_on?->format('M Y') ?? '—',
                'included_blurb' => self::PLAN_INCLUDED_BLURB,
                'security_services_note' => self::SECURITY_SERVICES_NOTE,
            ],
            'invoices' => $invoices,
        ];
    }

    /** Board 40's first info panel, verbatim. */
    private const PLAN_INCLUDED_BLURB = 'Guard App integration, e-Voting & elections, resident-gated meetings, '.
        "Accounting core, Payroll & HR for the estate's own staff, AI drafting assistant, and full custom ".
        'branding. See Feature toggles for the complete list.';

    /**
     * Board 40's second info panel, verbatim — the boundary this whole method
     * exists to honour.
     */
    private const SECURITY_SERVICES_NOTE = 'Gemini Security Limited provides guard staffing under its own labour '.
        'services agreement, priced separately from this software subscription. That relationship and its '.
        'invoices live in Accounting, not here.';

    /**
     * One row of board 40's three-invoice table.
     *
     * @return array<string, mixed>
     */
    private function billingInvoiceRow(Invoice $invoice): array
    {
        // Every line whose description names the subscription — "Premium
        // subscription", "Standard subscription" — and never the guard
        // add-on line beside it. See this class's billingBoard() docblock.
        $subscriptionMinor = $invoice->lines
            ->filter(fn ($line): bool => str_contains(mb_strtolower((string) $line->description), 'subscription'))
            ->sum('total_minor');

        return [
            // "INV-2026-09" — derived from the period, never the stored
            // reference, which carries an estate prefix nobody outside
            // Gemini has seen.
            'number' => 'INV-'.$invoice->period_start->format('Y-m'),
            'period' => $invoice->period_start->format('F Y'),
            'amount' => MoneyFormatter::whole((int) $subscriptionMinor, $invoice->currency),
            'status' => $invoice->status,
            'status_label' => $invoice->status === 'paid' && $invoice->paid_on !== null
                ? 'Paid '.$invoice->paid_on->format('M j')
                : ucfirst($invoice->status),

            // Nothing behind it yet — see SettingsController for the reason,
            // stated once rather than assembled per row.
            'view_href' => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * The central feature catalogue, with each feature's tier computed.
     *
     * @return list<array{key: string, label: string, sub_label: string|null, kind: string, is_core: bool, tier: string|null}>
     */
    private function catalogue(): array
    {
        $plans = DB::connection('mysql')->table('plans')->orderBy('sort')->get();
        $included = DB::connection('mysql')->table('plan_features')->where('included', true)->get();

        /** @var array<int, string> $planKeys plan id => key, in ascending price order */
        $planKeys = [];
        /** @var array<int, int> $planOrder plan id => sort */
        $planOrder = [];

        foreach ($plans as $plan) {
            $planKeys[(int) $plan->id] = (string) $plan->key;
            $planOrder[(int) $plan->id] = (int) $plan->sort;
        }

        /** @var array<int, array{tier: string, sort: int}> $lowest feature id => cheapest plan that includes it */
        $lowest = [];

        /** @var array<int, array{tier: string, sort: int}> $highest feature id => dearest plan that includes it */
        $highest = [];

        foreach ($included as $row) {
            $featureId = (int) $row->package_feature_id;
            $planId = (int) $row->plan_id;

            if (! isset($planKeys[$planId])) {
                continue;
            }

            $sort = $planOrder[$planId];

            // The cheapest plan that includes it is the tier badge, which is
            // what "Standard+" means: this and everything above it.
            if (! isset($lowest[$featureId]) || $lowest[$featureId]['sort'] > $sort) {
                $lowest[$featureId] = ['tier' => $planKeys[$planId], 'sort' => $sort];
            }

            if (! isset($highest[$featureId]) || $highest[$featureId]['sort'] < $sort) {
                $highest[$featureId] = ['tier' => $planKeys[$planId], 'sort' => $sort];
            }
        }

        $catalogue = [];

        foreach (DB::connection('mysql')->table('package_features')->orderBy('sort')->get() as $feature) {
            $id = (int) $feature->id;
            $isCore = (bool) $feature->is_core;
            $kind = (string) $feature->kind;

            /*
             * WHERE A ROW IS DRAWN, AND WHY THE TWO KINDS DISAGREE.
             *
             * A toggle belongs to the CHEAPEST tier that includes it: "Standard+"
             * means this tier and every one above, and drawing it under Premium
             * would tell a Standard estate it cannot have something it has.
             *
             * A `tier_value` feature belongs to the DEAREST. Every tier has
             * custom branding — the grid marks it included in all three — so the
             * cheapest tier is meaningless as a badge and would put it under
             * "Core, every tier" beside the panic button. What differs is HOW
             * MUCH, and the tier that grants all of it is the one the row is
             * about. Board 23 draws it under "Premium only" for exactly that
             * reason.
             *
             * A core feature is on at every tier by definition, whatever the
             * grid happens to say.
             */
            $tier = match (true) {
                $isCore => 'essential',
                $kind === 'tier_value' => $highest[$id]['tier'] ?? null,
                default => $lowest[$id]['tier'] ?? null,
            };

            $catalogue[] = [
                'key' => (string) $feature->key,
                'label' => (string) $feature->label,
                'sub_label' => $feature->sub_label === null ? null : (string) $feature->sub_label,
                'kind' => $kind,
                'is_core' => $isCore,
                'tier' => $tier,
            ];
        }

        return $catalogue;
    }

    /**
     * How much of a `tier_value` feature THIS estate's plan grants.
     *
     * "Logo only", "Logo + hero", "Full theme" — read for the estate's own
     * plan, never for the catalogue's cheapest. Board 23 fills all three
     * branding dots because Phoenix Park is on Premium; a Standard estate fills
     * two, and reading the same figure for both would tell one of them
     * something untrue about what it is paying for.
     */
    private function tierValueFor(?string $planKey, string $featureKey): ?string
    {
        if ($planKey === null) {
            return null;
        }

        $value = DB::connection('mysql')
            ->table('plan_features')
            ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
            ->join('package_features', 'package_features.id', '=', 'plan_features.package_feature_id')
            ->where('plans.key', $planKey)
            ->where('package_features.key', $featureKey)
            ->value('plan_features.tier_value');

        return $value === null ? null : (string) $value;
    }

    /** Does the estate's plan include this feature? */
    private function planIncludes(?string $planKey, string $featureKey): bool
    {
        if ($planKey === null) {
            return false;
        }

        return DB::connection('mysql')
            ->table('plan_features')
            ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
            ->join('package_features', 'package_features.id', '=', 'plan_features.package_feature_id')
            ->where('plans.key', $planKey)
            ->where('package_features.key', $featureKey)
            ->where('plan_features.included', true)
            ->exists();
    }

    /**
     * This estate's own decisions, keyed by feature.
     *
     * @return array<string, EstateFeature>
     */
    private function overrides(): array
    {
        $overrides = [];

        foreach (EstateFeature::query()->get() as $override) {
            $overrides[$override->feature_key] = $override;
        }

        return $overrides;
    }

    /**
     * One switch row.
     *
     * @param  array{key: string, label: string, sub_label: string|null, kind: string, is_core: bool, tier: string|null}  $feature
     * @return array<string, mixed>
     */
    private function featureRow(array $feature, ?EstateFeature $override, bool $available, ?string $tierValue): array
    {
        $enabled = $feature['is_core']
            ? true
            : ($override->enabled ?? $available);

        return [
            'key' => $feature['key'],
            'label' => $feature['label'],
            'description' => $feature['is_core']
                ? self::CORE_COPY
                : (self::FEATURE_COPY[$feature['key']] ?? (string) $feature['sub_label']),
            'tier' => $feature['tier'],
            'tier_label' => self::TIER_LABELS[(string) $feature['tier']] ?? '',

            // A `tier_value` feature is not a switch: no tier is without custom
            // branding, and the tiers differ in how much of it. Rendering that
            // as a tick would sell Essential a feature it does not have.
            'control' => $feature['kind'] === 'tier_value' ? 'scale' : 'switch',
            'enabled' => $enabled,

            /*
             * A scale is locked for the same reason a core row is, and for a
             * different cause: there is nothing here to switch. How much
             * branding an estate gets is what its tier grants, so the control
             * reports a commercial fact rather than offering a choice.
             */
            'locked' => $feature['is_core'] || $feature['kind'] === 'tier_value',
            'available' => $available,

            'scale' => $feature['kind'] === 'tier_value'
                ? $this->brandingScale($tierValue)
                : null,

            // Who decided, and why — the caption under a switch somebody moved.
            // Null where the plan's own answer still stands, which is not the
            // same as nobody having decided anything for a bad reason.
            'provenance' => $override?->provenance(),
            'reason' => $override?->reason,
            'blocked_reason' => $available
                ? null
                : 'Not included in this estate\'s plan. Adding it is a commercial change made by Gemini Security.',
        ];
    }

    /**
     * Board 23's "Payroll & HR routing" — a segmented control, not a switch.
     *
     * @param  array{key: string, label: string, sub_label: string|null, kind: string, is_core: bool, tier: string|null}  $feature
     * @return array<string, mixed>
     */
    private function payrollRoutingRow(array $feature, EstateSetting $setting, bool $available): array
    {
        return [
            'key' => 'estate_payroll',
            'label' => 'Payroll & HR routing',

            // "Staff payroll: in-house · Security payroll: Gemini-managed" —
            // computed from the two stored routings, so the line cannot say one
            // thing while the controls beneath it say another. "In-house" falls
            // to lower case mid-sentence and "Gemini-managed" does not, because
            // one is a description and the other is a company's name.
            'description' => sprintf(
                'Staff payroll: %s · Security payroll: %s',
                $setting->routingSentenceLabel($setting->staff_payroll_routing),
                $setting->routingSentenceLabel($setting->security_payroll_routing),
            ),
            'tier' => $feature['tier'],
            'tier_label' => self::TIER_LABELS[(string) $feature['tier']] ?? '',
            'control' => 'segmented',
            'enabled' => $available,
            'locked' => false,
            'available' => $available,
            'options' => $this->routingOptions($setting, $setting->staff_payroll_routing),
            'selected' => $setting->staff_payroll_routing,
            'caption' => $setting->routingCaption($setting->staff_payroll_routing),

            /*
             * D-021, stated where it bites. An estate payroll run may be
             * CALCULATED on the seeded 2026-04-DRAFT statutory rates and may not
             * be APPROVED, so a committee switching this to in-house needs to
             * know what they will and will not be able to do with it.
             */
            'note' => 'Statutory rates are seeded as 2026-04-DRAFT and unverified: a run may be calculated so the '.
                'figures can be checked, and cannot be approved until the rates are confirmed.',
            'blocked_reason' => $available
                ? null
                : 'Not included in this estate\'s plan. Adding it is a commercial change made by Gemini Security.',
        ];
    }

    /**
     * Board 23's second payroll row — the guards', and locked.
     *
     * @return array<string, mixed>
     */
    private function guardPayrollRow(EstateSetting $setting): array
    {
        return [
            'key' => 'security_payroll',
            'label' => 'Security guard payroll routing',
            'description' => "Guards are Gemini Security Limited's employees",
            'tier' => 'premium',
            'tier_label' => self::TIER_LABELS['premium'],
            'control' => 'segmented',
            'enabled' => true,
            'locked' => true,
            'available' => true,
            'options' => $this->routingOptions($setting, $setting->security_payroll_routing),
            'selected' => $setting->security_payroll_routing,
            'caption' => $setting->routingCaption($setting->security_payroll_routing),
            'note' => null,
            'blocked_reason' => 'Guard pay never posts to this estate\'s payroll ledger. Their statutory deductions '.
                'are filed under Gemini Security Limited\'s TRN, so an estate cannot route the pay of people it '.
                'does not employ.',
        ];
    }

    /**
     * The two options both routing rows offer, with the chosen one marked.
     *
     * @return list<array{value: string, label: string, active: bool}>
     */
    private function routingOptions(EstateSetting $setting, string $selected): array
    {
        $options = [];

        foreach ([EstateSetting::IN_HOUSE, EstateSetting::GEMINI_MANAGED] as $value) {
            $options[] = [
                'value' => $value,
                'label' => $setting->routingLabel($value),
                'active' => $value === $selected,
            ];
        }

        return $options;
    }

    /**
     * Board 23's three branding dots — L, H, C — filled to the tier.
     *
     * DERIVED FROM THE TIER VALUE, not stored per dot. "Full theme" fills all
     * three, "Logo + hero" fills two and "Logo only" fills one, which is the
     * same fact the package grid already holds and is why no `branding_option`
     * table exists to disagree with it.
     *
     * @return list<array{code: string, label: string, filled: bool}>
     */
    private function brandingScale(?string $tierValue): array
    {
        $levels = match ($tierValue) {
            'Full theme' => 3,
            'Logo + hero' => 2,
            'Logo only' => 1,
            default => 0,
        };

        $dots = [];

        foreach ([['L', 'Logo'], ['H', 'Hero image'], ['C', 'Colour theme']] as $i => [$code, $label]) {
            $dots[] = ['code' => $code, 'label' => $label, 'filled' => $i < $levels];
        }

        return $dots;
    }

    /**
     * The three this release holds back, each with the ruling that holds it.
     *
     * NONE OF THESE IS A CATALOGUE FEATURE, and none was added to the central
     * catalogue to make this group possible: `package_features` is board 43's
     * package builder, and putting a legally-blocked feature into it would
     * offer it for sale. They are estate flags with rulings behind them, drawn
     * here so the screen states a decision rather than silently omitting it —
     * which is the difference between a decision and an oversight.
     *
     * @return list<array<string, mixed>>
     */
    private function heldBackRows(EstateSetting $setting): array
    {
        return [
            [
                'key' => 'biometric_consent',
                'label' => 'Biometric check-in consent',
                'description' => 'Consent copy is in legal review and marked draft — the feature does not run',
                'tier' => null,
                'tier_label' => 'Held',
                'control' => 'switch',
                'enabled' => $setting->biometric_consent_enabled,
                'locked' => true,
                'available' => false,
                'blocked_reason' => 'Off until the consent copy clears legal review (D-022). No template, no '.
                    'landmark set and no image exists anywhere in this system, so what the flag governs is '.
                    'whether the feature runs at all — never what is retained.',
            ],
            [
                'key' => 'payment_gateway',
                'label' => 'Card payments for dues',
                'description' => 'Dues are recorded manually; card capture sits behind a gateway adapter',
                'tier' => null,
                'tier_label' => 'Held',
                'control' => 'switch',
                'enabled' => $setting->payment_gateway_mode !== EstateSetting::PAYMENT_MANUAL,
                'locked' => true,
                'available' => false,
                'blocked_reason' => 'Manual recording day one, card capture behind the payment gateway interface '.
                    '(D-023), whose only implementation is a null one. Switched on today it would offer residents '.
                    'a card form that talks to nothing — and a resident who believes they have paid is a resident '.
                    'about to be restricted for arrears they thought were settled.',
            ],
            [
                'key' => 'geofencing',
                'label' => 'Geofenced clock-in',
                'description' => 'Deferred — the estate boundary needs surveyed coordinates',
                'tier' => null,
                'tier_label' => 'Held',
                'control' => 'switch',
                'enabled' => $setting->geofencing_enabled,
                'locked' => true,
                'available' => false,
                'blocked_reason' => 'Deferred (D-033). The boundary is the Guard App\'s clock-in check and needs a '.
                    'surveyed polygon rather than a nullable column nobody populates; nothing reads it before '.
                    'Phase 3.',
            ],
        ];
    }

    /**
     * One entry in the central, append-only audit log.
     *
     * CENTRAL AND NOT PER ESTATE, and that is not an accident of where the
     * table happens to be. Board 24 states that every permission change writes
     * to an immutable log, and a log inside the estate database would be a log
     * the estate's own administrator could ask for the deletion of. `audit_log`
     * is append-only twice over — a withheld grant and a trigger — and it
     * refuses an edit from platform staff as readily as from a committee.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        string $action,
        User $by,
        string $entityType,
        string $entityId,
        array $before,
        array $after,
    ): void {
        AuditEntry::create([
            'tenant_id' => tenant()?->getTenantKey(),
            'actor_id' => $by->getKey(),
            'actor_name' => $by->name,

            // The role, not the permission. "Who was allowed to do this" is
            // answered by the matrix on the day it was done, and the role is
            // the shortest true version of it.
            'actor_role' => $by->roles->pluck('name')->implode(', '),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /** "" for a blank field, stored as null so unset and empty are one answer. */
    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * "Patrice Campbell" -> "PC".
     *
     * PUNCTUATION IS STRIPPED FIRST, and that is not fussiness. Local
     * development creates accounts named "Treasurer (demo)", which take their
     * second initial from an opening bracket and render "T(" in a 32-pixel
     * avatar. First and LAST rather than first two, because a middle name is
     * not what anybody is known by.
     */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        $letters = [];

        foreach ($parts as $part) {
            $clean = (string) preg_replace('/[^\p{L}]/u', '', (string) $part);

            if ($clean !== '') {
                $letters[] = $clean;
            }
        }

        if ($letters === []) {
            return '?';
        }

        $first = mb_substr($letters[0], 0, 1);
        $last = count($letters) > 1 ? mb_substr($letters[count($letters) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Where a settings screen lives, in whichever shape this environment
     * serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path, string $tenantKey): string
    {
        return app()->isLocal() && $tenantKey !== ''
            ? '/estate/'.$tenantKey.$path
            : $path;
    }
}
