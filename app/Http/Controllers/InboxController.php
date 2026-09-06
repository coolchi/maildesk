<?php

namespace App\Http\Controllers;

use App\Models\Thread;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $threads = $organization->threads()
            ->with(['messages' => fn ($query) => $query->orderBy('created_at')])
            ->latest('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (Thread $thread) => $thread->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Inbox/Index', [
            'threads' => $threads,
        ]);
    }
}
