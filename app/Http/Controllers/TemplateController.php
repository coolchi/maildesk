<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $templates = $organization->templates()
            ->latest()
            ->get()
            ->map(fn (Template $template) => $template->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
            'source_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['source_id'])) {
            $source = $organization->templates()->whereKey($validated['source_id'])->firstOrFail();
            $template = Template::query()->create([
                'organization_id' => $organization->id,
                'name' => $validated['name'] ?: $source->name.' (copy)',
                'subject' => $source->subject,
                'html' => $source->html,
            ]);
        } else {
            $template = Template::query()->create([
                'organization_id' => $organization->id,
                'name' => $validated['name'],
                'subject' => $validated['subject'] ?? '',
                'html' => $validated['html'] ?? '',
            ]);
        }

        return redirect()->route('templates.edit', $template)->with('success', 'Template created.');
    }

    public function edit(Request $request, Template $template): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($template->organization_id === $organization->id, 404);

        return Inertia::render('Templates/Edit', [
            'id' => $template->id,
            'template' => $template->toWorkspaceArray() + [
                'html' => $template->html,
                'subject' => $template->subject,
            ],
        ]);
    }

    public function update(Request $request, Template $template): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($template->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
        ]);

        $template->fill([
            'name' => $validated['name'],
            'subject' => $validated['subject'] ?? '',
            'html' => $validated['html'] ?? '',
        ])->save();

        return back()->with('success', 'Template saved.');
    }

    public function destroy(Request $request, Template $template): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($template->organization_id === $organization->id, 404);

        $template->delete();

        return redirect()->route('templates')->with('success', 'Template deleted.');
    }
}
