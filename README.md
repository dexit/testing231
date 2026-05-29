# Custom Endpoints Manager & Microplugins

A native WordPress plugin for dynamically registering, securing, and executing custom REST API endpoints backed by sandboxed PHP snippets called **Microplugins**.

[![Open in WordPress Playground](https://playground.wordpress.net/assets/powered-by-playground-badge-blue.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dexit/testing231/main/blueprint.json)

> **Playground demo** — lands on the CEM settings page with a pre-installed "Hello World Demo" microplugin registered at `GET /wp-json/cem/v1/hello`.

---

## Features

| Area | What it does |
|---|---|
| **Custom Endpoints** | Register unlimited REST routes via a settings-page table — slug, method(s), capability, microplugin callback, typed args |
| **Microplugins CPT** | PHP snippets stored as posts, edited in an **Ace editor** (34 themes, syntax-highlighting), cached to disk on publish |
| **Code Sandbox** | Every execution runs in an isolated PHP namespace (no `eval`). Blacklist of 30+ forbidden functions + suspicious-pattern checks + `php -l` syntax lint before any code is saved |
| **CEM Template Tags** | Injected `CEM` façade class available in every microplugin: `CEM::param()`, `CEM::body()`, `CEM::header()`, `CEM::user()`, `CEM::http_get()`, `CEM::http_post()`, `CEM::store()`, `CEM::retrieve()`, `CEM::queue()` |
| **Function Library** | 16 static helpers via `Lib::` alias: data extraction, array flattening, type coercion, WP post/term helpers, validation, string utilities |
| **Async Execution** | Mark any endpoint async → job queued in DB → WP Cron processes it → poll `GET /cem/v1/jobs/{id}` for status |
| **Execution Logs** | Every sync and async execution logged to `cem_execution_logs` table — payload, result, duration, attempt count |
| **Retry / Dead-letter** | Failed async jobs retry with exponential back-off (2 min → 10 min → 30 min). Dead jobs surfaced in admin with one-click Requeue |
| **Admin Logs UI** | Filterable log table (All / Queued / Running / Done / Failed / Dead) with inline payload/result/error expand |

---

## Requirements

- WordPress 6.9+
- PHP 7.4+

---

## Installation

1. Clone or download this repository.
2. Copy the `custom-endpoints-manager/` directory into `wp-content/plugins/`.
3. Activate **Custom Endpoints Manager & Microplugins** from the WordPress plugins screen.

---

## Quick Start

### 1 — Create a Microplugin

Go to **Microplugins → Add New**. Write a PHP function named `cem_microplugin_callback_{POST_ID}` where `{POST_ID}` is the post ID shown in the URL.

```php
<?php
function cem_microplugin_callback_42( WP_REST_Request $request ): WP_REST_Response {
    $name = CEM::param( 'name', 'World' );
    return CEM::response( array( 'message' => "Hello, {$name}!" ) );
}
```

Publish the post. The plugin validates the code and writes a cache file.

### 2 — Register an Endpoint

Go to **Settings → Custom Endpoints** and add a row:

| Field | Example value |
|---|---|
| Route Slug | `hello` |
| Methods | `GET` |
| Capability | `read` |
| Microplugin | Hello World (ID: 42) |

Save. The route `GET /wp-json/cem/v1/hello` is now live.

### 3 — Call it

```bash
curl "https://yoursite.com/wp-json/cem/v1/hello?name=Alice"
# → {"message":"Hello, Alice!"}
```

---

## Async Endpoints

Enable the **Async** checkbox on any endpoint. The caller gets an immediate `202` with a `job_id`:

```json
{ "job_id": 7, "status": "queued", "poll": "/wp-json/cem/v1/jobs/7" }
```

Poll the job endpoint or watch the **Execution Logs** admin tab. Failed jobs are retried automatically; dead jobs can be requeued with one click.

---

## CEM Template Tags

Every microplugin has access to the injected `CEM` façade:

```php
CEM::param( 'key', $default )   // GET/POST/JSON param
CEM::body()                      // decoded JSON request body
CEM::header( 'X-My-Header' )    // request header
CEM::method()                    // 'GET' | 'POST' | …
CEM::user()                      // WP_User or null
CEM::option( 'key', $default )  // get_option() shorthand
CEM::http_get( $url, $args )    // WP HTTP API GET
CEM::http_post( $url, $body )   // WP HTTP API POST
CEM::store( 'key', $value )     // WP transient store (cross-microplugin)
CEM::retrieve( 'key' )          // retrieve stored value
CEM::queue( 'slug', $payload )  // enqueue an async job for another endpoint
CEM::response( $data, $code )   // new WP_REST_Response
CEM::error( 'code', 'msg' )     // new WP_Error with HTTP status
```

`Lib::` is aliased to `CEM_Function_Library` — 16 data/parsing/WP/validation/string helpers. Full catalog at `GET /wp-json/cem/v1/functions/library`.

---

## Demo Microplugins

Seven example files in `microplugins/examples/` (rename from `.php.example` to use):

| File | What it demonstrates |
|---|---|
| `example-hubspot-deal-webhook.php.example` | HubSpot signature v3 validation, deal stage change → store + queue SMS |
| `example-hubspot-contact-webhook.php.example` | Lifecycle change → HubSpot API enrich → queue welcome SMS or WP CRM entry |
| `example-hubspot-company-webhook.php.example` | Revenue change → HubSpot API → WP post upsert + queue director alert |
| `example-twilio-receive-sms.php.example` | Twilio signature validation, command parsing, TwiML XML response |
| `example-twilio-dispatch-sms.php.example` | Twilio Messages API dispatch (async), E.164 validation, delivery record |
| `example-form-to-hubspot-submit.php.example` | Sanitize form fields → HubSpot Forms API v3 with consent |
| `example-hubspot-contact-enrich-and-dispatch.php.example` | Property change → reverse-lookup contact + companies → assemble dataset → queue dispatch |

---

## REST API

| Method | Route | Description |
|---|---|---|
| `*` | `/cem/v1/{slug}` | Your custom endpoints |
| `GET` | `/cem/v1/jobs` | List execution jobs (admin) |
| `GET` | `/cem/v1/jobs/{id}` | Poll a single job |
| `POST` | `/cem/v1/jobs/{id}/retry` | Requeue a failed/dead job |
| `GET` | `/cem/v1/functions/library` | Full function catalog |

---

## License

GPL v2 or later.
