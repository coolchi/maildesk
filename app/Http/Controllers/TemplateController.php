<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\EmailService;
use App\Support\ContentSamples;
use App\Support\CurrentOrganization;
use App\Support\DesignTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'samples' => ContentSamples::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
            'design_key' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['source_id'])) {
            $source = $organization->templates()->whereKey($validated['source_id'])->firstOrFail();
            $template = Template::query()->create([
                'organization_id' => $organization->id,
                'name' => $validated['name'] ?: $source->name.' (copy)',
                'subject' => $source->subject,
                'html' => $source->html,
                'design_key' => $source->design_key,
                'status' => 'draft',
            ]);
        } else {
            $template = Template::query()->create([
                'organization_id' => $organization->id,
                'name' => $validated['name'],
                'subject' => $validated['subject'] ?? '',
                'html' => $validated['html'] ?? '',
                'design_key' => DesignTemplates::normalize($validated['design_key'] ?? null),
                'status' => 'draft',
            ]);
        }

        return redirect()->route('templates.edit', $template)->with('success', 'Template created.');
    }

    public function storeFromSample(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'sample_key' => ['required', 'string', 'max:40'],
        ]);

        $sample = ContentSamples::find($validated['sample_key']);
        abort_unless($sample !== null, 404);

        $template = Template::query()->create([
            'organization_id' => $organization->id,
            'name' => $sample['name'],
            'subject' => $sample['subject'],
            'html' => $sample['html'],
            'design_key' => DesignTemplates::normalize($sample['design_key']),
            'status' => 'draft',
        ]);

        return redirect()->route('templates.edit', $template)->with('success', 'Sample copied. Edit the words and images, then save.');
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096'],
        ]);

        $path = $validated['image']->store('template-images/'.$organization->id, 'public');
        $url = Storage::disk('public')->url($path);
        if (str_starts_with($url, 'http://') && ! str_contains($url, 'localhost') && ! str_contains($url, '127.0.0.1')) {
            $url = 'https://'.substr($url, strlen('http://'));
        }

        return response()->json([
            'url' => $url,
        ]);
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
            'design_key' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        $template->fill([
            'name' => $validated['name'],
            'subject' => $validated['subject'] ?? '',
            'html' => $validated['html'] ?? '',
        ]);

        if (array_key_exists('design_key', $validated)) {
            $template->design_key = DesignTemplates::normalize($validated['design_key']);
        }

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

        $validated = $request->validate([
            'to' => ['nullable', 'email', 'max:255'],
        ]);

        $to = $validated['to'] ?? $user->email;

        $emails->send($organization, [
            'from' => 'hello@'.$domain,
            'to' => [[
                'email' => $to,
                'name' => strcasecmp($to, $user->email) === 0 ? $user->name : null,
            ]],
            'subject' => '[Test] '.($template->subject ?: $template->name),
            'html' => $template->html ?: '<p></p>',
            'design' => $template->design_key ?: DesignTemplates::defaultKey($organization),
            'tags' => ['template-test:'.$template->id],
            'thread' => false,
            'signature' => false,
            'expand_groups' => false,
            'meta' => ['template_id' => $template->id, 'template_test' => true],
        ]);

        return back()->with('success', 'Test email sent to '.$to.'.');
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

    public function updateDesignColor(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'design_key' => ['required', 'string', 'max:40'],
            'accent' => ['required_without:reset', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        $accent = $request->boolean('reset')
            ? DesignTemplates::resetAccent($organization, $validated['design_key'])
            : DesignTemplates::setAccent($organization, $validated['design_key'], (string) $validated['accent']);
        if ($accent === null) {
            return back()->withErrors(['accent' => 'Choose a design and a color.']);
        }

        return back();
    }

    public function updateDefaultDesign(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'design_key' => ['nullable', 'string', 'max:40'],
        ]);

        DesignTemplates::setDefault($organization, $validated['design_key'] ?? null);

        $key = DesignTemplates::normalize($validated['design_key'] ?? null);
        $name = DesignTemplates::find($key)['name'] ?? null;

        return back()->with(
            'success',
            $name ? $name.' is the default design.' : 'New messages default to plain mail.',
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
