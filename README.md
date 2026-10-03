# AlexiaSoft Ops

Internal operations portal for AlexiaSoft products: MintERP (`MINTERP`), MintPOS (`MINTPOS`), and MintHRM (`MINTHRM`).

**Status: authentication, account-management and registry MVP.** Public registration is disabled. `AdminSeeder` creates the first Admin from environment-provided credentials; Admins create Admin/User accounts directly and set a temporary password that must be changed at first login. No invitation email is sent. MFA is intentionally out of scope for this MVP. Customers, products and per-product instances are managed at `/admin`; each instance gets an immutable UUIDv7 reference. The Admin plan page at `/admin/plans` is product-scoped: MintERP, MintPOS and MintHRM each have product-scoped Core/Business versions with the same default user/branch/warehouse quotas (Core 5/1/2; Business 15/3/6). MintPOS/MintHRM have no module or Production add-ons. Admins select a product-specific plan and optional quota overrides for each Subscription instance, or License without Subscription quotas. Account, registry, plan and per-instance entitlement changes are audited at `/admin/audit`. MintERP Business Trial issuance is available to Admin on an unassigned Instance: 30 days from handover, normally once per Customer+Product, with audited exceptions on another Instance. Admins can later opt in to Production during an active Trial without extending it, or explicitly convert a Trial to a paid Core/Business plan after reviewing the contract and choosing a future expiry. These Admin actions require reasons and audit, not an extra confirmation checkbox. Trial conversion now also requires a confirmed first-payment record with private evidence. Manual renewal tracking is implemented at `/admin/renewals`: Ops stores externally verified receipts, paid service periods and explicit 15-day renewal grace separately from access expiry, with audited confirmation/application and manual delivery. It does not issue accounting documents or process payments; the external accounting system remains authoritative. Deploy the renewal migration before using these pages; existing customer expiry is not extended automatically. Browser/ERP UAT, MySQL concurrency and backup/restore gates remain open. See [`docs/SUBSCRIPTION_RENEWAL_MVP.md`](docs/SUBSCRIPTION_RENEWAL_MVP.md) for the checklist and rollout steps. An opt-in, manual MintERP delivery CLI is under development; it does not run automatically and no production entitlement has been pushed. Never connect product databases directly. Per-instance signing secrets are stored encrypted only after explicit technical enrollment.

## Boundaries

- Ops owns customers, product plans, trials, contracts, renewal tracking and effective per-instance entitlements. Renewal evidence is stored on the private `local` disk and served only to authenticated active Admins; back up the database and private files together. Ops records are not an accounts-receivable ledger.
- Each product instance remains the source of its own business data; Ops must not connect directly to product databases.
- Entitlements are delivered as complete, versioned snapshots through product-specific adapters.
- `instance_ref` is an Ops-issued UUIDv7 identifier, not an authentication secret.
- Keep MintERP's internal POS module distinct from the `MINTPOS` product.
- MintPOS and MintHRM have product-specific quotas but no optional module add-ons; their instance commercial mode is `SUBSCRIPTION` or `LICENSE`. They use the same Core/Business quota limits as MintERP but independent plan versions and no MintERP module matrix. These Ops quotas are not yet enforced by the MintPOS/MintHRM applications.
- Do not add payment processing, customer self-service, or cross-product business rules without an approved requirement.

The MintERP snapshot and HMAC contract is in [`docs/ENTITLEMENT_SYNC_V1.md`](docs/ENTITLEMENT_SYNC_V1.md). For local UAT, an Admin can pair from ERP `/local-uat/ops-pairing` and then enroll/send on Ops `/admin/instances/{id}/entitlement`; the ERP receiver still requires an explicit local deployment flag and neither page sends automatically. The protected CLI alternative uses `ops:entitlement:enroll {ref} {target-url} {key_id} --actor={admin-id}` with a 64-character hex secret on protected stdin; `ops:entitlement:push {ref} --actor={admin-id}` sends the current snapshot only on demand. Set `OPS_ENTITLEMENT_ALLOWED_HOSTS` to exact approved HTTPS hosts; the CLI checks/pins public DNS addresses and never follows redirects, but the host also needs a restricted outbound firewall. In `APP_ENV=local` only, `http://127.0.0.1:8000` or `http://localhost:8000` is allowed for same-machine tests without HTTPS/allowlist; it is pinned to loopback, with no proxy or redirects. `ops:entitlement:rotate` accepts a replacement secret via stdin; `ops:entitlement:retire-key` requires confirmed delivery with the new key. The Instance entitlement page lets an Admin change the approved destination with a reason; this is audited, does not change the Instance ref/secret, and never auto-pushes. Failed/successful delivery appears on that page and in audit. See the sync contract for the reviewed reconciliation procedure and [`docs/ENTITLEMENT_UAT_READINESS.md`](docs/ENTITLEMENT_UAT_READINESS.md) for the read-only preflight, approval gates and owner-led UAT. Do not enable real delivery until network policy and isolated UAT pass.

## Local setup

Requires PHP 8.2+ and Composer 2.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Set `OPS_ADMIN_NAME`, `OPS_ADMIN_EMAIL`, and a unique `OPS_ADMIN_PASSWORD` of at least 12 characters in `.env`, then run:

```sh
php artisan migrate
php artisan db:seed
php artisan serve
```

Seeding is idempotent, adds the initial `MINTERP`, `MINTPOS`, and `MINTHRM` product records without overwriting changes, and will not reset an existing Admin's password. Configure mail only if password-recovery email is needed; it is not used for account creation. Use separate databases and secrets for each deployment environment, never commit credentials, and serve the portal over HTTPS outside local development.
