<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Messaging a client's committee — board screen super-admin-11.
 *
 * A security company tells a committee things that matter, and a conversation
 * nobody can produce afterwards is the one that turns into a dispute. So every
 * message is a record with a recipient, a sender, a timestamp and a category,
 * and none of it is deletable.
 *
 * RECIPIENTS ARE THE ESTATE'S OWN COMMITTEE, read from their assignments
 * rather than typed. An operator cannot address a message to somebody who does
 * not hold a role at that estate, which is both a correctness rule and a
 * privacy one: the picker cannot be used to discover who works elsewhere.
 *
 * THE CATEGORY IS NOT DECORATION. It routes: billing reaches the Treasurer's
 * inbox, compliance the Property Manager's. Sending everything to the
 * President is how a committee stops reading any of it.
 */
class ClientMessaging
{
    /**
     * Which categories exist, and who each one is for.
     *
     * The role a category defaults to is stated here rather than chosen by the
     * sender, so the same kind of message reaches the same desk at every
     * client. The sender may still override it — a general note to the
     * Treasurer is legitimate — but the default is the platform's, not theirs.
     *
     * @var array<string, array{label: string, role: string}>
     */
    private const CATEGORIES = [
        'general' => ['label' => 'General', 'role' => 'estate.president'],
        'billing' => ['label' => 'Billing', 'role' => 'estate.treasurer'],
        'compliance' => ['label' => 'Compliance', 'role' => 'estate.property_manager'],
    ];

    /**
     * Everything screen 11 draws.
     *
     * @return array<string, mixed>
     */
    public function forEstate(Tenant $estate): array
    {
        $contacts = $this->contacts($estate);

        return [
            'estate' => [
                'id' => (string) $estate->getTenantKey(),
                'name' => (string) $estate->name,
            ],
            'categories' => array_map(
                static fn (string $key, array $meta): array => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'role' => $meta['role'],
                ],
                array_keys(self::CATEGORIES),
                array_values(self::CATEGORIES),
            ),
            'contacts' => $contacts,
            'recent' => $this->recent($estate),
        ];
    }

    /**
     * Send it.
     *
     * @param  array<string, mixed>  $input
     */
    public function send(Tenant $estate, User $actor, array $input): void
    {
        $contacts = collect($this->contacts($estate));

        $data = Validator::make($input, [
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],

            // Only somebody who actually holds a role at this estate. A
            // recipient id from anywhere else is refused rather than silently
            // corrected, because addressing the wrong person is the failure.
            'recipient_id' => ['required', 'integer', Rule::in($contacts->pluck('id')->all())],

            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'recipient_id.required' => 'Choose who this goes to.',
            'recipient_id.in' => 'That person does not hold a role at this estate.',
            'subject.required' => 'A message needs a subject — it is what the recipient sees first.',
            'body.required' => 'There is nothing to send.',
        ])->validate();

        $recipient = $contacts->firstWhere('id', (int) $data['recipient_id']);

        DB::connection('mysql')->transaction(function () use ($estate, $actor, $data, $recipient): void {
            $id = (string) $estate->getTenantKey();

            DB::connection('mysql')->table('client_messages')->insert([
                'tenant_id' => $id,
                'recipient_id' => (int) $data['recipient_id'],
                'recipient_name' => $recipient['name'],
                'recipient_role' => $recipient['role'],
                'category' => (string) $data['category'],
                'subject' => (string) $data['subject'],
                'body' => (string) $data['body'],
                'sent_by' => $actor->getKey(),
                'sent_by_name' => $actor->name,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * Audited as well as recorded. The message table says what was
             * sent; the audit log says that a Gemini operator reached into a
             * client's console, which is a different fact and the one a
             * compliance review asks about.
             *
             * The BODY is deliberately not copied into the audit entry. It is
             * already stored once, and duplicating correspondence into an
             * append-only log that a wider set of roles can read would widen
             * who can see it.
             */
            AuditEntry::create([
                'tenant_id' => $id,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'actor_role' => $actor->roles->isEmpty() ? 'No role assigned' : $actor->roles->first()->label,
                'action' => 'client.message_sent',
                'entity_type' => 'client_message',
                'entity_id' => $id,
                'before' => null,
                'after' => [
                    'to' => $recipient['name'],
                    'category' => (string) $data['category'],
                    'subject' => (string) $data['subject'],
                ],
            ]);
        });
    }

    /**
     * The estate's committee, in the order the board lists them.
     *
     * @return list<array{id: int, name: string, role: string, initials: string}>
     */
    private function contacts(Tenant $estate): array
    {
        return DB::connection('mysql')
            ->table('estate_assignments')
            ->join('users', 'users.id', '=', 'estate_assignments.user_id')
            ->join('roles', 'roles.id', '=', 'estate_assignments.role_id')
            ->where('estate_assignments.tenant_id', $estate->getTenantKey())
            ->where('estate_assignments.is_active', true)
            ->orderBy('roles.sort')
            ->select('users.id', 'users.name', 'roles.label as role', 'roles.name as role_key')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'role' => (string) $row->role,
                'role_key' => (string) $row->role_key,
                'initials' => $this->initials((string) $row->name),
            ])
            ->all();
    }

    /**
     * What has already been said to this client, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(Tenant $estate, int $limit = 5): array
    {
        return DB::connection('mysql')
            ->table('client_messages')
            ->where('tenant_id', $estate->getTenantKey())
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'subject' => (string) $row->subject,
                'detail' => sprintf(
                    '%s · to %s · %s',
                    self::CATEGORIES[$row->category]['label'] ?? ucfirst((string) $row->category),
                    $row->recipient_name,
                    $row->read_at === null ? 'unread' : 'read',
                ),
            ])
            ->all();
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
