# MailDesk Mobile Architecture

## Overview

MailDesk is a Laravel 13 + Vue 3/Inertia multi-tenant shared-inbox / email SaaS. This document describes the architecture for the React Native (Expo) mobile companion app.

## Data Models

### Core Entities

#### User
- `id`, `name`, `email`, `password` (hashed), `is_platform_admin`, `preferences`
- Belongs to many Organizations (via pivot with `role`: owner/admin/member)
- Has many Mailboxes

#### Organization (Workspace/Tenant)
- `id`, `name`, `slug`, `status`, `plan`, `product`, `subdomain`, `custom_domain`, `settings`
- Has many Users, Mailboxes, Threads, Messages, Contacts, Templates, Broadcasts, Domains, ApiKeys

#### Thread (Email Conversation)
- `id`, `organization_id`, `mailbox_id`, `subject`, `snippet`, `ai` (JSON), `last_message_at`
- Flags: `is_read`, `is_archived`, `is_spam`, `is_trashed`, `trashed_at`
- Has many Messages

#### Message
- `id`, `uuid`, `organization_id`, `thread_id`, `mailbox_id`
- `direction`: inbound/outbound
- `status`: queued/sent/delivered/failed/bounced/complained/suppressed
- `from_email`, `from_name`, `to` (array), `cc`, `bcc`, `reply_to`
- `subject`, `text_body`, `html_body`, `headers`, `meta`
- `sent_at`, `scheduled_at`, `received_at`
- Has many Attachments

#### Mailbox
- `id`, `organization_id`, `user_id`, `domain_id`, `email`, `display_name`
- `type`, `role`, `status`, `inbox`, `transactional`, `marketing`, `signature`

#### Contact
- `id`, `organization_id`, `email`, `first_name`, `last_name`, `company`, `meta`, `unsubscribed_at`
- Belongs to many Segments

### Supporting Entities
- Template, Broadcast, Domain, ApiKey, Suppression, Segment, Automation

## Authentication

### Web App Auth
- Laravel Breeze (session-based) with email/password login
- No 2FA currently implemented
- `LoginRequest` validates credentials and checks:
  - Account suspended/closed status
  - Mailbox login permissions
  - Workspace membership for tenant hosts

### Mobile App Auth Strategy

**Use Laravel Sanctum Personal Access Tokens:**

1. **Login Endpoint** (NEW): `POST /api/mobile/auth/login`
   - Accepts `email`, `password`
   - Validates using existing `LoginRequest` logic
   - Returns Sanctum personal access token + user data + workspaces

2. **Logout Endpoint** (NEW): `POST /api/mobile/auth/logout`
   - Revokes current token

3. **Me Endpoint** (NEW): `GET /api/mobile/auth/me`
   - Returns authenticated user, workspaces, current workspace

### Token Storage
- Use `expo-secure-store` for secure token persistence
- Never hardcode secrets; API URL from config (`EXPO_PUBLIC_API_URL`)
- Default: `https://maildesk.ng`

## API Endpoints (Mobile)

### Existing API Routes (`/api/v1/*`)
These use API key auth (`AuthenticateApiKey` middleware), not user auth. **Not suitable for mobile.**

### New Mobile API Routes (to be created)

All under `/api/mobile/*` with `auth:sanctum` middleware:

#### Auth
- `POST /api/mobile/auth/login` - Get token
- `POST /api/mobile/auth/logout` - Revoke token
- `GET /api/mobile/auth/me` - Current user + workspaces

#### Workspaces
- `GET /api/mobile/workspaces` - List user's workspaces
- `POST /api/mobile/workspaces/{id}/switch` - Set current workspace

#### Inbox
- `GET /api/mobile/inbox` - Thread list (paginated)
- `GET /api/mobile/inbox/{thread}` - Single thread with messages
- `PATCH /api/mobile/inbox/{thread}/read` - Mark read/unread
- `POST /api/mobile/inbox/{thread}/archive` - Toggle archive
- `POST /api/mobile/inbox/{thread}/spam` - Toggle spam
- `POST /api/mobile/inbox/{thread}/trash` - Toggle trash
- `POST /api/mobile/inbox/{thread}/reply` - Send reply

#### Compose
- `POST /api/mobile/emails` - Send new email

#### Contacts
- `GET /api/mobile/contacts` - List contacts (paginated)
- `POST /api/mobile/contacts` - Create contact

#### Profile
- `GET /api/mobile/profile` - Current user profile
- `PATCH /api/mobile/profile` - Update profile

## Workspace Scoping

All data is scoped to the current workspace (organization):
1. User must be a member of the organization
2. `WorkspaceAccess` service controls feature abilities:
   - `isTeam()`: owner/admin gets full access
   - `mailboxFor()`: gets user's linked mailbox
   - `scopeMailData()`: restricts queries to user's mailbox if scoped
   - `abilities()`: inbox/transactional/marketing/manage/mail flags

## Mobile App Structure

```
mobile/
├── app/                    # Expo Router pages
│   ├── (auth)/             # Unauthenticated screens
│   │   ├── login.tsx
│   │   └── _layout.tsx
│   ├── (app)/              # Authenticated screens
│   │   ├── _layout.tsx
│   │   ├── inbox/
│   │   │   ├── index.tsx   # Thread list
│   │   │   └── [id].tsx    # Thread detail
│   │   ├── compose.tsx
│   │   ├── contacts/
│   │   │   └── index.tsx
│   │   ├── workspace.tsx   # Workspace switcher
│   │   └── settings.tsx
│   ├── index.tsx           # Root redirect
│   └── _layout.tsx         # Root layout
├── src/
│   ├── api/                # API client
│   │   ├── client.ts       # Axios instance
│   │   ├── auth.ts         # Auth endpoints
│   │   ├── inbox.ts        # Inbox endpoints
│   │   ├── contacts.ts     # Contacts endpoints
│   │   └── types.ts        # TypeScript types
│   ├── components/         # Shared components
│   │   ├── ThreadList.tsx
│   │   ├── ThreadItem.tsx
│   │   ├── MessageView.tsx
│   │   ├── EmailWebView.tsx
│   │   └── ...
│   ├── contexts/           # React contexts
│   │   ├── AuthContext.tsx
│   │   └── WorkspaceContext.tsx
│   ├── hooks/              # Custom hooks
│   │   ├── useAuth.ts
│   │   └── useWorkspace.ts
│   └── utils/              # Utilities
│       ├── storage.ts      # Secure storage wrapper
│       └── config.ts       # App configuration
├── app.config.ts           # Expo config
├── package.json
└── tsconfig.json
```

## Security Considerations

1. **Token Security**: Store tokens in `expo-secure-store`, never in AsyncStorage
2. **API Security**: All mobile endpoints require `auth:sanctum` middleware
3. **Workspace Scoping**: Backend enforces workspace membership for all data access
4. **HTML Rendering**: Use sandboxed WebView with disabled JavaScript for email HTML
5. **No Secrets in Code**: API URL via environment/config only

## Backend Changes Required

1. **Add `HasApiTokens` trait to User model** for Sanctum token support
2. **Create Mobile API Controller** with auth endpoints
3. **Create Mobile Inbox API Controller** reusing existing services
4. **Create Mobile Contacts API Controller** reusing existing services
5. **Add routes in `routes/api.php`** under `api/mobile` prefix
6. **Feature tests** for all new endpoints
