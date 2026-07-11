# Notifications & Email

## Status (updated 2026-07-11)

| Item | State |
|---|---|
| Production transport | **Microsoft Graph `sendMail`** (OAuth2 client-credentials) — chosen |
| SMTP AUTH | **Ruled out** — LDC M365 tenant policy disables it (verified empirically) |
| Power Automate | **Retired. It will not be used by ATMS.** |
| Transport abstraction | `Fake` (development/testing) / `Graph` (production) |
| Built notifications | Account activation, password reset (currently via `AccountEmailTransport`, `Fake` in dev) |
| Future notifications | MR Created, WO Assigned, WO Completed — outside the current Phase 1 scope |
| Implementation | In progress — design/provisioning phase |

---

## Architecture

```
ATMS (Laravel)                 Microsoft Graph            Recipient
  ┌──────────┐                 ┌──────────────┐           ┌──────────┐
  │ Event    │ ── queued ────► │ POST         │ ── email ─►│ Inbox    │
  │ fires    │    job          │ /users/{mb}/ │           └──────────┘
  │          │                 │   sendMail   │
  └──────────┘                 └──────────────┘
     │                                ▲
     │  app-only OAuth2 token          │
     └──── login.microsoftonline.com ──┘
```

- **ATMS owns:** when to notify, whom to notify, what data to include, token lifecycle, retry, and the audited outcome.
- **Microsoft Graph `sendMail` owns:** delivery through the corporate mailbox (`notification@ldc.com.ly`).
- **Templates:** rendered **Laravel-side** because Graph `sendMail` takes the full message body (subject + HTML/text).
- **Transport is a swappable abstraction:** `Fake` (development/testing, no external calls) and `Graph` (production). Power Automate is retired and is not an available production path.
- **Throttling / concurrency (important):** Exchange Online limits concurrent app access per mailbox (~3–4). Blasting parallel `sendMail` calls to the one mailbox triggers `429 ApplicationThrottled` (and gateway `504`s). Dispatch MUST be **serialized** through the queue (limited per-mailbox concurrency) with **retry-on-429 honouring `Retry-After`**. Verified empirically 2026-07-04.

---

## Why Graph `sendMail`

### SMTP — ruled out
LDC's M365 tenant has **SMTP AUTH (Basic Auth) disabled**. Verified empirically 2026-07-04:

```
535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant.
Visit https://aka.ms/smtp_auth_disabled
```

The credentials are valid (the server recognised `notification@ldc.com.ly` and rejected on policy, not on password). Microsoft disables Basic-Auth SMTP tenant-wide by default. SMTP is therefore **not viable unless LDC IT re-enables SMTP AUTH for the mailbox** — which we are not pursuing. (OAuth2-over-SMTP / XOAUTH2 is also not a supported M365 path for app-only; the supported OAuth2 app-only path is Graph `sendMail`.)

### Power Automate — retired
Power Automate is no longer part of the ATMS architecture and will not be used as a fallback. Microsoft Graph replaces it for production email delivery, keeping authentication, templates, queuing, retry handling, and delivery auditing inside Laravel.

### Graph `sendMail` — chosen
- Pure OAuth2 (client-credentials → access token), **not SMTP**, so `SmtpClientAuthenticationDisabled` does not apply.
- Sends **from `notification@ldc.com.ly`** via `POST https://graph.microsoft.com/v1.0/users/{mailbox}/sendMail`.
- Uses the OAuth2 client-credentials flow against `login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with scope `https://graph.microsoft.com/.default`.
- Only IT ask: an Azure App Registration with `Mail.Send` (Application). No PA flow to build.

---

## Azure provisioning (LDC IT / Entra ID)

> **Important:** This is a **separate Entra ID app registration** from the `LDC_ERP_*` (Dynamics 365 Business Central) app. Use distinct credentials; do not reuse the ERP app.

1. **Register an application** in Entra ID (Azure AD) for ATMS notification email → yields **Client (Application) ID** and **Tenant (Directory) ID**.
2. **Add client credentials** — a client **secret** (recommended to start) or a **certificate**. See [Secret vs Certificate](#secret-vs-certificate-expiry--renewal) below.
3. **API permissions → Microsoft Graph → Application permissions → add `Mail.Send`** (Application, *not* Delegated) → **Grant admin consent** tenant-wide.
4. **(Recommended) Restrict the app to only `notification@ldc.com.ly`** so it cannot impersonate arbitrary senders, via an Application Access Policy (Exchange Online PowerShell):
   ```powershell
   New-DistributionGroup -Name "ATMS-Notification-Senders" -Members notification@ldc.com.ly
   New-ApplicationAccessPolicy -AppId "<CLIENT_ID>" -PolicyScopeGroupId "ATMS-Notification-Senders" -AccessRight RestrictAccess -Description "Restrict ATMS app to the notification mailbox"
   ```
5. Confirm **outbound HTTPS** from the ATMS server to `login.microsoftonline.com` and `graph.microsoft.com` is allowed.

### Application configuration (`backend/.env`)

```dotenv
ACCOUNT_EMAIL_TRANSPORT=fake     # keep "fake" until the Graph transport is built and the probe passes; then "graph"
GRAPH_TENANT_ID=                 # Directory (tenant) ID
GRAPH_CLIENT_ID=                 # App (client) ID
GRAPH_CLIENT_SECRET=             # secret value (or use GRAPH_CERT_PATH for a certificate)
GRAPH_MAILBOX=notification@ldc.com.ly
```

The container receives these via `compose.yaml`; after changing them, run `docker compose up -d` (compose re-injects env on `up`, not on `restart`).

---

## Secret vs Certificate (expiry & renewal)

### Recommendation: start with a client secret
- Matches the existing client-credentials code (which uses `client_secret`); **zero extra code** to run the probe.
- Switching to a certificate later is **trivial on Azure** (just add the cert; the secret can coexist or be removed), so there is no lock-in.
- Use a certificate later only if LDC policy requires it or you want longer validity / stronger security.

### Client secret
- **Expiry:** up to **24 months** (subject to LDC tenant policy). Record the exact expiry date Azure displays at creation.
- **Renewal:** ~2 weeks before expiry → generate a new secret in Azure → update `GRAPH_CLIENT_SECRET` in `backend/.env` → `docker compose up -d` → re-run the sendMail probe to confirm → delete the old secret in Azure.
- **If it lapses:** token acquisition fails (`AADSTS7000215: Invalid client secret provided`); notifications stop until rotated. **No data loss** — only a delivery gap.

### Certificate (optional, stronger / longer-lived)
- Self-signed is fine; Azure needs only the **public key** (`.cer`). Keep the **private key** (`.pem`/`.key`) on the server in a gitignored location, bind-mounted into the container; reference via `GRAPH_CERT_PATH`.
- **Validity:** typically **1–3 years** (longer than secrets).
- **Requires code:** the client-credentials flow must send a **signed client-assertion JWT** (e.g. via `firebase/php-jwt`) instead of a secret. This code is added only if certificates are adopted.
- **Renewal (zero-downtime overlap):** generate a new key pair → upload the **new** public cert to Azure (it coexists with the old) → place the new private key on the server, update config → restart → confirm via probe → remove the old cert from Azure.

### Operational rule (applies to either)
**The #1 operational risk is unnoticed expiry** — notifications silently fail when the credential lapses. At creation, set a calendar reminder for **~2 weeks before expiry** (secret) or **~1 month before** (certificate).

---

## Notification triggers

### Built (current)
| Trigger | Recipient | Notes |
|---|---|---|
| Account activation (one-time link) | The new user | `UserActivationNotification`; via `AccountEmailTransport` (`Fake` in development, Graph in production). |
| Password reset (one-time link) | The requesting user | `PasswordResetNotification`; same path. |

### Future operational notifications — outside current Phase 1
| Trigger | To | Cc | Template heading |
|---|---|---|---|
| MR Created | All active Maintenance Managers | All active Administrators | "New Maintenance Request" |
| WO Assigned / Reassigned | The new assignee | The action taker (who assigned/reassigned) | "Work Order Assigned" (first) / "Work Order Reassigned" (subsequent) |
| WO Completed | All active Maintenance Managers | The user who completed the WO | "Work Order Completed" |

- Greeting addresses the **To** recipient only (Cc see the same message).
- From-name "ATMS Notifications"; **no Reply-To** (footer carries the "do not reply" notice).
- All sends go through the shared `resources/views/emails/atms-notification.blade.php` template (amber `#d97706` accent, navy `#21274b` header, no logo).
- **Demo/dev caveat:** the demo DB uses faker emails, so role-based routing yields undeliverable addresses — test sends must use a hardcoded real recipient (`rawand.hawez@inova.krd`). Real routing works only in production with real user emails.

### Superseded / out of scope
- The earlier Power Automate webhook design is retired and must not be implemented or configured.
- **SM Order** notifications (`sm_order_submitted/approved/rejected`) remain **Phase 3** (Store Management is not built).

---

## Security

- Credentials live only in `backend/.env` (gitignored) or a secure secret store — never in code, commits, or logs.
- Activation/reset links carry one-time tokens. Graph inherently receives the action URL (required to render the link) — this is accepted by design; tokens are hashed server-side (`token_lookup`), single-use, and expiry-enforced.
- Every notification dispatch is recorded in the audit log.
- Secrets, plaintext tokens, and complete reset URLs must not be written to application logs.

---

## Resolved decisions (2026-07-04)
1. **WO Completed routing:** To = all active Maintenance Managers; Cc = the user who completed the WO.
2. **WO reassignment:** notify on **any assignee change**; Cc = the action taker.
3. **Activation + reset transport:** Microsoft Graph `sendMail` is the production implementation behind `AccountEmailTransport`; the fake implementation remains for development and automated tests.
4. **From-name / Reply-To:** From-name "ATMS Notifications"; **no Reply-To** (footer "do not reply" text only).
5. **Branding:** keep amber `#d97706` accent + navy `#21274b` header; **no logo** (text header, consistent with other apps). Base HTML provided by the client, adapted into `resources/views/emails/atms-notification.blade.php`.

---

## Pre-release / go-live checklist (email)

> Must be complete before client release. Carry these into the master go-live checklist when one is created.

- [ ] **Frontend base URL not final.** Email CTA links currently use the temporary `https://atms.inova.krd`. This **MUST** be updated to the officially-provided **LDC subdomain** before client release. Make the base URL a **config value** (e.g. `APP_FRONTEND_URL`), not hardcoded, so it can be switched without code changes. (Broader impact beyond email: Caddy `APP_HOST`, Sanctum `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS`, CORS — track separately.)
- [ ] **Real user email addresses.** Production users must have real, deliverable emails; the demo DB uses faker addresses, so role-based routing only delivers in prod.
- [ ] **Serialize dispatch + retry-on-429.** Send via the queue with limited per-mailbox concurrency and retry honouring `Retry-After` (Exchange throttles ~3–4 concurrent — see Architecture). Do **not** fan out parallel sends to the one mailbox.
- [ ] **Production credential.** Set the production `GRAPH_CLIENT_SECRET` (or certificate) in the prod env; record the expiry and rotate before it lapses.
- [ ] **Application Access Policy.** Restrict the app to `notification@ldc.com.ly` (`New-ApplicationAccessPolicy`) so it cannot send as arbitrary mailboxes.
- [ ] **Mail.Send consent.** Confirmed granted tenant-wide (2026-07-04) — re-verify in the production tenant.
- [ ] **Queue worker running** in production (notifications dispatch as queued jobs).

---

## Retired transport

Power Automate was considered during discovery but is retired. Do not provision a flow, expose Power Automate settings, or retain it as a runtime fallback. Microsoft Graph `sendMail` is the only production email transport.
