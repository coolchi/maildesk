# MailDesk

MailDesk is a multi-tenant business email platform. Each customer organisation gets its own workspace (on its own subdomain, e.g. `acme.maildesk.test`) where it can send transactional and marketing email, read a shared inbox, manage its audience, verify sending domains, and integrate through a REST API and webhooks. A platform admin area manages customer accounts, plans, subscriptions, mail providers, and subdomains.

Outbound mail goes through a pluggable provider layer: **Resend** by default, **SMTP** as an alternative, and a fake in-memory provider for local development and tests.

> Status: early / pilot. The core send path, tenancy, API keys, suppressions, scheduled sends, and webhooks are implemented and tested. Inbound mail is received via provider webhooks and threaded into the shared inbox. Several screens still use mock data, and real DNS verification, automations, and billing are not implemented yet — see [Known gaps](#known-gaps--roadmap).

## Features

Legend: ✅ working · 🟡 partial / uses mock data · ⛔ not implemented

| Area | Status | Notes |
|---|---|---|
| Multi-tenant workspaces | ✅ | Tenant resolved from subdomain (`IdentifyTenant`, `TenantResolver`); workspace create + switch |
| Compose & send | ✅ | Rich-text (Tiptap) editor, attachments, provider selection, suppression check |
| Scheduled sends | ✅ | `SendScheduledMessage` job runs every minute via the scheduler |
| Sent emails log | ✅ | List + detail; the "Insights" panel on the detail page is mock |
| Suppression list | ✅ | Enforced on compose, API, and broadcast sends |
| API keys | ✅ | `md_…` Bearer keys, rate-limited; "Export" button not implemented |
| Public REST API | ✅ | `/api/v1` — emails, inbox threads, domains |
| Webhooks | 🟡 | Queued, HMAC-SHA256 signed (`X-MailDesk-Signature`); no retry/backoff policy |
| Mailbox users | ✅ | Create / update / delete mailboxes per workspace |
| Templates | 🟡 | CRUD works; "Send test" is mock |
| Audience (contacts, segments) | 🟡 | Add / delete / suppress contacts; editing contacts, properties, segments, topics not implemented |
| Broadcasts | 🟡 | Sends to all contacts or a segment, skips suppressed; runs synchronously in the request, no unsubscribe link/header |
| Inbound email | ✅ | `POST /api/v1/inbound/{resend,generic}` — signature-verified, routed to the right tenant/mailbox, threaded, deduped, fires `email.received` |
| Shared inbox | 🟡 | Received mail and replies to sent mail thread together; read state is persisted; replying from the inbox, star, archive and delete are still UI-only |
| Domains | 🟡 | Add / delete; DNS records are shown, but **verification is faked** (always passes) and DKIM value is a placeholder |
| Automations | ⛔ | List / view / edit / delete only; no create route and nothing executes steps |
| Metrics & logs | 🟡 | Pages exist; some widgets fall back to mock data |
| Settings (usage, billing, SMTP, unsubscribe page, documents) | 🟡 | Unsubscribe page and SMTP settings saved; billing actions are mock, no payment provider |
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
| `RESEND_WEBHOOK_SECRET` | Signing secret (`whsec_…`) of the Resend webhook that posts `email.received` events |
| `MAILDESK_INBOUND_SECRET` | Shared secret for the generic inbound endpoint (`X-MailDesk-Inbound-Secret` header) |
| `MAILDESK_INBOUND_LOG_CHANNEL` | Optional log channel for inbound events (defaults to the main log) |
| `MAILDESK_FAKE_SEND` | When true, sends use the in-memory `ArrayProvider`. Defaults to true when `APP_ENV` is `local` or `testing` |
| `MAILDESK_SMTP_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_ENCRYPTION` | SMTP provider settings |
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
  Jobs/                    DispatchWebhook, SendScheduledMessage
  Mail/                    Provider layer: MailManager, Contracts/MailProvider, DTOs,
                           Providers/{Resend,Smtp,Array,Unsupported}Provider
  Models/                  22 Eloquent models (Organization, Mailbox, Message, Thread, Domain,
                           Contact, Segment, Broadcast, Automation, Template, Webhook, Plan, …)
  Services/                EmailService (send pipeline), TenantResolver
  Support/                 CurrentOrganization, PlansCatalog
config/maildesk.php        Provider, fake-send, API, and multi-tenant host config
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
  console.php              Scheduler (scheduled sends every minute)
tests/Feature/             78 tests: compose, suppressions, scheduled sends, webhooks, tenancy, admin, …
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
php artisan test     # 78 tests, 423 assertions — all passing
npm run build        # production frontend build
```

## Known gaps / roadmap

1. **Inbound email follow-ups** — send replies from the shared inbox (with `In-Reply-To`/`References`), persist star/archive/delete, raw MIME parsing for SMTP relays, and attachment download links. Confirm the Resend receiving API response shape against a live account.
2. **Real domain verification** — register domains with the provider, store real DKIM records, and check SPF/DKIM/DMARC via DNS instead of always passing.
3. **Broadcasts** — send via queued jobs, add unsubscribe links and `List-Unsubscribe` headers, and record delivery counts.
4. **Automations** — add a create route/wizard and a job that executes automation steps.
5. **Remove mock data** — replace `resources/js/data/mock.js` / `adminMock.js` usage (notifications, plans, admin provider health, usage fallback, email insights, broadcast preview) with real backend data.
6. **Delivery events** — process provider webhooks (delivered, bounced, complained, opened) to drive metrics, logs, and automatic suppressions.
7. **Webhook reliability** — retries with backoff and delivery history in the UI.
8. **Billing** — integrate a payment provider and enforce plan limits.
9. **Unfinished UI actions** — template test send, edit contact/property/segment/topic, API key export, support chat.
10. **Production readiness** — Postgres/MySQL config, queue worker + scheduler process setup, deployment docs.

## License

MIT (Laravel skeleton default).
