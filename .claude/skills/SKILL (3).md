---
name: elementor-build-page
description: Build or rebuild a page in Elementor end-to-end — from scratch or from a source such as Gutenberg, Bricks, HTML, screenshots, Figma, or another Elementor page. Activate when the user asks to create a new Elementor page, recreate an existing page in Elementor, migrate a page from another builder into Elementor, or decide whether to use Elementor v4 atomic or legacy v3 on the current site.
---

# Building a page in Elementor

This is the generic Elementor playbook. Use it when the target is Elementor,
whether you are building from scratch or reconstructing from another source.

Prefer the dedicated `novamira/elementor-*` abilities for Elementor reads,
writes, and schema discovery. Supporting abilities such as
`novamira/create-post` and `novamira/memory-*` are allowed when they support
the workflow. Use `novamira/execute-php` only as a narrow fallback for gaps
that still have no dedicated ability.

## First decision: what kind of job is this?

1. **No source exists**: build from the brief in Elementor.
2. **The source is Gutenberg, Bricks, HTML, screenshots, Figma, or another
   non-Elementor representation**: rebuild the page structure in Elementor.
   Do not try to preserve source-specific internals 1:1.
3. **The source is an Elementor legacy v3 page and the site exposes the v4
   atomic surface**: immediately load
   `novamira/skill-get slug=elementor-convert-to-v4` and follow that
   specialized playbook for the actual conversion.

## Choose the target surface

- If the site exposes the Elementor v4 atomic surface, prefer **atomic**
  widgets and containers by default.
- If the v4 atomic surface is not available, build with **legacy v3**
  Elementor widgets/containers.
- Do not mix v3 and v4 without a reason. Mixed pages are acceptable only when
  an atomic equivalent does not exist, the user explicitly wants a hybrid
  result, or a specialist migration playbook says to keep selected widgets v3.

Practical signal: if the abilities list includes v4-only tools such as
`novamira/elementor-get-style-schema` and Global Classes abilities, the site
is ready for the v4 atomic workflow. If those tools are absent, stay on the
legacy surface.

## Canonical sequence

1. **Understand the source or brief**
   - If there is a source page, identify its sections, hierarchy, key widgets,
     repeated patterns, dynamic content, and page-level layout constraints.
   - If there is no source page, extract the required sections, content, and
     layout from the user brief before writing.
2. **Create the target post**
   - Use `novamira/create-post` for a new page unless the user is editing an
     existing post.
   - If the page must inherit a specific WordPress page template and no
     dedicated ability exists yet, copy `_wp_page_template` with a narrow
     `execute-php` fallback.
3. **Check page-level composition when relevant**
   - If Elementor Pro / Theme Builder may affect the result, check which
     templates compose the page before writing content. Until a dedicated
     ability exists, use `execute-php` narrowly for template discovery.
4. **Build section by section**
   - Prefer subtree-sized writes over full document dumps whenever possible.
   - Use `novamira/elementor-get-schema` for widget discovery and
     `novamira/elementor-get-style-schema` when writing v4 styles.
   - Write the structure first, then refine settings/styles if needed.
5. **Handle repeated styles intentionally**
   - In v4, use per-element `styles` for unique styling.
   - Use Global Classes only for repeated patterns shared by multiple elements.
6. **Preserve dynamic behavior**
   - Keep dynamic content, links, and bindings when the source has them.
   - Use dedicated dynamic-tag abilities when you need to add or repair a
     binding explicitly.
   - When the source data lives in a field plugin (ACF / Pods / JE / MB /
     ACPT / ASE), activate the `dynamic-data-binding` skill — each provider
     has its own composite-key format and silent-fails differently.
7. **Summarize gaps and decisions**
   - Call out any part rebuilt approximately, any widget kept legacy, and any
     template/layout assumption that the user may want to review.

## Styling hierarchy — use in this order, do not skip

The most common agent failure on Elementor is reaching for CSS when a
native mechanism already covers the intent, or dumping page-level
custom CSS as a shortcut to avoid N per-element edits. Neither is
acceptable.

**Before writing any CSS, call `novamira/elementor-get-schema` on the
target widget and search for a native control that covers the intent.**
For style controls, do not start with a broad `include_styles:true`
dump on common v3 widgets; it can be very large. Prefer
`include_styles:true` plus `control_names` for the controls you are
checking, or scope with `tab:"style"` / `tab:"advanced"` or a specific
`section`. Keywords to scan: `flex`, `margin`, `padding`, `align`,
`justify`, `position`, `z_index`, `dimension`, `shadow`, `border`,
`typography`, `transform`. Responsive variants are suffixed `_tablet`,
`_mobile`. If a control exists, using CSS instead is wrong.

The tier depends on the surface. Check `novamira/elementor-check-setup`
→ `atomic.runtime_available` and `elementor_pro.active` BEFORE
deciding.

### v4 atomic (when `atomic.runtime_available` is true)

**Prop shapes — the server auto-wraps scalars.** In v4 atomic, both
widget settings AND per-element styles accept plain scalar values in
`props`. The server validates each value against the Style Schema and
wraps it into the correct `{$$type, value}` envelope for you. Pass the
simple form and let the server handle the plumbing:

```json
// Pass this:
"props": {
  "color": "#FFFFFF",
  "font-size": 72,
  "font-weight": "700",
  "display": "flex",
  "flex-direction": "row",
  "gap": 24,
  "padding": {"block-start": 16, "inline-end": 32, "block-end": 16, "inline-start": 32}
}

// The server stores this (equivalent):
"props": {
  "color": {"$$type": "color", "value": "#FFFFFF"},
  "font-size": {"$$type": "size", "value": {"size": 72, "unit": "px"}},
  "font-weight": {"$$type": "string", "value": "700"},
  "display": {"$$type": "string", "value": "flex"},
  "flex-direction": {"$$type": "string", "value": "row"},
  "gap": {"$$type": "size", "value": {"size": 24, "unit": "px"}},
  "padding": {"$$type": "dimensions", "value": { /* each side wrapped as size */ }}
}
```

Same applies to Global Classes' `styles` payload. You can still pass
the fully-wrapped form when you need an exotic shape (e.g. a
`box-shadow` array, a `background` with overlays) — both forms are
accepted. The server validates fail-hard against the Style Schema on
every write, so unknown props or bad enum values are returned with the
schema inline for you to correct.

Default unit for size scalars is `px`. Pass a sized object
(`{size: 50, unit: "%"}`) when you need another unit.

1. **Per-element `styles` props.** The native styling mechanism — not
   a workaround. Use `novamira/elementor-get-style-schema` to discover
   prop shapes, then write into `styles[id].variants[N].props` on the
   element via `add-element` / `edit-element`. Covers layout, spacing,
   typography, background, borders, shadows, transforms, hover states,
   responsive variants. This is where ~95% of styling belongs.
2. **Global Classes** (`novamira/elementor-list-global-classes`,
   `-create-global-class`, `-edit-global-class`, `-delete-global-class`,
   `-apply-global-class`) for patterns shared by multiple elements. Not
   one per widget — they are meant to be reused.
3. **Variables** (`novamira/elementor-list-variables`,
   `-get-variable`, `-create-variable`, `-edit-variable`,
   `-delete-variable`) for design tokens referenced by many
   styles/classes.
4. **`variants[N].custom_css.raw`** — true escape hatch, only for what
   style props cannot express (pseudo-elements like `::before`, sibling
   selectors, keyframes). Written via the `styles` param of
   `add-element` / `edit-element`, shape:
   ```json
   "variants": [{
     "meta": {"breakpoint": "desktop", "state": null},
     "props": { ... },
     "custom_css": {"raw": "selector::before { content: '★'; }"}
   }]
   ```
   **Render caveat.** `custom_css.raw` is render-gated: Elementor Pro's
   hook returns it only when `API::is_license_active()` AND the license
   has the `atomic-custom-css` feature flag enabled. If the license
   lapses or the feature flag is off, the CSS is silently stripped on
   the frontend — the data persists in the post meta, just doesn't
   render. Novamira Pro also restores it unconditionally, but do not
   rely on that for production (Novamira is a dev-time tool). Prefer
   style props whenever possible. When you do write `custom_css.raw`,
   flag the dependency to the user.
5. **Page-level `_elementor_page_settings.custom_css`** — Pro only.
   Reserved for cross-cutting rules (`@import`, body-wide resets,
   rules genuinely spanning many widgets). Never as a bulk shortcut
   for N widget edits. A block of rules all targeting
   `.elementor-element-<id>` is a code smell — those belong on the
   widgets, not here.

#### Semantic element ids

Pass a kebab-case `element_id` on `novamira/elementor-add-element` for
every element with a natural name (`hero`, `pricing-card`,
`footer-cta`, `cta-button`, `nav`, `services-grid`). The slug becomes
the rendered `data-id` and — for v4 atomic elements with synthesized
local styles — the rendered CSS class (`s-<element_id>`). That class
is what users see in DevTools and what page-level `custom_css` rules
reference. Without it the rendered class is a 7-char hex
(`s-684747c`), which is unusable for inspection or follow-up styling.

Skip `element_id` for anonymous elements — items inside a loop, pure
decorators (a divider, an empty spacer), elements with no obvious
single name. Falling back to the auto-id is correct there.

The id must be unique on the page. If two sections both want `hero`,
disambiguate (`hero-home`, `hero-product`).

#### Flex layout rules for atomic v4 (CRITICAL)

Getting flex wrong makes the page unrecognizable — content collapses
into a vertical column, the page grows to tens of thousands of pixels,
and the design is unreadable. This is the #1 failure mode of atomic v4
builds.

**Core rule.** In `e-flexbox` with `flex-direction: row`, children take
their natural width unless you explicitly give each child a `flex` or
`width` prop in its styles. Unlike v3 sections/columns, there is NO
auto-distribution. A row container with 4 children and none of them
defining `flex` or `width` will show 4 shrunk natural-width boxes, not
4 equal columns.

**For every child of a row flex container, set one of:**

```json
"flex": {"$$type": "flex", "value": {
  "flexGrow": {"$$type": "number", "value": 1},
  "flexShrink": {"$$type": "number", "value": 1},
  "flexBasis": {"$$type": "size", "value": {"size": 0, "unit": "%"}}
}}
```

or an explicit width:

```json
"width": {"$$type": "size", "value": {"size": 25, "unit": "%"}}
```

**Key atomic flex style props** (discover their exact shape with
`elementor-get-style-schema`):

| Intent | Atomic prop |
|---|---|
| Row / column direction | `flex-direction` (string: `row`, `column`) |
| Items alignment on cross axis | `align-items` (string: `center`, `flex-start`, ...) |
| Items distribution on main axis | `justify-content` (string: `space-between`, `center`, ...) |
| Gap between items | `gap` (size) |
| Wrap when overflowing | `flex-wrap` (string: `wrap`, `nowrap`) |
| Child sizing (column width) | `width` (size with % or px) |
| Child flex | `flex` (flex object with flexGrow/flexShrink/flexBasis) |
| Container min height | `min-height` (size) |

**Common patterns**

*N equal columns (e.g. 4 feature cards):*
- Parent: `flex-direction: row`, `flex-wrap: wrap`, `gap: 20px`
- Each child: `flex: {flexGrow: 1, flexShrink: 1, flexBasis: 0%}` OR
  `width: 25%`

*Hero split (65% / 35%):*
- Parent: `flex-direction: row`, `align-items: center`
- Left child: `width: 65%`
- Right child: `width: 35%` (or flex with flexGrow)

*Vertical section stack:*
- Parent: `flex-direction: column`, `align-items: center`

**Self-check after building a section.** Before moving to the next
section, read it back (`elementor-get-content full_dump:false`) and
verify: every row-flex container has children that each define `flex`
or `width`. A 0-byte `custom_css.raw` count + a suspicious `total
elements but no flex props` pattern is a red flag. If you have visual
tooling (any browser MCP, screenshot ability, computer-use), also
confirm the section height is sensible. If you have no visual tooling,
the DOM/data check is enough.

#### Boxed containers — full-bleed background, constrained content

Atomic v4 containers do NOT have a native "boxed width" concept: an
`e-flexbox` is always 100% of its parent. To get the classic page
pattern — **background/padding spans the full viewport, but content is
constrained to a max-width and centered** — you need a two-level
wrapper: an outer full-width container holding the background and an
inner container with `max-width` holding the children.

**You don't have to build the two-level wrapper by hand.** Pass
`boxed_width` (plus `background_color`, `padding`, flex settings, ...)
as a setting on a single flat `e-flexbox` and the server splits it for
you on write — the same mechanism the v3 → v4 converter uses. This
works for build-from-scratch too.

```
What you pass:                              What the server stores:
e-flexbox (id: hero,                        e-flexbox (id: hero, outer)
  boxed_width: 1300,            →             ├─ background, padding, justify-center
  background_color: "#FAF",                   └── e-flexbox (id: hero-in, inner)
  flex_direction: "column")                         ├─ max-width: 1300, width: 100%
  ├── e-heading                                     │   flex-direction: column
  └── e-paragraph                                   ├── e-heading
                                                    └── e-paragraph
```

How the split distributes settings:
- **Outer** keeps: `background_color`, `padding`, `margin`,
  `min_height`, `max_height`, `border_radius`, `tag` / `html_tag`,
  plus a forced `justify-content: center`. Any `styles` you already
  attached (e.g. a background-image overlay) stay on the outer.
- **Inner** gets: `width: 100%`, `max_width: <boxed_width>`, plus all
  flex layout settings (`flex_direction`, `flex_wrap`,
  `flex_align_items`, `flex_justify_content`, `flex_gap`).
- `content_width` is dropped (the wrapper pattern replaces it).
- `boxed_width` is consumed.

Notes for build-from-scratch:
- **Pure v4 atomic is full-bleed by default.** An `e-flexbox` whose
  settings contain no v3-era keys (no `boxed_width`, no
  `content_width`, no `flex_direction`, no `html_tag`, no `flex_gap`,
  no `flex_align_items`, no `flex_justify_content`, no `flex_wrap`)
  is considered a native v4 write and is NOT auto-split. If you want
  the section boxed, pass `boxed_width` explicitly — that single key
  is a v3 marker and triggers the split.
- **Opt into full-bleed explicitly** by passing `content_width:
  "full"` (useful when you want the intent visible in the tree, even
  though pure v4 atomic is already full-bleed).
- **Custom boxed width** — pass `boxed_width: 1100` (or `{size: 1100,
  unit: "px"}`) to get outer+inner split at that width.
- **Auto-box at kit default.** If the settings already contain any
  v3-marker key (commonly `flex_direction: "column"` when
  reconstructing a v3 source, or `html_tag: "section"`) AND neither
  `boxed_width` nor `content_width: "full"` is set, the server
  reads `container_width` from the active Elementor kit and splits
  at that value. This mirrors v3's default-boxed behavior for
  conversions; pure v4 builds rarely trigger it.
- Nested containers that already sit inside a split wrapper inherit
  the boxing and do NOT get a second outer/inner layer.
- The inner container gets a derived id `<your-id>-in`. If you need to
  `edit-element` it later, read the page back first to discover the
  id.

If you instead build the outer/inner wrapper by hand (outer flexbox
with bg + inner flexbox with `max-width` and `width: 100%`, plus
`justify-content: center` on the outer), the result is the same — but
the flat form with `boxed_width` is shorter, less error-prone, and
keeps the intent visible in the tree.

### v3 with Elementor Pro (`elementor_pro.active` true, atomic false)

1. **Native controls** — always first. Before writing any CSS, use
   `get-schema include_styles:true` with `control_names`, `tab`, or
   `section` narrowing so the response stays small. Controls agents
   keep forgetting: `_flex_shrink`, `_flex_grow`, `_z_index`,
   `_position`, negative `_margin`, `image_custom_dimension`,
   `box_shadow_*`, responsive suffixes `_tablet` / `_mobile`.
2. **Per-widget `custom_css`** setting on the widget — for rules
   controls can't express. Travels with the widget when moved or
   duplicated.
3. **Page-level `_elementor_page_settings.custom_css`** — cross-cutting
   rules only, same caveat as above.

### v3 Free (no Pro, no Novamira Pro)

Both `custom_css` fields are unavailable (they are Pro features). Atomic
v4 is also unavailable.

1. **Exhaust native controls.** Free has more than most agents expect.
   Always the first answer.
2. If a rule truly cannot be expressed by any control:
   - **WP Customizer → Additional CSS** via `wp_update_custom_css_post()`
     is visible, versioned, editable by the user. Scope is site-wide;
     write selectors precisely (e.g. scope by page ID class on `body`).
   - **External CSS file** enqueued conditionally via a small loader
     (mu-plugin under Novamira sandbox, code snippet, or child theme)
     is also acceptable.
3. Do NOT hide CSS inside an `html` widget `<style>` block. It is
   invisible to anyone editing the page later.
4. If the effect is specific to atomic-widget behavior that Free cannot
   provide (hover states on atomic, pseudo-elements), tell the user the
   feature requires Elementor Pro or Novamira Pro and stop — do not
   invent a workaround.

### Antipatterns to refuse

- Writing CSS for a property a native control already exposes
  (`flex-shrink`, `margin`, `padding`, `z-index`, dimensions,
  typography, background, borders, shadows — get-schema the widget
  first).
- Dumping N widget-scoped rules (`.elementor-element-<id> { ... }`)
  into `_elementor_page_settings.custom_css` because it is "fewer
  calls" than editing each widget.
- Using raw `execute-php` to mutate Elementor content instead of the
  dedicated `elementor-*` write abilities (set-content, add-element,
  edit-element, delete-element). The abilities handle validation,
  schema checks, and cache invalidation — bypassing them skips all of
  that and corrupts easily.
- Hiding CSS inside an `html` widget `<style>` block on Free.

## Source-specific guidance

### From scratch

- Start from the user brief, not from imagined Elementor internals.
- Create only the sections and components the brief actually requires.
- Prefer semantic structure: header/hero/content/cta/footer patterns should
  be reflected in the container hierarchy, not only in visual styling.

### From Gutenberg, Bricks, HTML, screenshots, or Figma

- Rebuild the layout and content in Elementor; do not chase exact source
  metadata or builder-specific keys.
- Translate the **intent**:
  - structure
  - spacing
  - typography
  - repeated components
  - responsive behavior
- Preserve literal user-facing content exactly unless the user asks for copy
  edits.
- **Do NOT use the `html` widget as a catch-all for unconverted source
  markup.** Every source element maps to an atomic widget: `<h1>`–`<h6>`
  → `e-heading`, `<p>` → `e-paragraph`, `<a class="btn">` / `<button>`
  → `e-button`, `<img>` → `e-image`, `<hr>` → `e-divider`,
  `<section>` / `<div>` layout wrappers → `e-flexbox` or `e-div-block`.
  For non-semantic display text (kicker labels, decorative numerals, icon
  text) use `e-heading` with `tag: "div"` or `tag: "span"` — both are
  valid and render correctly.
  The `html` widget is reserved for things you genuinely cannot
  translate (lottie / asciinema / third-party JS embeds, `<video>` /
  `<audio>` until an atomic equivalent exists, raw markup the user
  explicitly wants verbatim). A page made of N `html` widgets dumping
  the source markup is not a converted Elementor page — it is a static
  HTML page wrapped in Elementor chrome, and is not editable from the
  v4 editor.

### From Elementor itself

- If the source is already Elementor v4 or a compatible atomic build, reuse
  existing structure patterns and refine in place where possible.
- If the source is Elementor v3 and the site supports atomic, switch to the
  dedicated `elementor-convert-to-v4` skill.

## v4 atomic policy

When the v4 atomic surface exists:

- Prefer `e-flexbox` / `e-div-block` containers and atomic widgets.
- Prefer `novamira/elementor-get-style-schema` + per-element `styles` for
  unique styling.
- Prefer Global Classes only for repeated patterns.
- Prefer dedicated abilities over manual raw PHP or guessed `{$$type, value}`
  shapes.

### When there is no atomic equivalent

If a needed component has no real atomic equivalent:

- **Default to legacy v3** when it is one-off, third-party, or not central to
  the design system.
- **Consider `novamira/elementor-create-atomic-widget`** when the missing
  component is reused across pages, is a core design-system primitive, or the
  user explicitly wants a fully native v4 result with no legacy fallback.
- **Ask the user before creating a custom atomic widget** when the tradeoff is
  non-obvious. Creating a custom widget has hidden cost: maintenance,
  validation, and future compatibility. Do not make that decision silently.

The escalation question should be a direct product choice, for example:

- Keep this component as a legacy v3 widget inside the page
- Create a custom atomic widget so the result stays fully native v4

## What to preserve carefully

- User-facing HTML/text content
- Links and media references
- Dynamic tags and other bindings
- Page template/layout assumptions
- Repeated components that should become reusable patterns

## Ability quick map

| Ability | Use |
| --- | --- |
| `novamira/create-post` | Create the target page/post |
| `novamira/elementor-get-content` | Read an existing Elementor tree or subtree |
| `novamira/elementor-set-content` | Replace a full Elementor tree |
| `novamira/elementor-add-element` | Insert a new element or subtree |
| `novamira/elementor-edit-element` | Refine an existing element in place |
| `novamira/elementor-delete-element` | Remove a bad element cleanly |
| `novamira/elementor-get-schema` | Discover available widgets and control shapes |
| `novamira/elementor-get-style-schema` | Discover v4 style prop shapes |
| `novamira/elementor-list-global-classes` | Reuse existing shared classes |
| `novamira/elementor-create-global-class` | Create shared v4 patterns |
| `novamira/elementor-list-v3-styles` | Resolve legacy global colors / typography |
| `novamira/elementor-apply-dynamic-tag` | Re-apply or add dynamic bindings |
| `novamira/elementor-create-atomic-widget` | Create a reusable custom atomic widget when justified |

## What this skill is not

- It is **not** the specialized Elementor v3 -> v4 migration playbook. For
  that, load `elementor-convert-to-v4`.
- It is **not** a schema dump. Use the Elementor abilities for concrete widget
  and style shapes.
