# Draft: entitlement sync v1 (MINTERP)

**Staged implementation, not production-ready.** ERP has a disabled-by-default receiver and Ops has manual enrollment/push commands. No credentials or snapshots have been provisioned or sent to a customer instance. Ops stores per-Instance entitlements and MintERP Trial history. An Admin acts directly; exceptional Trial issuance records a reason and audit, without a second approval. Ops no longer requires separate readiness/handover/contract confirmation checkboxes; Admin actions still require a reason and audit. License has no end date; an Admin explicitly changes its mode or status later.

Ops pushes a complete effective snapshot to one pre-enrolled MintERP instance. Subscription example:

```json
{
  "schema_version": 1,
  "product_code": "MINTERP",
  "instance_ref": "0195f1a3-5c00-7abc-8def-0123456789ab",
  "source_revision": 12,
  "entitlement": {
    "commercial_mode": "SUBSCRIPTION",
    "plan_code": "BUSINESS",
    "plan_version": "2026.1",
    "status": "ACTIVE",
    "starts_at": "2026-10-01T00:00:00Z",
    "expires_at": "2027-10-01T00:00:00Z",
    "quota_users": 15,
    "quota_branches": 3,
    "quota_warehouses": 6,
    "included_modules": ["crm", "asset"],
    "production_addon": false
  }
}
```

For `LICENSE`, send the same top-level identity/revision with explicit empty Subscription fields, never a stale Plan left over from the prior mode:

```json
{
  "schema_version": 1,
  "product_code": "MINTERP",
  "instance_ref": "0195f1a3-5c00-7abc-8def-0123456789ab",
  "source_revision": 13,
  "entitlement": {
    "commercial_mode": "LICENSE",
    "plan_code": null,
    "plan_version": null,
    "status": "ACTIVE",
    "starts_at": null,
    "expires_at": null,
    "quota_users": null,
    "quota_branches": null,
    "quota_warehouses": null,
    "included_modules": [],
    "production_addon": false
  }
}
```

A License snapshot may also have `status: SUSPENDED`. License has **no expiry**: `starts_at` and `expires_at` must both be null. It stays in its current status until an Ops Admin explicitly changes mode or status and pushes a newer revision. Time-limited promotions must use a different agreed mechanism, not an implicit License expiry.

## Proposed rules

- Ops issues an immutable UUIDv7 `instance_ref`; ERP binds it once. It identifies the instance but is not a credential.
- Each Subscription entitlement targets one Instance. A Customer may have multiple instances, each managed and revisioned separately.
- MintERP Core and Business plan defaults are versioned by plan code/version; quotas and optional modules are product-specific payload data. Production remains an opt-in per-instance add-on.
- MintPOS and MintHRM use their own product-scoped Core/Business versions with the same user/branch/warehouse defaults as MintERP (Core 5/1/2; Business 15/3/6), plus optional per-instance quota overrides. They have no module or Production add-ons; commercial mode per instance remains `SUBSCRIPTION` or `LICENSE`. Delivery/enforcement in those applications is not implemented; no live adapter or API contract is implied.
- MintERP accepts explicit, audited `SUBSCRIPTION` ↔ `LICENSE` changes from an Ops Admin without a second approval/confirmation. Both modes use the same authentication and revision rules. The API never issues `INTERNAL` or `UNASSIGNED`. A License clears current plan/period/quota/module/add-on fields, but keeps the immutable `instance_ref`, monotonic source revision, local audit revision and historical `trial_issued_at`; returning to Subscription requires a complete valid snapshot and never grants a second Trial.
- `source_revision` increases per instance and is separate from the ERP's local audit revision. Reject lower revisions. An identical repeated revision is idempotent; the same revision with different content is rejected.
- Subscription `status` is `ACTIVE`, `TRIAL`, or `SUSPENDED`; License is `ACTIVE` or `SUSPENDED`. `SUSPENDED` blocks business reads and writes for both modes (logout and a separately authenticated recovery/sync API remain available). Expired Subscription without suspension stays read-only; active License bypasses Subscription quotas/module gates. Times are UTC ISO-8601 instants; expiry is exclusive. Subscription quotas are non-negative integers.
- `included_modules` contains only effective optional MintERP modules (`crm`, `asset`); use `[]` for none. Core modules are implicit. Production is the separate boolean add-on.
- A MintERP Trial uses the Business plan and the agreed 30-day period from handover. Eligibility is once per Customer + Product and issuance is attached to one selected Instance; an exception on a different Instance records the Admin's reason and audit. An Instance cannot receive a second Trial. Production Trial opt-in preserves the original expiry; an expired Trial cannot gain Production. Trial-to-paid conversion uses an active Core/Business plan and a future paid expiry, resets overrides to that plan's defaults, and retains an enabled Production add-on. The Ops Admin acts directly without a second approval or confirmation checkbox; reason/audit, eligibility and explicit actions remain. Production readiness and paid-contract review are operator responsibilities, explained on the form; no checkbox is treated as proof. No automatic charge or entitlement delivery occurs.
- Snapshot application must be atomic and audited. The ERP must continue enforcing access locally if Ops is unavailable.

## Chosen MVP transport (manual implementation; production disabled)

- Ops issues the immutable UUIDv7 `instance_ref`. An operator generates a random 32-byte secret for that Instance (stored as 64 lowercase hex characters; decode to bytes before HMAC) using a secure server-side CLI/deployment secret store, configures it on Ops through a protected CLI/stdin (encrypted at rest with Ops's APP_KEY) and on the intended ERP in its protected deployment env/secret store, then binds the ref in ERP through an audited local CLI. No public enrollment endpoint, secret in shell arguments, URL, audit or logs; no auto-bind on first request. For `APP_ENV=local` on the same loopback machine only, an authenticated ERP Admin may generate/bind a one-time key in the ERP web UI (encrypted outside web root) and copy it into the authenticated Ops Admin enrollment form; the secret is never flashed into a session or shown again. ERP receiver enablement remains a separate deployment switch, never a web action. Production still uses protected deployment secrets and CLI enrollment; do not use the local key file in production. The ref is not the secret. Each clone/staging Instance gets a new ref, secret and destination. This one-time technical pairing does **not** require a second person's approval.
- Except for loopback in `APP_ENV=local`, Ops accepts only an exact FQDN from `OPS_ENTITLEMENT_ALLOWED_HOSTS` over HTTPS/443 without credentials, path, query or fragment. In `APP_ENV=local` only, Ops also accepts `http://127.0.0.1:<port>` or `http://localhost:<port>` with an explicit port (e.g. `8000`), no credentials/path/query/fragment; cURL pins localhost to `127.0.0.1` and still blocks proxies/redirects. This exception needs no host allowlist entry and fails closed outside local; HTTP carries the signed snapshot without TLS, so use it only on the same trusted machine and never expose the local listener to an untrusted network. After initial CLI enrollment, an Ops Admin can change the destination origin on the Instance entitlement page with a reason: the new host must first be approved in the deployment allowlist and resolve to public IPs. The change is audited, keeps `instance_ref` and secret unchanged, and **does not push**; previous delivery is no proof for the new host. Prepare DNS/TLS and the same ERP deployment's enrollment first, then push manually and check the acknowledgement; disable the old endpoint separately. A clone/new ERP deployment needs its own ref/secret enrollment, never a copied production identity. For HTTPS destinations, each push resolves A records, rejects the entire answer if any address is not public, and pins the checked IPv4 address in cURL while verifying TLS hostname/certificate, disabling proxies and redirects. For local HTTP only, the destination is pinned to loopback without DNS, and proxies/redirects remain disabled. DNS failures fail closed. **Also enforce outbound firewall egress** to approved public IP ranges; validate this and TLS termination during isolated UAT. Never push to a clone with the production ref/secret.
- `PUT /api/v1/entitlements/current` is outside the web business-access middleware so it can unsuspend an Instance; it accepts no Laravel user session. HTTPS only. `X-Ops-Key-Id`, `X-Ops-Timestamp` (Unix seconds) and `X-Ops-Signature` (lowercase hex HMAC-SHA256) sign the exact UTF-8 string `v1\n{key_id}\n{timestamp}\nPUT\n/api/v1/entitlements/current\n{sha256_hex(raw_body)}`. Reject unknown key IDs, timestamps outside ±300 seconds, invalid signatures (constant-time comparison), oversized/non-JSON bodies and wrong `schema_version`, `product_code` or pre-bound `instance_ref`. Validate strict payload fields. Use PHP/Laravel built-ins; no OAuth, JWT or new signing dependency.
- Rotation: first install the new ERP key ID/secret as current and move the old pair to the previous env keys; reload its config. Run `ops:entitlement:rotate {ref} {new-key-id} --actor={id}` with the new 64-hex secret on protected stdin. Push manually; on HTTP 401 Ops attempts the previous key **once** (recording which key succeeded). Only after ERP confirms delivery **with the new key at the current destination** may `ops:entitlement:retire-key {ref} --actor={id}` remove Ops's old key; then remove ERP's previous key. Never retire after a fallback-only delivery. A compromised old key must be revoked immediately, not kept for fallback. No key is stored in a business-data table on ERP.
- Store `source_revision` plus a digest of the validated snapshot in ERP separately from local `revision`; lock the singleton row and apply entitlement + audit atomically. Higher revision applies once; lower is conflict; equal revision with matching digest is a no-op retry, equal with different digest is conflict. Timestamp bounds + the revision/digest rule make captured requests unable to change state again. Ops push requires ERP's 200 response confirming the exact revision, and logs successful/failed delivery without secrets. The Instance page distinguishes latest acknowledged, pending and failed delivery. Network retries are manual and idempotent; only one automatic retry with the old key occurs on HTTP 401 during rotation. A portal save or last successful delivery **is not proof of the ERP's current state** after local changes.
- If Ops is down, ERP enforces its local snapshot and expiry. A local mode change increments ERP's local revision and blocks all subsequent pushes (409), including equal-revision retries. After reviewing the local change and Ops's desired snapshot, run `erp:entitlement:reconcile --actor={admin-id} --source-revision={current ERP source} --local-revision={current ERP local} --reason='...'` (audited, optimistic revision check). This invalidates the last snapshot digest, so an equal-revision push still conflicts. Run `ops:entitlement:bump {ref} --actor={admin-id} --reason='...'` to record a newer Ops revision, then push. These actions explicitly authorize replacing the local emergency state; never alter DB rows by SQL.

สำหรับ Instance ใหม่ ERP Admin จับคู่ผ่าน `/ops-pairing` (Admin + HTTPS หรือ local loopback) โดยเก็บ key เข้ารหัสใน DB ของ ERP และเปิด receiver แบบ audited แยกจากการจับคู่; Ops Admin enroll/push ผ่านหน้า Instance พร้อมเหตุผล โดย production จำกัด HTTPS public DNS/allowlist. ไม่ส่ง key ใน URL/log/chat และยังไม่เปิด UI หมุน/เลิกใช้ key แบบ DB-backed ก่อนผ่าน UAT. ฐาน UAT เก่าที่ใช้ encrypted local key file ยังทำงานต่อได้ แต่ DB key ของ Instance ใหม่มีสิทธิ์ก่อนและห้าม fallback ไปไฟล์เก่า.

Ops staff enter and see date/time in **Asia/Bangkok (UTC+7)**. `datetime-local` inputs are converted to UTC before persistence; existing UTC records are not rewritten. HMAC snapshots and ERP expiry enforcement remain UTC. An existing 13:36 UTC start displays as 20:36 Thai and still needs an audited new revision if the intended Thai start was 13:36.

Version v1 is scoped by `schema_version: 1` **and** `product_code: MINTERP`; a product-specific breaking change bumps schema version before adding other adapters. MintPOS/MintHRM do not consume this endpoint.

**Remaining gate:** validate the destination's firewall egress/TLS policy and rotation/reconciliation procedures on an isolated Instance, apply only reviewed migrations after backup, then let the owner perform UAT (including Trial/paid UI) before production. The Ops push is manual and the ERP receiver defaults off. No real keys or secrets were generated by this document.
