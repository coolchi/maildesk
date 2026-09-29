<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\ContactWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public const PER_PAGE = 50;

    public function __construct(public ContactWriter $writer) {}

    /**
     * List contacts with pagination and search.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(self::PER_PAGE, max(10, (int) $request->query('per_page', 20)));

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
            ->latest();

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $contacts = collect($paginator->items())
            ->map(fn (Contact $contact) => $contact->toWorkspaceArray())
            ->values()
            ->all();

        return response()->json([
            'contacts' => $contacts,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'search' => $search !== '' ? $search : null,
        ]);
    }

    /**
     * Create a new contact.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:190'],
        ]);

        $contact = $this->writer->upsert(
            $organization,
            $validated['email'],
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null,
            $validated['company'] ?? null,
        );

        return response()->json([
            'contact' => $contact->toWorkspaceArray(),
        ], 201);
    }

    /**
     * Get the organization from the request.
     */
    protected function organization(Request $request): Organization
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');

        if (! $workspaceId) {
            abort(400, 'Workspace ID is required. Pass X-Workspace-Id header or workspace_id query parameter.');
        }

        $user = $request->user();
        $organization = $user->organizations()->whereKey($workspaceId)->first();

        if (! $organization) {
            abort(403, 'You do not have access to this workspace.');
        }

        return $organization;
    }
}
