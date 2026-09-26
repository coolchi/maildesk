<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Support\EmailHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EmailHtmlRenderingTest extends TestCase
{
    use RefreshDatabase;

    private const DIRTY = <<<'HTML'
<div style="color:#808080;padding:8px" onclick="steal()">Hello
<script>alert(document.cookie)</script>
<img src="https://example.com/logo.png" onerror="steal()" alt="logo" width="120">
<a href="https://example.com/offer" onmouseover="steal()">Offer</a>
<a href="javascript:alert(1)">Bad link</a>
<iframe src="https://evil.test"></iframe>
<form action="https://evil.test"><input name="password"></form>
<table bgcolor="#ffffff" width="600"><tr><td align="center" style="border-top:1px solid #e0dfdd">Cell</td></tr></table>
</div>
HTML;

    public function test_scripts_are_stripped(): void
    {
        $clean = EmailHtmlSanitizer::clean(self::DIRTY);

        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsString('document.cookie', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<form', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<input', $clean);
    }

    public function test_event_handlers_and_javascript_urls_are_removed(): void
    {
        $clean = EmailHtmlSanitizer::clean(self::DIRTY);

        foreach (['onclick', 'onerror', 'onmouseover', 'javascript:', 'steal('] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $clean);
        }
    }

    public function test_links_open_in_a_new_tab_without_opener(): void
    {
        $clean = EmailHtmlSanitizer::clean('<p><a href="https://example.com/offer">Offer</a></p>');

        $this->assertMatchesRegularExpression('/<a [^>]*target="_blank"/', $clean);
        $this->assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $clean);
        $this->assertMatchesRegularExpression('/rel="[^"]*noreferrer[^"]*"/', $clean);
    }

    public function test_email_layout_styles_are_kept(): void
    {
        $clean = EmailHtmlSanitizer::clean(self::DIRTY);

        $this->assertStringContainsString('color:#808080', $clean);
        $this->assertStringContainsString('bgcolor="#ffffff"', $clean);
        $this->assertStringContainsString('align="center"', $clean);
        $this->assertStringContainsString('border-top:1px solid #e0dfdd', $clean);
        $this->assertStringContainsString('src="https://example.com/logo.png"', $clean);
        $this->assertStringContainsString('Cell', $clean);
    }

    public function test_empty_html_is_left_alone(): void
    {
        $this->assertNull(EmailHtmlSanitizer::clean(null));
        $this->assertSame('', EmailHtmlSanitizer::clean(''));
    }

    public function test_inbox_pages_receive_sanitized_html_and_raw_html_stays_stored(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create(['organization_id' => $org->id]);
        $thread = Thread::factory()->create(['organization_id' => $org->id, 'mailbox_id' => $mailbox->id]);
        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'html_body' => self::DIRTY,
        ]);

        $check = fn (?string $html) => $html !== null
            && ! str_contains($html, '<script')
            && ! str_contains($html, 'onerror')
            && str_contains($html, 'target="_blank"');

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->where('threads.0.messages.0.html', $check));

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox.show', $thread->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Show')
                ->where('thread.messages.0.html', $check));

        $this->assertSame(self::DIRTY, $message->fresh()->html_body);
    }

    public function test_email_detail_gets_sanitized_preview_and_raw_source(): void
    {
        $message = Message::factory()->create(['html_body' => self::DIRTY]);

        $payload = $message->toWorkspaceArray(true);

        $this->assertStringNotContainsString('<script', $payload['html']);
        $this->assertSame(self::DIRTY, $payload['html_source']);
    }
}
