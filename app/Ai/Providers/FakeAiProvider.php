<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider;
use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\AiChatResult;

/**
 * Deterministic in-memory provider for local/testing (maildesk.ai.fake).
 */
class FakeAiProvider implements AiProvider
{
    /** @var list<AiChatRequest> */
    public array $requests = [];

    public function __construct(
        protected string $content = '{"priority":"normal","intent":"support","language":"en"}',
        protected string $model = 'fake-model',
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function configured(): bool
    {
        return true;
    }

    public function chat(AiChatRequest $request): AiChatResult
    {
        $this->requests[] = $request;

        $userText = collect($request->messages)
            ->filter(fn ($message) => ($message['role'] ?? null) !== 'system')
            ->pluck('content')
            ->implode(' ');
        $haystack = $userText !== ''
            ? $userText
            : collect($request->messages)->pluck('content')->implode(' ');
        $content = $this->content;

        if (
            stripos($haystack, 'draft a reply') !== false
            || stripos($haystack, 'Draft a reply from us') !== false
            || (stripos($haystack, 'Respond with HTML only') !== false && stripos($haystack, 'Compose assist') === false)
        ) {
            $content = '<p>Thanks for reaching out. We are looking into this and will follow up shortly.</p>';
        } elseif (stripos($haystack, 'Summarize this email thread') !== false || stripos($haystack, 'action_items') !== false) {
            $content = '{"summary":"Customer needs help with a delayed order.","action_items":["Confirm shipment status","Reply with ETA"]}';
        } elseif (stripos($haystack, 'Compose assist request') !== false) {
            if (stripos($haystack, 'Action: subject') !== false) {
                $content = '{"subjects":["Quick update for you","Following up","Your request"]}';
            } else {
                $content = '<p>Here is a clearer version of your message.</p>';
            }
        } elseif (stripos($haystack, 'Broadcast assist request') !== false || stripos($haystack, 'subject-line variants') !== false) {
            $content = '{"subjects":["What\'s new this week","A quick update from us","Don\'t miss this"],"html":"<h2>What\'s new</h2><p>We have exciting updates to share.</p>"}';
        } elseif (stripos($haystack, 'Natural-language workflow') !== false || stripos($haystack, 'automation workflows') !== false) {
            $content = '{"name":"Welcome series","trigger":"contact.added","steps":[{"type":"trigger","label":"When contact.added"},{"type":"delay","label":"Wait 1 hour"},{"type":"email","label":"Send welcome email"}]}';
        } elseif (stripos($haystack, 'Natural-language segment') !== false || stripos($haystack, 'segment rules') !== false) {
            $content = '{"name":"Subscribed Acme","description":"Subscribed contacts at acme.com","rules":[{"field":"meta.status","op":"eq","value":"subscribed"},{"field":"email_domain","op":"eq","value":"acme.com"}]}';
        } elseif (stripos($haystack, 'Explain this bounce') !== false || stripos($haystack, 'bounce and complaint') !== false) {
            $content = '{"explanation":"The recipient mailbox rejected the message as undeliverable.","causes":["Invalid address","Mailbox full"],"fixes":["Remove the address","Ask the recipient for an updated email"]}';
        } elseif (stripos($haystack, 'in-app help') !== false || stripos($haystack, 'Setup question') !== false) {
            $content = 'Add and verify a domain under Domains, then send from Compose using an address on that domain.';
        } elseif (stripos($haystack, 'abuse/risk summary') !== false || stripos($haystack, 'Flags JSON') !== false) {
            $content = 'Elevated bounce activity suggests list hygiene issues that should be reviewed.';
        } elseif (stripos($haystack, 'urgent') !== false || stripos($haystack, 'asap') !== false) {
            $content = '{"priority":"urgent","intent":"support","language":"en"}';
        } elseif (stripos($haystack, 'unsubscribe') !== false || stripos($haystack, 'buy now') !== false) {
            $content = '{"priority":"low","intent":"spam","language":"en"}';
        } elseif (stripos($haystack, 'pricing') !== false || stripos($haystack, 'demo') !== false) {
            $content = '{"priority":"normal","intent":"sales","language":"en"}';
        }

        return new AiChatResult(
            content: $content,
            provider: $this->name(),
            model: $this->model,
        );
    }
}
