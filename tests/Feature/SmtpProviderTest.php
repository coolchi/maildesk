<?php

namespace Tests\Feature;

use App\Mail\DTO\OutboundEmail;
use App\Mail\Providers\SmtpProvider;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SmtpProviderTest extends TestCase
{
    private function recorder(): AbstractTransport
    {
        return new class extends AbstractTransport
        {
            /** @var list<SentMessage> */
            public array $sent = [];

            protected function doSend(SentMessage $message): void
            {
                $this->sent[] = $message;
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
    }

    public function test_sends_with_all_headers_and_returns_the_message_id(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('attachments/report.txt', 'quarterly numbers');

        $transport = $this->recorder();
        $provider = new SmtpProvider(['host' => 'smtp.test'], $transport);

        $result = $provider->send(new OutboundEmail(
            fromEmail: 'hello@acme.test',
            fromName: 'Acme Support',
            to: ['sam@example.com', ['email' => 'kim@example.com', 'name' => 'Kim']],
            subject: 'Your order',
            html: '<p>Shipped</p>',
            text: 'Shipped',
            cc: ['cc@example.com'],
            bcc: ['audit@acme.test'],
            replyTo: ['support@acme.test'],
            tags: ['broadcast:7'],
            headers: [
                'Message-ID' => '<abc-123@acme.test>',
                'In-Reply-To' => '<parent-1@example.com>',
                'References' => '<root-0@example.com> <parent-1@example.com>',
                'List-Unsubscribe' => '<https://acme.test/unsubscribe/1?signature=x>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'X-MailDesk-Group' => 'team@acme.test',
                'Subject' => 'ignored duplicate',
                'X-Empty' => null,
            ],
            attachments: [[
                'filename' => 'report.txt',
                'content_type' => 'text/plain',
                'path' => 'attachments/report.txt',
                'disk' => 'local',
            ]],
        ));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('abc-123@acme.test', $result->providerMessageId);
        $this->assertCount(1, $transport->sent);

        /** @var Email $email */
        $email = $transport->sent[0]->getOriginalMessage();
        $headers = $email->getHeaders();

        $this->assertSame('<abc-123@acme.test>', $headers->get('Message-ID')->getBodyAsString());
        $this->assertCount(1, iterator_to_array($headers->all('Message-ID'), false));
        $this->assertSame('<parent-1@example.com>', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<root-0@example.com> <parent-1@example.com>', $headers->get('References')->getBodyAsString());
        $this->assertSame('<https://acme.test/unsubscribe/1?signature=x>', $headers->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertSame('team@acme.test', $headers->get('X-MailDesk-Group')->getBodyAsString());
        $this->assertSame('broadcast:7', $headers->get('X-MailDesk-Tags')->getBodyAsString());
        $this->assertFalse($headers->has('X-Empty'));
        $this->assertSame('Your order', $email->getSubject());
        $this->assertCount(1, iterator_to_array($headers->all('Subject'), false));

        $this->assertSame('Acme Support', $email->getFrom()[0]->getName());
        $this->assertSame(['sam@example.com', 'kim@example.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('Kim', $email->getTo()[1]->getName());
        $this->assertSame('cc@example.com', $email->getCc()[0]->getAddress());
        $this->assertSame('audit@acme.test', $email->getBcc()[0]->getAddress());
        $this->assertSame('support@acme.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('<p>Shipped</p>', $email->getHtmlBody());
        $this->assertSame('Shipped', $email->getTextBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('report.txt', $email->getAttachments()[0]->getFilename());

        // Bcc goes in the envelope, not the headers.
        $recipients = array_map(fn ($a) => $a->getAddress(), $transport->sent[0]->getEnvelope()->getRecipients());
        $this->assertContains('audit@acme.test', $recipients);
        $this->assertStringNotContainsString('audit@acme.test', $transport->sent[0]->toString());
    }

    public function test_generates_a_message_id_when_none_is_given(): void
    {
        $transport = $this->recorder();
        $result = (new SmtpProvider(['host' => 'smtp.test'], $transport))->send(new OutboundEmail(
            fromEmail: 'hello@acme.test',
            fromName: null,
            to: ['sam@example.com'],
            subject: 'Plain',
            text: 'Hi',
        ));

        $this->assertTrue($result->success);
        $this->assertStringEndsWith('@acme.test', $result->providerMessageId);
        $this->assertSame(
            '<'.$result->providerMessageId.'>',
            $transport->sent[0]->getOriginalMessage()->getHeaders()->get('Message-ID')->getBodyAsString(),
        );
    }

    public function test_transport_factory_receives_the_resolved_config(): void
    {
        $seen = null;
        $transport = $this->recorder();
        $provider = new SmtpProvider(['host' => 'smtp.own.test', 'password' => 'x'], function (array $config) use (&$seen, $transport) {
            $seen = $config;

            return $transport;
        });

        $provider->send(new OutboundEmail('a@acme.test', null, ['b@example.com'], 'S', text: 'T'));

        $this->assertSame('smtp.own.test', $seen['host']);
        $this->assertCount(1, $transport->sent);
    }

    public function test_builds_esmtp_transport_from_config(): void
    {
        $ssl = SmtpProvider::makeTransport(['host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'u@x', 'password' => 'p:/@ss']);
        $this->assertInstanceOf(EsmtpTransport::class, $ssl);
        $this->assertSame('smtp.example.com', $ssl->getStream()->getHost());
        $this->assertSame(465, $ssl->getStream()->getPort());
        $this->assertTrue($ssl->getStream()->isTLS());
        $this->assertSame('u@x', $ssl->getUsername());
        $this->assertSame('p:/@ss', $ssl->getPassword());

        $tls = SmtpProvider::makeTransport(['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls']);
        $this->assertFalse($tls->getStream()->isTLS());
        $this->assertSame(587, $tls->getStream()->getPort());

        $plain = SmtpProvider::makeTransport(['host' => 'localhost', 'port' => 1025, 'encryption' => 'none']);
        $this->assertFalse($plain->isAutoTls());
    }

    public function test_missing_host_fails_cleanly(): void
    {
        $result = (new SmtpProvider(['host' => '']))->send(new OutboundEmail('a@acme.test', null, ['b@example.com'], 'S', text: 'T'));

        $this->assertFalse($result->success);
        $this->assertSame('SMTP host is not configured.', $result->error);
    }
}
