# MailDesk

MailDesk is a multi-tenant business email platform. Each customer organisation gets its own workspace (on its own subdomain, e.g. `acme.maildesk.test`) where it can send transactional and marketing email, read a shared inbox, manage its audience, verify sending domains, and integrate through a REST API and webhooks. A platform admin area manages customer accounts, plans, subscriptions, mail providers, and subdomains.

Outbound mail goes through a pluggable provider layer: **Resend** by default, **SMTP** as an alternative, and a fake in-memory provider for local development and tests.

> Status: early / pilot. The core send path, tenancy, API keys, suppressions, scheduled sends, webhooks, queued broadcasts with unsubscribe handling, real domain verification (Resend registration + DNS lookups, optional Cloudflare auto-publish), delivery events with auto-suppression, signatures, and group addresses are implemented and tested. Inbound mail is received via provider webhooks and threaded into the shared inbox, where you can reply and forward. Some screens still use mock data, and automations, recurring billing, and several audience features are not implemented yet — see [Known gaps](#known-gaps--roadmap).

## Features

Legend: ✅ working · 🟡 partial / uses mock data · ⛔ not implemented

| Area | Status | Notes |
|---|---|---|
| Multi-tenant workspaces | ✅ | Tenant resolved from subdomain (`IdentifyTenant`, `TenantResolver`); workspace create + switch |
| Compose & send | ✅ | Floating compose button (`c`) opens a docked panel (minimise / full-screen / discard) whose draft survives navigation; Tiptap editor, Cc/Bcc, attachments, provider selection, suppression check, signature appended |
| Scheduled sends | ✅ | `SendScheduledMessage` job runs every minute via the scheduler |
| Sent page | ✅ | `GET /sent` with status tabs, search and paging; email detail page's "Insights" panel is still mock |
| Bounced page | ✅ | `GET /bounced` lists bounced / suppressed sends; failed replies can be retried (`POST /emails/{id}/retry`) |
| Suppression list | ✅ | Enforced on compose, API, and broadcast sends; bounces and complaints are added automatically |
| Delivery events | 🟡 | `POST /api/v1/events/{driver}` handles delivered, bounced, complained, opened, clicked; bounces/complaints auto-suppress and fire `email.<type>` webhooks. The live Resend webhook still only subscribes to `email.received`; delivery-delayed events and an event timeline UI are not built |
| API keys | ✅ | `md_…` Bearer keys with enforced permissions (Sending access = `POST /emails` only, else 403), single-domain scope, verified-sender check, per-key rate limit (`MAILDESK_API_RATE_LIMIT`/min, 429 + `Retry-After`/`X-RateLimit-*`), optional expiry and one-click rotation; "Export" button not implemented |
| Public REST API | ✅ | `/api/v1` — emails, inbox threads, domains |
| Webhooks | ✅ | Queued per endpoint with retries (5 attempts, 30 s → 30 min backoff) and per-attempt delivery history; SSRF-protected (https only outside local, private/internal addresses blocked on save and at delivery after DNS resolution, no redirects, 5 s timeout); HMAC-SHA256 signed (`X-MailDesk-Signature`, plus timestamped `X-MailDesk-Signature-V2`); test event button and owner/admin secret rotation |
| Mailbox users | ✅ | Create / update / delete mailboxes per workspace; optional per-mailbox signature override |
| Signatures | ✅ | Workspace signature (Settings → Signature) applied to compose, replies, forwards, API sends and broadcasts, each toggleable; per-mailbox override (`SignatureService`) |
| Group addresses | ✅ | Groups page (CRUD + members); mail to a group address fans out to members (`FanOutGroupMessage`, `GroupAddressService`); groups usable as broadcast audiences |
| Templates | 🟡 | CRUD works; "Send test" and "Publish" are front-end only |
| Audience (contacts, segments) | 🟡 | Add / delete / suppress contacts; editing contacts, properties, segments, topics not implemented |
| Broadcasts | 🟡 | Queued, throttled sending (`SendBroadcast` / `SendBroadcastRecipient`, `MAILDESK_BROADCAST_PER_SECOND`) to all contacts, a segment or a group; per-recipient rows with skipped (suppressed / unsubscribed) recipients and stats; signed unsubscribe links, branded unsubscribe page and `List-Unsubscribe` / one-click headers. No click tracking, scheduling, or draft edit screen |
| Inbound email | ✅ | `POST /api/v1/inbound/{resend,generic}` — signature-verified, routed to the right tenant/mailbox, threaded, deduped, fires `email.received`; attachments shown in the thread with download links |
| Shared inbox | 🟡 | Threads received mail with replies; read state persisted; reply (hidden by default, opens with the Reply button or `r`, with Cc/Bcc, attachments, retry) and forward (`f`) are real. Bodies render in a sandboxed iframe after HTMLPurifier cleaning. Star, archive and delete are still UI-only |
| Domains | 🟡 | Registered with Resend for real DKIM/records; verified via DNS-over-HTTPS (1.1.1.1) with system-resolver fallback; hourly/daily recheck (`domains:recheck`); optional Cloudflare connect + auto-publish of records. Shows "verified" even while Resend reports `partially_verified` |
| Onboarding | ✅ | Get Started modal whose steps (domain, API key, first send, webhook) are driven by real server state |
| Automations | ⛔ | List / view / edit / delete only; create page has no store route, steps are not saved, nothing executes steps |
| Metrics & logs | ✅ | Built from real message data and provider events: date-range/domain/tag filters, delivered (delivery events only), bounce and complaint rates, opens/clicks (shown as "Not tracked" until the provider sends such events) and per-broadcast numbers. App log rotates daily (`LOG_DAILY_DAYS`, default 14); failed sends and webhook deliveries are logged |
| Settings (usage, billing, SMTP, signature, unsubscribe page, documents) | 🟡 | Signature, unsubscribe page and per-workspace SMTP server (owners/admins; password stored encrypted and write-only) are saved; usage falls back to mock numbers; plan upgrades are paid through Monipay (NGN hosted checkout, verified server-side — see [Payments](#payments-monipay)); card form, invoices and cancel are still mock |
| Notifications bell | 🟡 | Mock data (`useNotifications.js`) |
| Platform admin | 🟡 | Accounts, subscriptions, providers, subdomains, plans; plan list, provider presets and health read from `adminMock.js` |

## Tech stack

- **Backend:** PHP 8.3, Laravel 13 (`laravel/framework ^13.17`), Laravel Sanctum 4, Inertia Laravel 2, Ziggy 2, `resend/resend-php` 1.13
- **Frontend:** Vue 3, Inertia Vue 3, Vite 8, Tailwind CSS (with forms + typography plugins), Tiptap 3 editor, Lucide icons, Axios
- **Auth scaffolding:** Laravel Breeze (Inertia + Vue)
- **Data:** SQLite by default (database-backed sessions, cache, and queue)
- **Dev tooling:** PHPUnit 12, Laravel Pint, Pail, Boost, Collision
- **Local hosting:** Laravel Herd (Valet-compatible), wildcard subdomains on `maildesk.test`

## Local setup

Prerequisites: PHP 8.3+, Composer, Node 22+, and [Laravel Herd](https://herd.laravel.com).

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # seeds platform data, a demo tenant workspace, and test@example.com
npm install
npm run dev                     # or: npm run build
herd link maildesk              # serves http://maildesk.test (+ *.maildesk.test)
```

Run a queue worker and the scheduler (needed for webhooks and scheduled sends):

```bash
php artisan queue:work
php artisan schedule:work
```

`composer dev` runs the app's combined dev process (`php artisan dev`).

### Environment variables

| Key | Purpose |
|---|---|
| `APP_URL` | `http://maildesk.test` |
| `MAILDESK_PROVIDER` | Default outbound provider: `resend` or `smtp` |
| `RESEND_API_KEY` | Resend API key (`re_…`); also used to fetch inbound email bodies |
| `RESEND_WEBHOOK_SECRET` | Signing secret (`whsec_…`) of the Resend webhook that posts `email.received` and delivery events |
| `MAILDESK_INBOUND_SECRET` | Shared secret for the generic inbound endpoint (`X-MailDesk-Inbound-Secret` header) |
| `MAILDESK_INBOUND_LOG_CHANNEL` | Optional log channel for inbound events (defaults to the main log) |
| `MAILDESK_INBOUND_TOLERANCE` | Webhook signature timestamp tolerance in seconds (default 300) |
| `MAILDESK_INBOUND_DISK` | Filesystem disk for inbound attachments (default `local`) |
| `RESEND_API_URL` | Resend API base URL (default `https://api.resend.com`) |
| `MAILDESK_DOMAINS_REGISTER` | Register new domains with the provider (default true) |
| `MAILDESK_DNS_RESOLVER`, `MAILDESK_DNS_DOH_URL` | DNS lookup mode for verification: `doh` (default, `https://1.1.1.1/dns-query`) or the system resolver |
| `MAILDESK_DNS_AUTO_PUBLISH` | Auto-publish required records to a connected Cloudflare zone (default true) |
| `MAILDESK_BROADCAST_PER_SECOND` | Broadcast send throttle (default 2 per second) |
| `MAILDESK_FAKE_SEND` | When true, sends use the in-memory `ArrayProvider`. Defaults to true when `APP_ENV` is `local` or `testing` |
| `MAILDESK_SMTP_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_ENCRYPTION` | Fallback SMTP settings for `SmtpProvider` (a workspace's own Settings → SMTP server wins, then an admin SMTP provider's config, then these); Laravel's `MAIL_*` mailer is not used for provider sends |
| `MAILDESK_BASE_DOMAIN` | Base domain for tenant subdomains (`maildesk.test`) |
| `MAILDESK_CENTRAL_DOMAINS` | Comma-separated domains that serve the shared app shell |
| `MAILDESK_API_RATE_LIMIT` | API requests per minute per key (default 120) |
| `SESSION_DOMAIN` | `.maildesk.test` so sessions are shared across subdomains |
| `SANCTUM_STATEFUL_DOMAINS` | `maildesk.test,*.maildesk.test` |

## API

All endpoints live under `/api/v1` and require `Authorization: Bearer md_…` (create keys under **API Keys**).

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/emails` | List sent/received emails |
| POST | `/api/v1/emails` | Send an email |
| GET | `/api/v1/emails/{uuid}` | Get one email |
| GET | `/api/v1/inbox/threads` | List inbox threads |
| GET | `/api/v1/inbox/threads/{thread}` | Get a thread with messages |
| POST | `/api/v1/inbound/{driver}` | Provider webhook for received mail (`resend` or `generic`); no API key, signature-verified |
| POST | `/api/v1/events/{driver}` | Provider webhook for delivery events (delivered, bounced, complained, opened, clicked); no API key, signature-verified |
| GET | `/api/v1/domains` | List domains |
| POST | `/api/v1/domains` | Add a domain |

```bash
curl -X POST http://maildesk.test/api/v1/emails \
  -H "Authorization: Bearer md_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "hello@your-verified-domain.com",
    "to": ["you@example.com"],
    "subject": "Hello from MailDesk",
    "text": "It works"
  }'
```

## Project structure

```
app/
  Http/Controllers/        Web controllers (one per dashboard section) + AdminController
  Http/Controllers/Api/V1/ Public API (emails, inbox, domains)
  Http/Middleware/         IdentifyTenant, AuthenticateApiKey, EnsurePlatformAdmin, HandleInertiaRequests
  Jobs/                    DispatchWebhook, SendScheduledMessage, SendBroadcast, SendBroadcastRecipient,
                           FanOutGroupMessage
  Console/Commands/        RecheckDomains (domains:recheck)
  Mail/                    Provider layer: MailManager, Contracts/MailProvider, DTOs,
                           Providers/{Resend,Smtp,Array,Unsupported}Provider
  Models/                  26 Eloquent models (Organization, Mailbox, Message, Thread, Domain,
                           Contact, Segment, Broadcast, BroadcastRecipient, GroupAddress, …)
  Services/                EmailService (send pipeline), InboundEmailService, BroadcastService,
                           DeliveryEventService, SignatureService, GroupAddressService, TenantResolver,
                           Domains/{DnsResolver,DomainVerifier,ResendDomainClient},
                           Dns/{CloudflareDnsProvider,DnsRecordManager}
  Support/                 CurrentOrganization, PlansCatalog
config/maildesk.php        Provider, fake-send, API, multi-tenant host, inbound, domains/DNS, broadcast config
database/                  Migrations + seeders (SaasPlatformSeeder, TenantWorkspaceSeeder)
resources/js/
  Pages/                   Inertia pages per section (Inbox, Compose, Emails, Broadcasts, Automations,
                           Templates, Audience, Domains, ApiKeys, Webhooks, Metrics, Logs, Settings, Admin/…)
  Layouts/, Components/    AppLayout, shared UI
  composables/             useNotifications, usePlatform, …
  data/                    mock.js, adminMock.js (placeholder data still used by some screens)
routes/
  web.php                  Dashboard + admin routes
  api.php                  /api/v1 routes
  console.php              Scheduler (scheduled sends every minute, domain recheck hourly + daily full pass)
tests/Feature/             PHPUnit feature tests: compose, inbox, broadcasts, domains, delivery events,
                           signatures & groups, onboarding, tenancy, admin, …
tests/js/                  Vitest tests (email frame, onboarding)
```

## Inbound email

Providers POST received mail to `POST /api/v1/inbound/{driver}`:

- **`resend`** — point a Resend webhook for `email.received` at `https://<app>/api/v1/inbound/resend` and set `RESEND_WEBHOOK_SECRET`. Svix signatures are verified (5-minute tolerance). If the webhook carries only metadata, the body is fetched from Resend's receiving API with `RESEND_API_KEY`, and attachments are downloaded via the receiving attachments API.
- **`generic`** — JSON (`from`, `to`, `cc`, `subject`, `text`, `html`, `message_id`, `in_reply_to`, `references`, `headers`, base64 `attachments`) with the `X-MailDesk-Inbound-Secret` header.

With a secret left empty, verification is skipped only in `local`/`testing`; in production the request is rejected.

Pipeline (`App\Services\InboundEmailService`):

1. **Route** — match To/Cc/Delivered-To against active mailboxes with inbox enabled (case-insensitive); otherwise fall back to the organisation owning the recipient domain (catch-all). Unroutable mail returns `202` and is not stored.
2. **Dedupe** — skip if the organisation already has the same `Message-ID` or provider message id (safe provider retries).
3. **Thread** — match `In-Reply-To`/`References` against stored `message_id_header` in the same organisation; for `Re:`/`Fwd:` without a match, fall back to same sender + normalised subject in the same mailbox within 30 days; otherwise start a new thread. Outbound mail now gets its own `Message-ID`, so customer replies land on the original conversation.
4. **Store** — `messages` row (`direction=inbound`, `status=received`), attachments on the configured disk, thread snippet/count/`last_message_at` updated and marked unread.
5. **Notify** — queue an `email.received` webhook to the organisation's subscribers.

**Responses and retries.** `201` stored, `200` duplicate or ignored event, `202` unroutable, `401` bad signature. `503` means "retry later": a missing webhook secret outside local/testing (logged as critical) or Resend's API being unreachable, rate-limited, or returning 5xx while fetching the body or attachments. Unexpected errors return `500`. Without a secret, local and testing environments accept unsigned posts for development. Every outcome is logged (never the body).

## Payments (Monipay)

Plan upgrades are paid in naira through [Monipay](https://monipay.ng/api-docs) hosted checkout (`App\Services\Billing\*`, `payments` table).

Env (leave empty to disable; the Billing tab then shows "Payments not configured"):

- `MONIPAY_PUBLIC_KEY` — `pub_test_…` / `pub_live_…`, used for `POST /transaction/initialize`.
- `MONIPAY_SECRET_KEY` — `pri_test_…` / `pri_live_…`, server-only: `GET /transaction/verify/{reference}` and the webhook HMAC. Never sent to the browser.
- `MONIPAY_WEBHOOK_SECRET` — optional; overrides the secret key for webhook signatures.
- `MONIPAY_NAIRA_PER_PLAN_PRICE_UNIT` — optional; `plans.price` is stored in whole units, so a plan without an explicit `plans.price_kobo` is charged `price × rate × 100` kobo (default rate 1). Set `price_kobo` on paid plans for exact NGN prices. Minimum charge is ₦50 (5000 kobo).

Flow: an owner/admin clicks **Pay with Monipay** → `POST /billing/monipay/initialize` creates a pending payment (`md_<ulid>` reference), initializes it (amount in kobo, `callback_url` on the tenant's own host, `webhook_url`) and redirects to the returned `authorization_url`. Monipay sends the customer back to `GET /billing/monipay/callback` (reference from `reference`/`trxref`/`ref`/`trans_id`, falling back to the reference kept in the session), which verifies with the secret key. A payment is fulfilled only if verify succeeds, `data.status` is `success`/`approved` (case-insensitive), the amount equals the kobo charged and the currency is NGN. Fulfilment activates the workspace's subscription for that plan's product (or extends it by one interval when paying again for the same plan) inside a locked transaction, so callback, webhook and retries are idempotent. Mismatched amounts are logged as critical and left for review.

Webhook: set `https://<your-app-host>/api/v1/payments/monipay/webhook` in the Monipay dashboard (it is also sent per transaction as `webhook_url`). The `x-monipay-signature` header must be the hex HMAC-SHA512 of the raw body (optional `sha512=` prefix). Bad signature → `401`; missing secret outside local/testing → `503` + critical log; events other than `charge.success` → `200 ignored`. `charge.success` is still re-verified via the API before fulfilling (defence in depth); if verify is unreachable the webhook answers `503` so Monipay retries.

Notes: test and live share the same API host — the mode comes from the `pub_test_`/`pri_test_` keys. Monipay publishes no SDKs, test cards, refunds or subscription API, so renewals are manual (a new checkout each period). The inline popup is not used: v1 `MonipayPop` is gone and v2 `new Monipay().checkout()` cannot take our server-generated reference, so MailDesk uses the hosted redirect only.

## Testing

```bash
php artisan test     # 205 tests, 1304 assertions — all passing
npm test             # 9 Vitest tests
npm run build        # production frontend build
```

## Known gaps / roadmap

1. **Inbox actions** — persist star/archive/delete (currently UI-only); raw MIME parsing for SMTP relays. Confirm the Resend receiving API response shape against a live account.
2. **Domain status accuracy** — reflect Resend's `partially_verified` state instead of showing "verified".
3. **Delivery events follow-ups** — subscribe the live Resend webhook to delivery events (and enable open/click tracking), handle delivery-delayed events, and add an event timeline in the UI.
4. **Broadcasts** — scheduling and a draft edit screen (opens/clicks come from provider events once tracking is enabled).
5. **Email rendering** — `<style>` blocks are stripped and remote images auto-load; template and broadcast previews still use `v-html`.
6. **Automations** — save the create wizard and its steps, add a job that executes steps, and an events API to trigger them.
7. **SMTP** — workspaces can send through their own SMTP server (Settings → SMTP) with headers, Message-ID and provider id preserved; SMTP has no delivery/bounce event feed, so SMTP-sent mail stays at `sent` unless something posts events for it. There is no inbound SMTP relay server, and the compose/reply send buttons still require an active platform provider even when workspace SMTP is on.
8. **Remove mock data** — replace `resources/js/data/mock.js` / `adminMock.js` usage (notifications, plans, admin plans / provider presets / health, usage fallback, email insights) with real backend data.
9. **Webhook follow-ups** — auto-disable endpoints after repeated failures, prune old delivery rows, manual "redeliver" button.
10. **Billing** — one-off Monipay plan payments work (Settings → Billing); still missing: automatic renewals (Monipay documents no recurring API, so customers renew with a new checkout each period), expiry/downgrade when `current_period_ends_at` passes, plan-limit enforcement, admin editing of `plans.price_kobo`, refunds, real payment-method and invoice screens.
11. **Unfinished UI actions** — template test send and publish, edit contact/property/segment/topic, API key export, support chat.
12. **Production readiness** — Postgres/MySQL config, queue worker + scheduler process setup, deployment docs.

## License

MIT (Laravel skeleton default).
