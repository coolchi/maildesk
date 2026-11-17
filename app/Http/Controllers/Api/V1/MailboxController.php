<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Services\Imap\MailboxProvisioner;
use App\Services\MailboxLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public API for email addresses (mailboxes). Mirrors the web Users page
 * (App\Http\Controllers\MailboxController): same validation, same login
 * user + mailbox records, same Stalwart provisioning.
 */
class MailboxController extends Controller
{
    public function __construct(
        public MailboxProvisioner $provisioner,
        public MailboxLimit $limits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $mailboxes = $organization->mailboxes()
            ->when($request->filled('domain'), fn ($q) => $q->where('email', 'like', '%@'.strtolower((string) $request->query('domain'))))
            ->orderBy('email')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        $mailboxes->setCollection($mailboxes->getCollection()->map(fn (Mailbox $mailbox) => self::present($mailbox)));
        $usage = $this->limits->usage($organization);

        return response()->json([
            ...$mailboxes->toArray(),
            'mailbox_limit' => $usage['limit'],
            'mailboxes_used' => $usage['used'],
        ]);
    }

    public function show(Request $request, int $mailbox): JsonResponse
    {
        return response()->json(self::present($this->find($request, $mailbox)));
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'email' => ['required_without_all:local,domain', 'nullable', 'string', 'max:320'],
            'local' => ['required_without:email', 'nullable', 'string', 'max:64'],
            'domain' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(['admin', 'developer', 'staff'])],
            'inbox' => ['boolean'],
            'transactional' => ['boolean'],
            'marketing' => ['boolean'],
            'send_limit' => ['nullable', 'integer', 'min:0'],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);

        if (! $this->limits->canCreate($organization)) {
            $usage = $this->limits->usage($organization);
            $message = $this->limits->message($organization);

            return response()->json([
                'message' => $message,
                'errors' => ['mailbox_limit' => [$message]],
                'mailbox_limit' => $usage['limit'],
                'mailboxes_used' => $usage['used'],
            ], 422);
        }

        if (filled($validated['email'] ?? null)) {
            $address = strtolower(trim((string) $validated['email']));
            if (substr_count($address, '@') !== 1) {
                throw ValidationException::withMessages(['email' => 'The email must look like name@your-domain.']);
            }
            [$local, $domainName] = explode('@', $address);
        } else {
            $local = strtolower(trim((string) $validated['local']));
            $domainName = strtolower(trim((string) $validated['domain']));
        }

        if ($local === '' || strlen($local) > 64 || ! preg_match('/^[a-z0-9._+-]+$/i', $local)) {
            throw ValidationException::withMessages([
                'local' => 'The address part before @ may only use letters, numbers, dots, dashes, underscores and plus signs (max 64).',
            ]);
        }

        $domain = $organization->domains()
            ->where('status', 'verified')
            ->whereRaw('lower(name) = ?', [$domainName])
            ->first();

        if (! $domain) {
            throw ValidationException::withMessages([
                'domain' => "The domain {$domainName} is not a verified domain in this workspace.",
            ]);
        }

        $email = $local.'@'.strtolower($domain->name);

        if (Mailbox::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'That mailbox address already exists.']);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'That email is already registered as a login.']);
        }

        $inbox = $request->boolean('inbox', true);
        $transactional = $request->boolean('transactional');
        $marketing = $request->boolean('marketing');

        if (! $inbox && ! $transactional && ! $marketing) {
            throw ValidationException::withMessages(['inbox' => 'Assign at least inbox, transactional, or marketing.']);
        }

        $password = filled($validated['password'] ?? null)
            ? (string) $validated['password']
            : self::generatePassword();
        $name = filled($validated['name'] ?? null) ? (string) $validated['name'] : $local;

        /** @var Mailbox $mailbox */
        $mailbox = DB::transaction(function () use ($organization, $domain, $email, $name, $password, $validated, $inbox, $transactional, $marketing) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $organization->users()->attach($user->id, ['role' => 'member']);

            return Mailbox::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'domain_id' => $domain->id,
                'email' => $email,
                'display_name' => $name,
                'type' => 'shared',
                'role' => $validated['role'] ?? 'staff',
                'status' => 'active',
                'inbox' => $inbox,
                'transactional' => $transactional,
                'marketing' => $marketing,
                'send_limit' => $validated['send_limit'] ?? 1000,
            ]);
        });

        $appPassword = null;
        if ($this->provisioner->enabled()) {
            try {
                $appPassword = $this->provisioner->provision($mailbox);
            } catch (Throwable $e) {
                Log::warning('API mailbox IMAP provision skipped after save', [
                    'mailbox' => $mailbox->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $mailbox->refresh();

        return response()->json([
            ...self::present($mailbox),
            // Shown once. Store it now; it cannot be read back from the API.
            'password' => $password,
            'mail_client' => [
                'enabled' => (bool) $mailbox->imap_enabled,
                'app_password' => $mailbox->imap_enabled ? $appPassword : null,
                'imap' => config('maildesk.stalwart.clients.imap', []),
                'smtp' => config('maildesk.stalwart.clients.smtp', []),
            ],
        ], 201);
    }

    public function destroy(Request $request, int $mailbox): JsonResponse
    {
        $organization = $this->organization($request);
        $model = $this->find($request, $mailbox);

        DB::transaction(function () use ($model, $organization) {
            $this->provisioner->destroyRemote($model);

            $userId = $model->user_id;
            $model->delete();

            if (! $userId) {
                return;
            }

            $organization->users()->detach($userId);

            $user = User::query()->find($userId);
            if ($user && ! $user->isPlatformAdmin() && $user->organizations()->count() === 0) {
                $user->delete();
            }
        });

        return response()->json(['id' => $mailbox, 'deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Mailbox $mailbox): array
    {
        return [
            'id' => $mailbox->id,
            'email' => $mailbox->email,
            'name' => $mailbox->display_name ?: $mailbox->email,
            'role' => $mailbox->role ?: 'staff',
            'status' => $mailbox->status ?: 'active',
            'inbox' => (bool) $mailbox->inbox,
            'transactional' => (bool) $mailbox->transactional,
            'marketing' => (bool) $mailbox->marketing,
            'send_limit' => $mailbox->send_limit,
            'has_login' => $mailbox->user_id !== null,
            'imap_enabled' => (bool) $mailbox->imap_enabled,
            'created_at' => $mailbox->created_at?->toIso8601String(),
            'updated_at' => $mailbox->updated_at?->toIso8601String(),
        ];
    }

    public static function generatePassword(): string
    {
        return Str::password(20, letters: true, numbers: true, symbols: false);
    }

    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    protected function find(Request $request, int $mailbox): Mailbox
    {
        return $this->organization($request)->mailboxes()->findOrFail($mailbox);
    }
}
