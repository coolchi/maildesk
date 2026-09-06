# MailDesk

Business mail SaaS — Laravel + Inertia + Vue. Resend-first, multi-provider ready, with a send/receive API and inbox UI.

## Local URL

Linked with Laravel Herd (Valet-compatible):

**http://maildesk.test**

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run dev
```

Add your Resend key:

```env
RESEND_API_KEY=re_xxx
MAILDESK_PROVIDER=resend
APP_URL=http://maildesk.test
```

If the site is not linked yet:

```bash
herd link maildesk
```

## Stack

- Laravel 13 + Breeze (Inertia Vue)
- Provider adapters: Resend (default), SMTP
- Public API at `/api/v1/*` (Bearer `md_…` keys)
- Dashboard: Inbox, Compose, Domains, API Keys, Docs

## API quickstart

1. Register at http://maildesk.test/register  
2. Create an API key under **API Keys**  
3. Send:

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
# maildesk
