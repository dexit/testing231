---
name: novamira-feedback
description: Compose a sanitized English bug report the user can copy and paste to Novamira support, for genuine bugs in the Novamira plugin or its abilities (not for the agent's own input mistakes that a builder correctly rejected). Activate ONLY when the user explicitly asks to send feedback, report a bug, or contact Novamira support. Never auto-activate without an explicit user request — if the agent observes symptoms that look like a Novamira-side bug, it may briefly mention the option of reporting it and ask the user if they want to, but must wait for the user's go-ahead before invoking this skill. Do not activate for builder validation errors caused by malformed agent input, dissatisfaction with creative choices, or third-party/WordPress-core issues.
---

# Novamira feedback report

Your job is to compose a self-contained bug report **in English** that the user can copy and paste into Novamira support. The user does not know what an "ability" is — they only know that something they asked for did not happen correctly. You have the full conversation context, so you produce the technical part; the user only needs to copy and send.

## When to use this skill

Only for **genuine bugs in Novamira or its abilities** — not for failures caused by the agent itself.

Use this skill **only after the user has explicitly asked** to send feedback, report a bug, or contact Novamira support. The skill never runs unprompted.

If you observe symptoms that look like a Novamira-side bug (ability returned success but site state is wrong, plugin crash, admin screen broken, settings not persisting) but the user has not asked to report anything, you may mention the option in one short sentence — *"This looks like it might be a bug in Novamira itself; want me to prepare a report you can send to support?"* — and **wait for confirmation**. Do not invoke the skill until they say yes.

Do **not** use this skill when:

- The agent passed invalid or malformed input and the builder (Bricks, Elementor, WordPress core) correctly rejected it. That is an agent mistake, not a Novamira bug — fix your input and retry instead of reporting.
- Validation errors from a builder's own schema (unsupported element type, missing required field, wrong setting key). Those mean the agent should consult the relevant `list-elements` / `list-settings` ability and correct itself.
- The failure occurred inside `novamira/execute-php` or any other ability whose payload is arbitrary code written by the agent. Bugs there are in the agent's own code, not in Novamira — never report these.
- The user is unhappy with a creative choice (layout, copy, color) — that is feedback on the agent's design decisions, not a bug.
- The problem is in WordPress core, the theme, or a third-party plugin you only observed but did not cause.

**Self-check before activating:** ask yourself "if a human had run this same operation manually with the same intent, would the bug still appear?" If no — it is the agent's fault. If yes — it is a real bug worth reporting.

## When the user asks for a report but the cause is the agent

If the user explicitly asks to send feedback / report a bug, but your honest assessment is that the failure was caused by **your own mistake** (malformed input, wrong PHP in `execute-php`, ignoring schema, wrong assumption about the builder), do **not** produce a Novamira bug report. Instead, reply to the user — in the language they are using in the conversation, in plain non-technical words — and:

1. Tell them clearly that this specific issue is not something to report to Novamira, and explain why simply, in one or two sentences. The user does not know what an ability, a schema, or `execute-php` is — translate. **Always answer in the user's own language** (Italian if they wrote in Italian, etc.). The English example below shows the *style and tone* expected, not text to copy verbatim — for `execute-php` failures: *"To do what you asked, I wrote a small piece of custom code and ran it on your site. The code had a mistake I made — it's not something that's broken in the Novamira plugin, it's just a mistake on my side. There's nothing for the Novamira team to fix here."* Adapt the same plain-language style for schema/validation errors, missing required fields, or any other agent-side mistake.
2. Take responsibility plainly. Do not blame the plugin, the builder, or the user.
3. Offer to retry the operation correctly, now that the cause is understood.
4. Mention that if they still want to report something to Novamira (e.g. they think the error message was unclear, or the workflow could be smoother), they can describe it in their own words and you will compose a separate report focused on that — but do not auto-generate one.

Only proceed to the report-composition steps below if the failure genuinely points at Novamira itself.

## Hard rules — no private data

The report must contain **no private or identifying data**. Strip or replace before output:

- Email addresses, phone numbers, personal names → omit entirely.
- Post/page content, titles, excerpts, custom field values → replace with `[redacted]` or describe by type (`a paragraph element with custom text`).
- Specific slugs, post IDs, taxonomy terms, user IDs → replace with placeholders (`<post_id>`, `<slug>`).
- API keys, tokens, license keys, passwords → never include, even partially.
- Full site URL → keep only the bare domain if relevant (`example.com`), drop paths and query strings.
- Screenshots, file contents, database rows → do not include.

When in doubt, leave it out. The report should be reproducible-in-spirit, not data-rich.

## What to include

Produce the report in this exact structure, in English, as plain text inside a fenced code block so the user can copy it cleanly:

```
Novamira feedback report

What I asked the agent to do:
<one or two sentences in plain English describing the user's original goal, with all private data redacted>

What the agent attempted:
<bulleted sequence of the Novamira abilities the agent called, in order, with sanitized intent — e.g. "Created a new page", "Set the Bricks element tree with N sections", "Patched element settings". Do not include raw input payloads.>

What went wrong:
<concise description of the failure: error message returned by the ability, wrong visual outcome, missing effect, etc. Quote error strings verbatim only if they contain no private data.>

Expected vs actual:
- Expected: <what the user wanted>
- Actual: <what happened instead>

Environment:
- Builder: <Bricks | Elementor | other, with version>
- Novamira version: <X.Y.Z>
- Novamira Pro version: <X.Y.Z>
- WordPress version: <X.Y.Z>
- PHP version: <X.Y.Z>
- Other plugins involved: <only those touching the failing path, each with version — e.g. "ACF Pro 6.8.0.1, WPML 4.9.2". Omit the line entirely when no third-party plugin is involved.>
- AI agent: <main model name and version, e.g. "Claude Opus 4.7">
- Subagents used: <list each subagent type with its model, e.g. "Explore (Sonnet 4.6), code-reviewer (Opus 4.7)" — omit the line entirely when no subagent was dispatched>

Notes:
<anything else relevant the agent observed, e.g. "the ability returned success but the page rendered empty". Keep it short.>
```

Omit any section you genuinely have no information for — do not invent values. WordPress, PHP, plugin versions, and the locale are listed in the MCP server-instructions block sent at session start (`Installed plugins:` + the `WordPress … — PHP …` header) — prefer reading them from there since they are already in your context. If a value is missing or you want to confirm it, you can also resolve it through an ability call: `novamira/execute-php` for `phpversion()`, `get_bloginfo('version')`, `get_plugins()`, or any other one-line lookup. The agent's own model name comes from the system prompt; subagent models come from the dispatch you performed (the `subagent_type` and any explicit `model` override).

## How to deliver it to the user

1. Print one short line above the code block, in the user's language, telling them what to do **and asking them to review it before copying** — e.g.: *"Here is a sanitized report for Novamira support. Please review it before copying — make sure nothing private slipped through and the description matches what you experienced."*
2. Print the report inside a single fenced code block so it copies cleanly.
3. After the code block, print one short line, in the user's language, noting that they can attach any screenshot or screen recording alongside the report when they send it — those should not go inside the code block.
4. End with one short line offering to adjust the report if they want more or less detail.

Do not call any ability to "send" the report. There is no submission endpoint — the user copies and sends it themselves through their existing support channel.

## Verification before output

Before showing the report, re-read it and confirm:

- No email, name, phone number, token, key, or password appears anywhere.
- No post content, title, or excerpt text appears verbatim.
- The site is referenced at most by bare domain.
- The text is in English regardless of the conversation language.

If any check fails, redact and re-emit.
