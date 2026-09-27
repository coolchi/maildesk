<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\Suppression;
use App\Services\ContactWriter;
use App\Services\SegmentMembership;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public const PER_PAGE = 50;

    public const IMPORT_LIMIT = 5000;

    public function __construct(public ContactWriter $writer) {}

    public function index(Request $request, SegmentMembership $membership): Response
    {
        $organization = CurrentOrganization::from($request);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $validStatuses = ['subscribed', 'unsubscribed'];

        $query = $organization->contacts()
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.strtolower($search).'%';
                $q->where(function (Builder $inner) use ($like) {
                    $inner->whereRaw('lower(email) like ?', [$like])
                        ->orWhereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like])
                        ->orWhereRaw('lower(company) like ?', [$like]);
                });
            })
            ->when($status && in_array($status, $validStatuses, true), function (Builder $q) use ($status) {
                if ($status === 'unsubscribed') {
                    $q->where(function (Builder $inner) {
                        $inner->whereNotNull('unsubscribed_at')
                            ->orWhere('meta->status', 'unsubscribed');
                    });
                } else {
                    $q->whereNull('unsubscribed_at')
                        ->where(function (Builder $inner) {
                            $inner->whereNull('meta->status')
                                ->orWhere('meta->status', 'subscribed');
                        });
                }
            })
            ->latest();

        $paginator = $query->paginate(self::PER_PAGE)->withQueryString();
        $contacts = collect($paginator->items())
            ->map(fn (Contact $contact) => $contact->toWorkspaceArray())
            ->values()
            ->all();

        $stats = [
            'all' => $organization->contacts()->count(),
            'subscribers' => $organization->contacts()
                ->whereNull('unsubscribed_at')
                ->where(fn (Builder $q) => $q->whereNull('meta->status')->orWhere('meta->status', 'subscribed'))
                ->count(),
            'unsubscribers' => $organization->contacts()
                ->where(fn (Builder $q) => $q->whereNotNull('unsubscribed_at')->orWhere('meta->status', 'unsubscribed'))
                ->count(),
        ];

        $segments = $organization->segments()
            ->latest()
            ->get()
            ->map(function (Segment $segment) use ($membership) {
                return $segment->toWorkspaceArray(
                    memberCount: $membership->count($segment),
                    ruleSummary: $membership->summarize($segment->rules),
                );
            })
            ->values()
            ->all();

        return Inertia::render('Audience/Index', [
            'contacts' => $contacts,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'stats' => $stats,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status && in_array($status, $validStatuses, true) ? $status : null,
            ],
            'segments' => $segments,
            'properties' => [],
            'topics' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        // Single-contact payload (legacy) or contacts[] repeater.
        if ($request->filled('email') && ! $request->has('contacts')) {
            $request->merge([
                'contacts' => [[
                    'email' => $request->input('email'),
                    'name' => $request->input('name'),
                    'first_name' => $request->input('first_name'),
                    'last_name' => $request->input('last_name'),
                    'company' => $request->input('company'),
                ]],
            ]);
        }

        $validated = $request->validate([
            'contacts' => ['required', 'array', 'min:1', 'max:100'],
            'contacts.*.email' => ['required', 'email', 'max:255'],
            'contacts.*.name' => ['nullable', 'string', 'max:240'],
            'contacts.*.first_name' => ['nullable', 'string', 'max:120'],
            'contacts.*.last_name' => ['nullable', 'string', 'max:120'],
            'contacts.*.company' => ['nullable', 'string', 'max:190'],
        ]);

        $saved = 0;
        foreach ($validated['contacts'] as $row) {
            $this->writer->upsert(
                $organization,
                $row['email'],
                $row['first_name'] ?? null,
                $row['last_name'] ?? null,
                $row['company'] ?? null,
                $row['name'] ?? null,
            );
            $saved++;
        }

        return back()->with(
            'success',
            $saved === 1 ? 'Contact saved.' : "{$saved} contacts saved.",
        );
    }

    public function import(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $handle = fopen($validated['file']->getRealPath(), 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = null;
        $saved = 0;
        $skipped = 0;
        $rowNumber = 0;
        $totalDataRows = 0;
        $seenEmails = [];

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($row === [null] || $row === false) {
                    continue;
                }

                $cells = array_map(fn ($value) => trim((string) $value), $row);

                if ($header === null) {
                    $header = $this->normalizeImportHeader($cells);
                    if (! in_array('email', $header, true)) {
                        $header = ['email', 'name', 'company'];
                        $mapped = $this->mapImportRow($header, $cells);
                        $totalDataRows++;
                    } else {
                        continue;
                    }
                } else {
                    $mapped = $this->mapImportRow($header, $cells);
                    $totalDataRows++;
                }

                $email = Str::lower((string) ($mapped['email'] ?? ''));
                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;

                    continue;
                }

                if (isset($seenEmails[$email])) {
                    $skipped++;

                    continue;
                }
                $seenEmails[$email] = true;

                if ($saved >= self::IMPORT_LIMIT) {
                    $skipped++;

                    continue;
                }

                $this->writer->upsert(
                    $organization,
                    $email,
                    $mapped['first_name'] ?? null,
                    $mapped['last_name'] ?? null,
                    $mapped['company'] ?? null,
                    $mapped['name'] ?? null,
                );
                $saved++;
            }
        } finally {
            fclose($handle);
        }

        if ($saved === 0) {
            return back()->withErrors([
                'file' => $skipped > 0
                    ? 'No valid contacts found in the file.'
                    : 'The file was empty.',
            ]);
        }

        $limitExceeded = $totalDataRows > self::IMPORT_LIMIT;
        $message = $saved === 1 ? '1 contact imported.' : "{$saved} contacts imported.";

        if ($limitExceeded) {
            $over = $totalDataRows - self::IMPORT_LIMIT;
            $message .= " {$over} row(s) exceeded the ".number_format(self::IMPORT_LIMIT).' row limit.';
        }

        if ($skipped > 0 && ! $limitExceeded) {
            $message .= " {$skipped} row(s) skipped (invalid or duplicate).";
        } elseif ($skipped > 0 && $limitExceeded) {
            $invalidSkipped = $skipped - ($totalDataRows - self::IMPORT_LIMIT);
            if ($invalidSkipped > 0) {
                $message .= " {$invalidSkipped} row(s) skipped (invalid or duplicate).";
            }
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($contact->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['subscribed', 'unsubscribed'])],
            'name' => ['sometimes', 'nullable', 'string', 'max:240'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company' => ['sometimes', 'nullable', 'string', 'max:190'],
        ]);

        if (array_key_exists('name', $validated)) {
            [$first, $last] = $this->writer->splitName($validated['name']);
            $contact->first_name = $first;
            $contact->last_name = $last;
        }

        if (array_key_exists('first_name', $validated)) {
            $contact->first_name = $validated['first_name'];
        }

        if (array_key_exists('last_name', $validated)) {
            $contact->last_name = $validated['last_name'];
        }

        if (array_key_exists('company', $validated)) {
            $contact->company = $validated['company'] !== null && trim((string) $validated['company']) !== ''
                ? trim((string) $validated['company'])
                : null;
        }

        if (isset($validated['status'])) {
            $meta = (array) ($contact->meta ?? []);
            if ($validated['status'] === 'unsubscribed') {
                $meta['status'] = 'unsubscribed';
                $contact->unsubscribed_at = $contact->unsubscribed_at ?? now();
            } else {
                $meta['status'] = 'subscribed';
                $contact->unsubscribed_at = null;
            }
            $contact->meta = $meta;
        }

        $contact->save();

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($contact->organization_id === $organization->id, 404);

        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }

    public function suppress(Request $request, Contact $contact): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($contact->organization_id === $organization->id, 404);

        Suppression::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => Str::lower($contact->email),
            ],
            [
                'reason' => 'Added from audience',
                'source' => 'manual',
            ],
        );

        return back()->with('success', 'Contact added to suppressions.');
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    protected function normalizeImportHeader(array $cells): array
    {
        return array_map(function (string $cell) {
            $key = Str::of($cell)->lower()->replace([' ', '-'], '_')->toString();

            return match ($key) {
                'e_mail', 'email_address', 'mail' => 'email',
                'full_name', 'fullname' => 'name',
                'firstname', 'first' => 'first_name',
                'lastname', 'last' => 'last_name',
                'organisation', 'organization', 'org', 'company_name' => 'company',
                default => $key,
            };
        }, $cells);
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    protected function mapImportRow(array $header, array $cells): array
    {
        $mapped = [];
        foreach ($header as $index => $key) {
            $mapped[$key] = $cells[$index] ?? '';
        }

        return $mapped;
    }
}
