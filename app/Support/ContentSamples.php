<?php

namespace App\Support;

/**
 * Starter content templates. Using one copies it into the workspace,
 * where the words and images can be edited like any other template.
 */
class ContentSamples
{
    /**
     * @return list<array{key: string, name: string, description: string, subject: string, design_key: string, html: string}>
     */
    public static function all(): array
    {
        return [
            self::welcome(),
            self::gettingStarted(),
            self::invoice(),
            self::receipt(),
            self::offer(),
            self::newsletter(),
            self::promotion(),
            self::shipping(),
            self::appointment(),
            self::review(),
            self::renewal(),
            self::event(),
            self::followUp(),
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}|null
     */
    public static function find(mixed $key): ?array
    {
        $key = strtolower(trim((string) $key));
        foreach (self::all() as $sample) {
            if ($sample['key'] === $key) {
                return $sample;
            }
        }

        return null;
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function welcome(): array
    {
        return self::make(
            'welcome',
            'Welcome',
            'The first note a new customer receives.',
            'Welcome',
            'aurora',
            self::picture('welcome-hero.png', 'A sunlit chair by an open door').
            '<h2>Welcome, {{first_name}}</h2>'.
            '<p>You are in. This is the note new customers see first, so it should sound like you.</p>'.
            '<p>Here is what happens next:</p>'.
            '<ul>'.
            '<li><strong>Today</strong> — your account is open, and this inbox is the one we will use.</li>'.
            '<li><strong>This week</strong> — we send only what you asked for.</li>'.
            '<li><strong>Whenever you need us</strong> — reply to this email and a person answers.</li>'.
            '</ul>'.
            '<p><a href="https://example.com/start">Open your account</a></p>'.
            '<p>Glad you are here.</p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function gettingStarted(): array
    {
        return self::make(
            'getting-started',
            'Getting started',
            'A short setup list for a new customer.',
            'Your first three steps',
            'signal',
            self::picture('getting-started.png', 'A clear desk ready for work').
            '<h2>Three steps, then you are set</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>Most people finish this in one sitting. Change the steps so they match what you actually need.</p>'.
            '<ol>'.
            '<li>Add the address you want replies sent to.</li>'.
            '<li>Invite the person who should see this with you.</li>'.
            '<li>Send yourself a test so you know how it looks.</li>'.
            '</ol>'.
            '<p><a href="https://example.com/setup">Continue setup</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function invoice(): array
    {
        return self::make(
            'invoice',
            'Invoice',
            'Amount due, line items, and a pay link.',
            'Invoice INV-1042',
            'editorial',
            self::picture('invoice-still.png', 'Paper, a pen, and a linen folder').
            '<h2>Invoice INV-1042</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>This covers September. Change the number, the lines, and the date before you send it.</p>'.
            '<ul>'.
            '<li><strong>Workspace plan</strong> — 49.00</li>'.
            '<li><strong>Extra mailboxes, 2</strong> — 12.00</li>'.
            '<li><strong>Amount due</strong> — 61.00, by 12 October</li>'.
            '</ul>'.
            '<p>Pay from the link, or reply if a line looks wrong.</p>'.
            '<p><a href="https://example.com/pay/inv-1042">Pay invoice</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function receipt(): array
    {
        return self::make(
            'receipt',
            'Payment receipt',
            'Confirmation that a payment landed.',
            'Payment received',
            'linen',
            self::picture('receipt-still.png', 'An envelope and an olive sprig').
            '<h2>We received your payment</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>61.00 landed on 28 September for invoice INV-1042. This is your receipt.</p>'.
            '<ul>'.
            '<li><strong>Amount</strong> — 61.00</li>'.
            '<li><strong>Reference</strong> — INV-1042</li>'.
            '<li><strong>Paid</strong> — 28 September</li>'.
            '</ul>'.
            '<p><a href="https://example.com/receipts/inv-1042">Download the receipt</a></p>'.
            '<p>Thank you. Reply if you need this sent to someone else.</p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function offer(): array
    {
        return self::make(
            'offer',
            'Sales offer',
            'A product, a price, and a deadline.',
            'Held for you until Friday',
            'midnight',
            self::picture('offer-product.png', 'A matte black bottle on stone').
            '<h2>Held for you until Friday</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>The piece in the photo is the one we talked about. I can keep it aside until Friday at 6pm.</p>'.
            '<ul>'.
            '<li><strong>Price</strong> — 180.00, including delivery</li>'.
            '<li><strong>Included</strong> — the item, a note, and 14 days to change your mind</li>'.
            '</ul>'.
            '<p><a href="https://example.com/offer">Take this offer</a></p>'.
            '<p>If it is not right, tell me what would be. I would rather adjust it than guess.</p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function newsletter(): array
    {
        return self::make(
            'newsletter',
            'Newsletter',
            'A banner and three short stories.',
            'Notes from this month',
            'editorial',
            self::picture('newsletter-banner.png', 'Citrus, flowers, and paper on a table').
            '<h2>This month</h2>'.
            '<p>Hello {{first_name}}, three short notes, then we will leave you alone.</p>'.
            self::picture('newsletter-story-1.png', 'A quiet morning street').
            '<h3>The morning route</h3>'.
            '<p>We changed the delivery window to before noon on weekdays. If yours should be different, reply with the time that works.</p>'.
            self::picture('newsletter-story-2.png', 'Two people at a workshop table').
            '<h3>In the workshop</h3>'.
            '<p>A small run finished this week. The next one opens on the 15th, and the list is short.</p>'.
            '<p><a href="https://example.com/journal/workshop">Read the note</a></p>'.
            self::picture('newsletter-story-3.png', 'A handmade ceramic cup').
            '<h3>One object</h3>'.
            '<p>The cup in the photo is the sample for the autumn set. Tell us if you want one held.</p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function promotion(): array
    {
        return self::make(
            'promotion',
            'Promotion',
            'A banner, a code, and an end date.',
            '20% off through Friday',
            'aurora',
            self::picture('promo-banner.png', 'Coral flowers and a gold ribbon').
            '<h2>20% off, through Friday</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>Use the code below at checkout. It ends Friday at midnight, and it does not combine with anything else.</p>'.
            '<p><strong>WEEKEND20</strong></p>'.
            '<p><a href="https://example.com/shop">Shop the offer</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function shipping(): array
    {
        return self::make(
            'shipping',
            'Shipping update',
            'A parcel photo, a carrier, and a tracking link.',
            'Your order is on the way',
            'signal',
            self::picture('shipping-box.png', 'An open box packed with tissue').
            '<h2>Your order is on the way</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>The parcel left this morning. Change the carrier and the link to match the shipment.</p>'.
            '<ul>'.
            '<li><strong>Order</strong> — 4821</li>'.
            '<li><strong>Carrier</strong> — Rider</li>'.
            '<li><strong>Window</strong> — Thursday, between 10 and 2</li>'.
            '</ul>'.
            '<p><a href="https://example.com/track/4821">Track the parcel</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function appointment(): array
    {
        return self::make(
            'appointment',
            'Appointment',
            'Time, place, and a link to join.',
            'You are booked',
            'linen',
            self::picture('appointment.png', 'Two chairs and a table in afternoon light').
            '<h2>You are booked</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>Here is the time we are holding. Reply if you need a different one.</p>'.
            '<ul>'.
            '<li><strong>When</strong> — Thursday 3 October, 2:00pm</li>'.
            '<li><strong>Where</strong> — 14 Harbor Street, or the link below</li>'.
            '<li><strong>Length</strong> — 30 minutes</li>'.
            '</ul>'.
            '<p>Bring the question you most want answered. We will start there.</p>'.
            '<p><a href="https://example.com/meet/thursday">Join the meeting</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function review(): array
    {
        return self::make(
            'review',
            'Review request',
            'A product photo and one question.',
            'How did we do?',
            'linen',
            self::picture('review-product.png', 'A notebook and a single flower').
            '<h2>How did we do?</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>You have had it for a week. One honest note helps the next person decide, and it helps us fix what is off.</p>'.
            '<p><a href="https://example.com/review">Leave a short review</a></p>'.
            '<p>If something is wrong, skip the form and reply. We would rather hear it here.</p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function renewal(): array
    {
        return self::make(
            'renewal',
            'Renewal reminder',
            'Plan, date, and amount before a renewal.',
            'Your plan renews on 12 October',
            'midnight',
            self::picture('renewal.png', 'A brass key on a dark table').
            '<h2>Your plan renews on 12 October</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>Nothing is due today. This is the reminder so the renewal is not a surprise.</p>'.
            '<ul>'.
            '<li><strong>Plan</strong> — Studio, billed yearly</li>'.
            '<li><strong>Renews</strong> — 12 October</li>'.
            '<li><strong>Amount</strong> — 490.00</li>'.
            '</ul>'.
            '<p>Stay on this plan, or tell us before the date if you want a smaller one.</p>'.
            '<p><a href="https://example.com/billing">Review the renewal</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function event(): array
    {
        return self::make(
            'event',
            'Event invitation',
            'A gathering, a time, and a way to reply.',
            'You are invited',
            'aurora',
            self::picture('event-banner.png', 'A long table under string lights').
            '<h2>You are invited</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>Thursday 16 October, from 6pm, in the courtyard. Dinner is served at 7. There is room for one guest if you say so by Monday.</p>'.
            '<ul>'.
            '<li><strong>Where</strong> — The courtyard, 14 Harbor Street</li>'.
            '<li><strong>When</strong> — Thursday 16 October, 6:00pm</li>'.
            '<li><strong>Dress</strong> — Come as you are</li>'.
            '</ul>'.
            '<p><a href="https://example.com/rsvp">Save your seat</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function followUp(): array
    {
        return self::make(
            'follow-up',
            'Meeting follow-up',
            'What you agreed, written down after the call.',
            'Following up on our conversation',
            'editorial',
            self::picture('follow-up.png', 'A notebook and coffee after a meeting').
            '<h2>Following up</h2>'.
            '<p>Hello {{first_name}},</p>'.
            '<p>Thank you for the time yesterday. I wrote down what we agreed, so it does not live only in the meeting.</p>'.
            '<ul>'.
            '<li>We will send the revised proposal by Tuesday.</li>'.
            '<li>You will confirm who else should be on the thread.</li>'.
            '<li>We meet again on the 9th, same time.</li>'.
            '</ul>'.
            '<p>If I missed a point, reply with it and I will correct this note.</p>'.
            '<p><a href="https://example.com/notes">Open the notes</a></p>',
        );
    }

    /**
     * @return array{key: string, name: string, description: string, subject: string, design_key: string, html: string}
     */
    private static function make(string $key, string $name, string $description, string $subject, string $designKey, string $html): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'subject' => $subject,
            'design_key' => $designKey,
            'html' => $html,
        ];
    }

    private static function picture(string $file, string $alt): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if (str_starts_with($base, 'http://') && ! str_contains($base, 'localhost') && ! str_contains($base, '127.0.0.1')) {
            $base = 'https://'.substr($base, strlen('http://'));
        }

        return '<img src="'.e($base.'/images/templates/'.$file).'" alt="'.e($alt).'">';
    }
}
