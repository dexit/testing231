=== Webhook Router & Mapper ===
Contributors: dexit
Tags: webhook, api-gateway, automation, integration, pipeline
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress-native API gateway and ETL pipeline. Ingest webhooks, transform payloads, dispatch actions, and track outcomes.

== Description ==

Webhook Router & Mapper (WRM) is a full-featured API gateway built for WordPress. It provides:

* **Route Ingestion** — Register REST endpoints for any webhook provider (Stripe, Twilio, HubSpot, custom, etc.)
* **Payload Capture** — Store and inspect every incoming request with full payload visibility
* **Mapping Engine** — Transform payload fields into WordPress posts, meta, and taxonomies
* **Action Chains** — Execute webhooks, send emails (MJML), SMS/WhatsApp, run functions, or trigger sub-mappings
* **Async Job Queue** — Process mappings in the background with automatic retry and dead-letter routing
* **Schedules** — Run mappings on a timer or via URL trigger
* **Message Tracking** — Track email opens, clicks, bounces, and SMS delivery status
* **Observability** — Live log tail, per-route hourly metrics, gateway IP filtering, signature verification

= Key Features =

* IP allowlist/blocklist enforcement per route (CIDR support for IPv4 and IPv6)
* Conditional chain execution (10 operators: eq, neq, gt, gte, lt, lte, contains, etc.)
* Dead-letter routing — failed jobs re-dispatched to a configurable DLQ route
* MJML email rendering (compiled to responsive HTML)
* Multi-provider SMS/WhatsApp (Twilio, Sinch, MessageMedia, WhatsApp)
* React SPA admin panel with live log streaming, sparklines, and real-time auto-refresh
* Works with MySQL, SQLite (via WP SQLite plugin), and Redis object cache

== Installation ==

1. Upload the plugin to `/wp-content/plugins/webhook-router-mapper/`
2. Activate through the Plugins menu
3. Go to Webhook Router → Get Started to seed demo data or create your first route

== Changelog ==

= 1.3.0 =
* Added per-route IP allowlist/blocklist with CIDR matching
* Added signature status tracking per capture (verified/failed/skipped)
* Added hourly metrics table (wrm_metrics) with sparkline dashboard
* Added GET /wrm/v1/logs/tail endpoint for live log streaming
* Added conditional chain execution with 10 comparison operators
* Added dead-letter routing for permanently failed jobs
* Added React Getting Started page with demo data seeder
* Bumped DB schema to version 1.3

= 1.2.0 =
* Added message tracking for email/SMS opens, clicks, bounces
* Added MJML email rendering support
* Added multi-provider SMS/WhatsApp chains
* Added pipeline-centric Dashboard with sparklines
* Added full-text search on jobs, logs, and messages

= 1.1.0 =
* Added async job queue with exponential backoff retry
* Added schedule runner (cron + URL trigger)
* Added React SPA admin panel

= 1.0.0 =
* Initial release — route registration, payload capture, mapping engine, action chains
