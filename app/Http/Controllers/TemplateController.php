<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\EmailService;
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
                'status' => 'draft',
            ]);
        } else {
            $template = Template::query()->create([
                'organization_id' => $organization->id,
                'name' => $validated['name'],
                'subject' => $validated['subject'] ?? '',
                'html' => $validated['html'] ?? '',
                'status' => 'draft',
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
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        $template->fill([
            'name' => $validated['name'],
            'subject' => $validated['subject'] ?? '',
            'html' => $validated['html'] ?? '',
        ]);

        if (isset($validated['status'])) {
            $template->status = $validated['status'];
        }

        $template->save();

        return back()->with('success', 'Template saved.');
    }

    public function test(Request $request, Template $template, EmailService $emails): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($template->organization_id === $organization->id, 404);

        $domain = $organization->domains()
            ->where('status', 'verified')
            ->orderBy('name')
            ->value('name');

        if ($domain === null) {
            return back()->with('error', 'Verify a sending domain before sending a test.');
        }

        if (blank($template->html) && blank($template->subject)) {
            return back()->with('error', 'Add a subject or body before sending a test.');
        }

        $user = $request->user();

        $emails->send($organization, [
            'from' => 'hello@'.$domain,
            'to' => [['email' => $user->email, 'name' => $user->name]],
            'subject' => '[Test] '.($template->subject ?: $template->name),
            'html' => $template->html ?: '<p></p>',
            'tags' => ['template-test:'.$template->id],
            'thread' => false,
            'signature' => false,
            'expand_groups' => false,
            'meta' => ['template_id' => $template->id, 'template_test' => true],
        ]);

        return back()->with('success', 'Test email sent to '.$user->email.'.');
    }

    public function publish(Request $request, Template $template): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($template->organization_id === $organization->id, 404);

        $next = $template->status === 'published' ? 'draft' : 'published';
        $template->forceFill(['status' => $next])->save();

        return back()->with(
            'success',
            $next === 'published' ? 'Template published.' : 'Template moved to draft.',
        );
    }

    public function destroy(Request $request, Template $template): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($template->organization_id === $organization->id, 404);

        $template->delete();

        return redirect()->route('templates')->with('success', 'Template deleted.');
    }
}
