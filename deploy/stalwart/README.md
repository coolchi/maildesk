# Stalwart IMAP / POP / SMTP for MailDesk

MailDesk provisions mailboxes into [Stalwart](https://stalw.art/) so users can add
`user@domain` in Gmail, Apple Mail, or Outlook (cPanel-style hosting).

**Preferred runtime: Docker Compose on a dedicated mail VPS.**

## Docker (recommended)

Requirements on the mail host: Docker Engine + Compose plugin, static public IP,
ports **25 / 465 / 587 / 993 / 995 / 443** open, PTR for the hostname.

```bash
cd deploy/stalwart
cp .env.example .env   # optional notes only
./compose-up.sh        # or: docker compose up -d
```

| File | Purpose |
|------|---------|
| [`docker-compose.yml`](docker-compose.yml) | Official `stalwartlabs/stalwart:v1` image + volumes |
| [`compose-up.sh`](compose-up.sh) / [`compose-down.sh`](compose-down.sh) | Start / stop helpers |
| [`.env.example`](.env.example) | Notes for MailDesk `STALWART_*` wiring |

### First-time setup

1. `docker compose logs -f stalwart` — copy the temporary `admin` password.
2. Open **http://127.0.0.1:18080/admin** (from another machine:  
   `ssh -L 18080:127.0.0.1:18080 forge@mail-vps`).
3. Complete the wizard (hostname e.g. `mail.example.com`, first domain).
4. Restart: `docker compose restart` so normal listeners (IMAP/SMTP) come up.
5. Create DNS: MX → this host, plus SPF / DKIM / DMARC from the Stalwart UI.
6. Point **MailDesk** at the server (app `.env`):

On a Forge app host (nginx owns `:443`), Compose maps HTTPS → **`:8443`** and
bootstrap → **`127.0.0.1:18080`**. On a dedicated mail VPS you can change those
back to `443:443` and `127.0.0.1:8080:8080` in `docker-compose.yml`.

```env
STALWART_ENABLED=true
STALWART_URL=https://127.0.0.1:8443
STALWART_ADMIN_USER=admin@example.com
STALWART_ADMIN_PASSWORD=...
STALWART_IMAP_HOST=mail.example.com
STALWART_IMAP_PORT=993
STALWART_IMAP_ENCRYPTION=ssl
STALWART_SMTP_HOST=mail.example.com
STALWART_SMTP_PORT=465
STALWART_SMTP_ENCRYPTION=ssl
STALWART_POP_HOST=mail.example.com
STALWART_POP_PORT=995
STALWART_POP_ENCRYPTION=ssl
```

7. In MailDesk: **Settings → Mail clients → Enable** on a mailbox, then add that
   account in Gmail (IMAP 993 SSL, SMTP 465 SSL, app password from MailDesk).

### Upgrade

```bash
docker compose pull
docker compose up -d
```

Config and mail live in Docker volumes `stalwart-etc` / `stalwart-data`.

### Ports published

| Port | Use |
|------|-----|
| 25 | SMTP MX |
| 465 | SMTP submission (SSL) |
| 587 | SMTP submission (STARTTLS) |
| 993 | IMAPS |
| 995 | POP3S |
| 8443 → 443 | HTTPS admin / JMAP (avoids nginx on host 443) |
| 127.0.0.1:18080 → 8080 | Bootstrap / recovery only |

---

## Bare-metal spike (this Forge host)

An earlier binary install under `/home/forge/mail/stalwart` used high ports
because `forge` cannot bind 25/465/993. Prefer Docker on a **mail VPS** instead.

```bash
./start.sh
./status.sh
./stop.sh
```

| Path | Purpose |
|------|---------|
| `/home/forge/mail/stalwart/bin/stalwart` | Binary |
| `/home/forge/mail/stalwart/etc/` | `config.json`, `admin.env` |
| `/home/forge/mail/stalwart/data/` | Store |

---

## Gmail app checklist

1. Settings → Mail clients → copy IMAP/SMTP + enable mailbox.
2. Gmail → Add account → Other → IMAP.
3. Username = full address; password = **app password** (not web login).
4. IMAP **993 SSL**; SMTP **465 SSL**.
