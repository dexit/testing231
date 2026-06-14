# Webhook Router Mapper — Worked Demo & Fail-Safe Reference

End-to-end examples for the two chained-mapping flows, plus how the engine
behaves when things go wrong.

> Mapping config lives in the `wrm_config` post meta of each `wrm_mapping`
> post (the visual builder writes it for you). The JSON files in this folder
> are ready to paste — strip the `_name` / `_description` helper keys first.

---

## Flow A — Payload → CPT + Dispatcher → Webhook

A single inbound webhook creates/updates a CPT post and immediately
dispatches a **signed** JSON payload to an external service.

### 1. Incoming payload

```json
{
  "contact": { "name": "Jane Doe", "email": "jane@example.com", "phone": "+15551234567" },
  "message": "Interested in a quote",
  "source": "website"
}
```

### 2. Mapping — `flow-a-lead-dispatcher.json`

- CPT: `lead`, de-duplicated on `contact.email` vs `meta:email`
- Fields → `post_title`, `post_content`, `meta:email`, `meta:phone`,
  `taxonomy:lead_source`
- Chain: `dispatcher` with a `signing_secret`

### 3. Result

**A `lead` post:**

| Field | Value |
|-------|-------|
| `post_title` | `Jane Doe` |
| `post_content` | `Interested in a quote` |
| `meta:email` | `jane@example.com` |
| `meta:phone` | `+15551234567` |
| `lead_source` term | `website` |

**An outbound POST** to `https://hooks.crm.example.com/inbound`:

```http
POST /inbound HTTP/1.1
Content-Type: application/json
X-Source: wrm
X-WRM-Signature: sha256=<hmac_sha256(signing_secret, body)>

{"event":"lead.created","post_id":"123","name":"Jane Doe",
 "email":"jane@example.com","phone":"+15551234567",
 "received_at":"2026-06-14 16:50:02"}
```

The receiver verifies authenticity by recomputing
`HMAC-SHA256(signing_secret, raw_body)` and comparing to the
`X-WRM-Signature` header.

---

## Flow B — Payload → CPT-A (JSON meta) → chain → CPT-B (custom metas) → Dispatcher → Webhook

A complex object is parked verbatim on **CPT-A** as JSON, then a chained
mapping decodes that JSON and projects it into a clean **CPT-B** with flat
metadata before dispatching.

### 1. Incoming payload

```json
{
  "order": {
    "id": "ORD-1001",
    "customer": { "name": "Acme Corp", "email": "ops@acme.test" },
    "total": 4299,
    "currency": "USD",
    "items": [ { "sku": "A-1", "qty": 2 }, { "sku": "B-7", "qty": 1 } ]
  }
}
```

### 2. Step 1 mapping — `flow-b1-order-inbox.json`

- CPT-A: `order_raw` (status `private`)
- `order.id` → `post_title`
- **`order` → `meta_json:payload`** — the entire `order` object is stored
  **JSON-encoded** in the `payload` meta key
- Chain: `mapping` with
  `chain_source: "post_meta_json"`, `chain_source_key: "payload"`
  → set `mapping_id` to the post ID of the step-2 mapping

What the engine does: writes `meta:payload =
'{"id":"ORD-1001","customer":{...},"total":4299,...}'`, then **reads that
meta back, `json_decode()`s it, and passes the decoded `order` object as the
payload** to the step-2 mapping.

### 3. Step 2 mapping — `flow-b2-order-normalized.json`

Receives the decoded `order` object as its payload, so sources are relative
to it (`id`, `customer.name`, …):

- CPT-B: `order`, de-duplicated on `id` vs `meta:order_id`
- Flat metas: `order_id`, `customer_email`, `total_cents`, `currency`
- `items` → `meta_json:line_items` (kept as JSON for later use)
- Chain: `dispatcher` → signed POST to the ERP

### 4. Result

**`order_raw` post (CPT-A):** title `ORD-1001`, `meta:payload` = raw JSON.

**`order` post (CPT-B):**

| Meta | Value |
|------|-------|
| `order_id` | `ORD-1001` |
| `customer_email` | `ops@acme.test` |
| `total_cents` | `4299` |
| `currency` | `USD` |
| `line_items` | `[{"sku":"A-1","qty":2},{"sku":"B-7","qty":1}]` |

**Outbound POST** to `https://erp.example.com/api/orders` with
`X-WRM-Signature`, body:

```json
{"order_id":"ORD-1001","wp_post_id":"124","customer":"Acme Corp",
 "amount_cents":"4299","currency":"USD","line_count":["1","1"]}
```

(`line_count` uses a loop node over `items` → one `"1"` per item.)

---

## Fail-safe behaviour

The engine is built to **degrade, not crash**. Every failure path is logged
to the `wrm_logs` table (visible in the Logs admin tab) and processing
continues where it safely can.

| Condition | What happens | Log level |
|-----------|--------------|-----------|
| Mapping `wrm_config` is invalid JSON | Aborts with `mapping_config_invalid`; no post touched | `error` |
| Incoming payload is invalid JSON | Treated as empty `{}`; mapping still runs | — |
| `meta_json` chain meta won't decode to an array (e.g. stored `"null"`) | Falls back to the **original** payload; chain still runs | `warning` |
| `chain_source_key` missing/empty | Uses the original payload (no decode attempted) | — |
| Synthetic chain capture fails to store | Chain returns `capture_store_failed`; parent mapping unaffected | `error` |
| Dispatcher URL is private/reserved (SSRF) | Chain skipped (`unsafe_url`); other chains still run | `warning` |
| Outbound webhook returns HTTP error / times out | Logged with status; mapping result still `success` | `error` |
| `function` chain not allow-listed | Blocked (`not_registered`); never executed | `warning` |
| `action` chain hook not allow-listed | Blocked (`not_registered`); never fired | `warning` |
| Any uncaught exception in `apply()` | Caught → `{success:false, error, exception}`; logged with trace | `exception` |

### Async durability

- Captures are processed via a **WP-Cron job queue** — the HTTP webhook
  responds immediately, work happens out-of-band.
- **Retries:** up to 3 attempts with back-off delays of **2s / 10s / 30s**;
  exhausted jobs are marked `dead` (not lost — retry from the Jobs tab).
- **Rate limiting** per route prevents a burst from overwhelming
  downstream services (429 once the window is exceeded).
- A **sweep mutex** (120s transient) stops two cron runs from processing the
  same batch concurrently, and an async loopback **spawns cron on enqueue**
  so low-traffic sites still fire promptly.

### Inbound authenticity (replay-safe)

- **Twilio:** SHA1 signature, URL reconstructed from `$_SERVER` (proxy-safe).
- **HubSpot v3:** `HMAC-SHA256(secret, METHOD+URI+body+timestamp)` with a
  **5-minute replay window**.
- **WhatsApp / Meta:** SHA256 `X-Hub-Signature-256`.
- **Outbound** (dispatcher): `X-WRM-Signature: sha256=<hmac>` when a
  `signing_secret` is set, so your receivers can verify in the same way.
