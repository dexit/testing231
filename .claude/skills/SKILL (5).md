---
name: elementor-convert-to-v4
description: Convert a legacy Elementor v3 page to the v4 atomic architecture — containers become e-flexbox/e-div-block, widgets become atomic equivalents (e-heading, e-paragraph, e-button, e-divider), styling uses per-element styles + shared Global Classes. Activate when the user asks to convert, migrate, or upgrade an Elementor page to v4/atomic.
---

# Elementor v3 → v4 conversion playbook

You are converting a legacy Elementor page to the v4 atomic architecture.
Prefer the dedicated `novamira/elementor-*` abilities for all Elementor
document reads/writes and for schema/style discovery.
Supporting abilities such as `novamira/create-post` and `novamira/memory-*`
are allowed when they support the workflow.
Use `novamira/execute-php` only as a narrow fallback for gaps that still
have no dedicated ability. Do NOT default to raw PHP for tasks already
covered by dedicated abilities.

## Strategy: section-by-section with `add-element tree:`

Build the converted page section by section. For each top-level section:
1. Fetch its subtree with `get-content element_id:"<id>"` (small, 1-3K tokens)
2. Transform it following this playbook
3. Append it to the target page with `add-element tree:<subtree>`

The server automatically handles all the v4 plumbing:
- **Auto-wraps** plain settings into `{$$type, value}` format (pass `"title": "Hello"`, server wraps into `html-v3`)
- **Auto-wraps** styles too — scalar values inside a variant's `props` (e.g. `color: "#FFFFFF"`, `font-size: 72`, `padding: {block-start: 16, ...}`) are wrapped against the Style Schema; same wrap applies to Global Classes' `styles` payload
- **Auto-converts** valid v3 `__dynamic__` tags to v4 `{$$type: "dynamic"}` format
- **Auto-syncs** per-element style IDs into `settings.classes`
- **Auto-normalizes** classes to wrapped `{$$type: "classes", value: [...]}` format
- **Invalidates** the CSS cache after every write

You NEVER need to:
- Manually wrap settings or style props in `{$$type, value}` format — pass scalars
- Convert dynamic tag string format
- Add style IDs to `settings.classes`
- Clear CSS cache
- Use the full page dump (work section by section)

If a subtree contains an unknown widget type, invalid control value, or an
unparseable v3 dynamic tag string, `add-element tree:` now fails hard so you
can correct the subtree and retry instead of persisting a broken section.

## Ground rules

1. **Preserve content HTML exactly.** When copying `title`, `editor`, or `text` content from v3 to v4, copy the ENTIRE HTML string unchanged — including `<sup>`, `<span style="...">`, `<strong>`, `<b>`, `<i>`, `<br>`, HTML entities (`&reg;`), inline styles, and CSS classes. The v4 `html-v3` prop type renders HTML. Never strip tags or convert entities.
2. **Preserve `__dynamic__` tags exactly.** If a v3 widget has `__dynamic__` on a content field, copy the ENTIRE tag string to the v4 widget. Only remap the key name: `editor` → `paragraph` for text-editor→e-paragraph. The tag value stays identical.
3. **Per-element styles for unique styling.** Use the `styles` field on each element for its unique visual properties. Global Classes only for patterns that repeat (e.g. 4 identical cards).
4. **Get flex layout right.** This is the #1 visual difference if wrong. See the Flex Layout section below.
5. **Resolve v3 global colors.** Call `list-v3-styles` to get the actual hex values — don't guess colors.

## Styling: `styles` field + Global Classes

### Per-element styles (unique to one element)

Every atomic element can have a `styles` field with style definitions:

```json
{
  "id": "my-element",
  "elType": "e-flexbox",
  "settings": {"tag": "header"},
  "styles": {
    "my-element-base": {
      "id": "my-element-base",
      "type": "class",
      "label": "my-element-base",
      "variants": [
        {
          "meta": {"breakpoint": "desktop", "state": null},
          "props": {
            "padding": {"$$type": "dimensions", "value": {
              "block-start": {"$$type": "size", "value": {"size": 16, "unit": "px"}},
              "inline-end": {"$$type": "size", "value": {"size": 32, "unit": "px"}},
              "block-end": {"$$type": "size", "value": {"size": 16, "unit": "px"}},
              "inline-start": {"$$type": "size", "value": {"size": 32, "unit": "px"}}
            }},
            "background": {"$$type": "background", "value": {
              "color": {"$$type": "color", "value": "#E52600"}
            }}
          }
        },
        {
          "meta": {"breakpoint": "tablet", "state": null},
          "props": {
            "padding": {"$$type": "size", "value": {"size": 16, "unit": "px"}}
          }
        }
      ]
    }
  }
}
```

The server auto-adds the style IDs to `settings.classes` — you don't need to.

Required shape for every style entry:
- `label`: string, minimum 2 characters (e.g. the style ID itself, or a short descriptor like `"base"`). Empty strings are rejected.
- `meta.breakpoint`: must be a string — `"desktop"`, `"tablet"`, or `"mobile"`. `null` is rejected. Use `"desktop"` for the base variant.
- `meta.state`: `null` is fine (or `"hover"`, `"focus"`, `"active"`).

### Global Classes (shared patterns)

Use Global Classes only when multiple elements share the exact same style
(e.g. 4 plugin cards with identical padding/border/shadow). Create them
via `create-global-class` and reference their IDs in `settings.classes`.

### When to use which

Both Global Classes and per-element styles override base styles correctly.
Use Global Classes for shared patterns, per-element styles for unique ones.

| Scenario | Use |
|----------|-----|
| Hero section unique background + padding | Per-element `styles` |
| 4 identical card containers | Global Class |
| Button style reused across cards | Global Class |
| Heading typography unique to one heading | Per-element `styles` |
| CTA button with different size/color | Per-element `styles` |

**Do not mix** per-element styles and Global Classes for the same CSS
properties on the same element — use one or the other.

## Flex layout rules (CRITICAL)

Getting flex layout wrong makes the page unrecognizable. Follow these rules:

### v3 → v4 flex mapping

In v3, child containers in a `flex-direction: row` parent auto-distribute
equally. In v4 `e-flexbox`, children take their natural width unless you
explicitly set `flex` or `width` on them.

**Rule: when a v3 container has N children in a row layout, each child
needs `flex` in its styles:**

```json
"flex": {"$$type": "flex", "value": {
  "flexGrow": {"$$type": "number", "value": 1},
  "flexShrink": {"$$type": "number", "value": 1},
  "flexBasis": {"$$type": "size", "value": {"size": 0, "unit": "%"}}
}}
```

Or alternatively set an explicit `width`:
```json
"width": {"$$type": "size", "value": {"size": 25, "unit": "%"}}
```

### Key flex properties to translate

| v3 setting | v4 style prop | Notes |
|------------|---------------|-------|
| `flex_direction: "row"` | `flex-direction: {$$type: "string", value: "row"}` | Default for e-flexbox is row |
| `flex_direction: "column"` | `flex-direction: {$$type: "string", value: "column"}` | |
| `flex_align_items: "center"` | `align-items: {$$type: "string", value: "center"}` | |
| `flex_justify_content: "space-between"` | `justify-content: {$$type: "string", value: "space-between"}` | |
| `flex_gap: {size: 20, unit: "px"}` | `gap: {$$type: "size", value: {size: 20, unit: "px"}}` | |
| `flex_wrap: "wrap"` | `flex-wrap: {$$type: "string", value: "wrap"}` | |
| `width: {size: 65, unit: "%"}` | `width: {$$type: "size", value: {size: 65, unit: "%"}}` | For inner containers |
| `min_height: {size: 500, unit: "px"}` | `min-height: {$$type: "size", value: {size: 500, unit: "px"}}` | |

### Common layout patterns

**4 equal columns (e.g. plugin cards):**
- Parent: `flex-direction: row`, `flex-wrap: wrap`, `gap: 20px`
- Each child: `flex: {flexGrow: 1, flexShrink: 1, flexBasis: 0%}` or `width: 25%`

**Hero with sidebar (65% / 35%):**
- Parent: `flex-direction: row`, `align-items: center`
- Left child: `width: 65%`
- Right child: `width: 35%` (or `flex: {flexGrow: 1, ...}`)

**Vertical stack (sections):**
- `flex-direction: column`, `align-items: center`

## Dynamic tags preservation

### The server auto-converts v3 → v4 dynamic tag format

In v3, dynamic tags are stored as `__dynamic__` with `[elementor-tag ...]`
strings. In v4 atomic widgets, they become inline `{$$type: "dynamic"}`
prop values. **The server handles this conversion automatically** — you
just pass `__dynamic__` from the v3 source and the server:
1. Parses the `[elementor-tag ...]` string
2. Converts it to `{$$type: "dynamic", value: {name, settings}}`
3. Sets it as the prop value (replacing any static fallback)

### What the agent must do

Only **remap the key name** when the v3 → v4 content key changes:
- `editor` → `paragraph` (for text-editor → e-paragraph)
- `title` stays `title` (heading → e-heading)
- `link` stays `link` (button → e-button)
- `text` stays `text` (button → e-button)

Copy the tag value string unchanged.

```json
// v3 text-editor: agent remaps "editor" → "paragraph", copies the tag string
"__dynamic__": {"paragraph": "[elementor-tag id=\"abc\" name=\"text-with-dynamic-shortcodes\" settings=\"...\"]"}

// Server auto-converts to v4 format in the stored data:
// "paragraph": {"$$type": "dynamic", "value": {"name": "text-with-dynamic-shortcodes", "settings": {"content": "..."}}}
```

### What the agent must NOT do

- Do NOT manually convert the tag string format — the server does it
- Do NOT remove `__dynamic__` from the tree — pass it through
- Do NOT worry about the v4 `{$$type: "dynamic"}` format — the server builds it

## Element conversion map

### Containers

| v3 | v4 | Condition |
|----|-----|-----------|
| `container` | `e-flexbox` | Uses flex layout (default) |
| `container` | `e-div-block` | Uses block layout |

Settings: only `tag` (from `html_tag`). All visual styles go in `styles` field.

### Boxed containers

In v3, a container with `boxed_width` renders its background
full-bleed but constrains its children to the boxed width. The same
visual effect in v4 atomic requires a two-level wrapper (outer
full-width with the background; inner with `max-width` holding the
content).

**You don't build the two-level wrapper yourself.** Pass the v3 boxed
container as a single flat `e-flexbox` with `settings.boxed_width:
{size: 1300, unit: "px"}` (or just `boxed_width: 1300`) alongside its
other v3 settings (background_color, padding, flex_direction, ...).
The server splits it deterministically into outer + inner and moves
the children inside the inner.

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
- **Outer** keeps: `background_color`, `padding`, `margin`, `min_height`,
  `max_height`, `border_radius`, `tag`/`html_tag`, plus a forced
  `justify-content: center`. Existing `styles` (e.g. dynamic
  `background-image-overlay` you built manually) stay on the outer.
- **Inner** gets: `width: 100%`, `max_width: <boxed_width>`, plus all the
  flex layout settings (`flex_direction`, `flex_wrap`, `flex_align_items`,
  `flex_justify_content`, `flex_gap`).
- `content_width` is dropped (the wrapper pattern replaces it).
- `boxed_width` is consumed.

Notes:
- The inner gets a derived id `<your-id>-in`. You can't reference it in
  later `edit-element` calls without reading the page back first.
- For sibling sections that are each independently boxed (a stack of
  full-bleed sections each with its own `boxed_width`), pass each one
  flat — the server splits each one into its own outer/inner. That is
  the correct v4 layout.
- For nested boxed (a boxed container inside another boxed container),
  the recursion handles each level independently.
- For `content_width: "full"` containers, there is nothing to split —
  the server keeps them full-width and drops the `content_width` key
  (no v4 equivalent).
- **Default-boxed containers**: a v3 container with NEITHER
  `boxed_width` NOR `content_width: "full"` is implicitly constrained
  to the kit's `container_width`. The server handles this
  automatically: at the top level of the tree it reads
  `kits_manager->get_current_settings('container_width')` and splits
  the container at that width, just as if you had passed
  `boxed_width: <kit value>` yourself. Nested containers that already
  sit inside a split wrapper inherit the boxing and do NOT get a second
  outer/inner layer.

Net effect: you can pass the v3 container tree through unchanged and
the server produces the right wrapper shape. Add `content_width: "full"`
explicitly only when you want a section to be full-bleed in v4 (hero
sections with a full-viewport background, typically) even though the
v3 source was default-boxed.

### Widgets — MUST convert vs keep v3

These 5 widget types **MUST ALWAYS** be converted to their atomic
equivalent. No exceptions — even when they have `custom_css`,
`__dynamic__` tags, `__globals__`, or complex responsive settings.
The server handles dynamic tags and settings wrapping automatically.
`custom_css` is lost (report it) but that is NOT a reason to keep v3.

| v3 | v4 | Content mapping | Tag |
|----|-----|-----------------|-----|
| `heading` | **MUST** → `e-heading` | `title` → `title` | `header_size` value |
| `text-editor` | **MUST** → `e-paragraph` | `editor` → `paragraph` | `"p"` |
| `button` | **MUST** → `e-button` | `text` → `text`, `link` → `link` | `"a"` |
| `divider` | **MUST** → `e-divider` | — | — |
| `image` | **MUST** → `e-image` | `image` → `image` | — |

These widget types have **no atomic equivalent** — keep as v3:

| v3 | Action |
|----|--------|
| `html` | keep v3 |
| `loop-grid` | keep v3 |
| `form` | keep v3 |
| `accordion` | keep v3 |
| `dce-*` (any Dynamic Content for Elementor widget) | keep v3 |
| `template` | keep v3 |
| `animated-headline` | keep v3 |
| Third-party / unknown | keep v3 |

For `icon-list`, `social-icons`, `spacer` — rebuild from atomic
primitives (e-flexbox + e-paragraph/e-button/e-div-block).

## Theme Builder templates

If Elementor Pro is active, **always check which templates compose the
page** before converting. A page's visible layout may come from multiple
templates (header, footer, single-post, sidebar, loop items, etc.),
not just the post's own content. Until a dedicated ability exists for this,
use `novamira/execute-php` as a narrow fallback to list the templates that
apply to the source post and decide what actually needs converting.

### Before converting any page:

1. **Check memory** (`novamira/memory-list`) for previous template conversions
2. **List all templates** that contribute to the rendered page — not just
   header/footer but also single-post templates, sidebar templates, loop
   item templates, etc. The post's own Elementor content may be minimal
   if the layout comes from templates.
3. **Ask the user** what to convert. Present the full picture:
   > "This page's layout comes from template X (post Y, type Z).
   > The post itself also has its own Elementor content.
   > Do you want me to convert:
   > - Only the post content
   > - Only the template (affects all pages using it)
   > - Both
   > ?"
   Do NOT skip this step. Do NOT assume. The user decides.
4. **Save to memory** after conversion: template ID, title, conversion status

### Notes on header/footer conversion:

- Headers often use complex v3 widgets (mega-menu, search-form, nav-menu)
  that have NO atomic equivalent — they stay v3 inside e-flexbox containers
- Footers are usually simpler (headings, text, icon-list, image)
- Converting header/footer containers to e-flexbox changes their layout
  system — test carefully since these affect every page

## Canonical sequence

### 1. Inventory

- Check memory for previous conversions on this site (`novamira/memory-list`)
- `get-content post_id:SOURCE` → compact skeleton
- Note element types, widget types, which containers are row vs column
- Check if the page uses header/footer templates (see section above)
  If no dedicated template-discovery ability exists, use
  `novamira/execute-php` to find templates:
  ```php
  get_posts(['post_type' => 'elementor_library', 'meta_key' => '_elementor_template_type', 'meta_value' => 'header', 'post_status' => 'publish'])
  ```

### 2. Resolve colors and existing classes

- `list-v3-styles` → get actual hex values for every `__globals__` reference
- `list-global-classes` → find reusable classes (nv-card, nv-btn, etc.)
- `get-style-schema` → reference for `{$$type, value}` shapes
- Build a color map: `{globals_id → hex}` for translating `__globals__`

### 3. Create target page

- `create-post title:"Page v4" status:draft`
- Copy the page template from source so the target has the same layout
  (full-width, canvas, default, etc.). Until there is a dedicated ability
  for this, use a narrow `execute-php` call:
  ```php
  execute-php: update_post_meta(TARGET_ID, '_wp_page_template', get_page_template_slug(SOURCE_ID) ?: 'default')
  ```

### 4. Convert section by section

For each top-level section in the skeleton:
1. `get-content post_id:SOURCE element_id:"<section_id>"` → full subtree
2. Transform:
   - Containers: `elType` → `e-flexbox`, `settings` → `{tag}`, `styles` → visual props
   - Widgets: `widgetType` → atomic, content keys remapped, `styles` → typography/colors
   - **`__dynamic__`**: copy through with key remapping
   - **Flex layout**: add `flex`/`width` on children of row containers
   - **Resolve colors**: replace `__globals__` references with actual hex values
3. `add-element post_id:TARGET tree:<transformed subtree>`

### 5. Verify

- Open the target page and compare visually with the original
- Check: layout (row/column), colors, spacing, typography, dynamic content
- Fix any issues with `edit-element` or by re-inserting the section

### 6. Report

- Elements converted (type → type)
- Elements kept v3 (reason)
- Settings dropped (shape dividers, motion effects, custom_css — no v4 equivalent)
- `__dynamic__` tags preserved

### 7. Propose custom atomic widgets

After the report, list every widget that was kept v3 because no atomic
equivalent exists. For each one, ask the user whether they want you to
create a custom atomic widget (via `novamira/elementor-create-atomic-widget`)
that replicates it natively in v4. Group them by complexity:

- **Good candidates** — widgets with simple markup and few controls
  (e.g. icon-box, star-rating, counter) that would be straightforward
  to replicate as atomic
- **Complex candidates** — widgets with JS interaction, complex state,
  or many controls (e.g. nav-menu, carousel, accordion) that would
  require significant effort

Wait for the user's decision before creating anything.

## Style property quick reference

| v3 setting | v4 style prop | Shape |
|------------|---------------|-------|
| `padding` | `padding` | `{$$type: "dimensions", value: {block-start, inline-end, block-end, inline-start}}` |
| `margin` | `margin` | Same as padding |
| `background_color` | `background` | `{$$type: "background", value: {color: {$$type: "color", value: "#hex"}}}` |
| `border_radius` | `border-radius` | `{$$type: "size", value: {size, unit}}` |
| `border_border` + `border_color` | `border-style` + `border-color` | string + color |
| `box_shadow_box_shadow` | `box-shadow` | `{$$type: "box-shadow", value: [{$$type: "shadow", value: {hOffset, vOffset, blur, spread, color, position?}}]}` |
| `typography_font_size` | `font-size` | `{$$type: "size", value: {size, unit}}` |
| `typography_font_weight` | `font-weight` | `{$$type: "string", value: "800"}` |
| `typography_font_family` | `font-family` | `{$$type: "string", value: "Montserrat"}` |
| `typography_line_height` | `line-height` | `{$$type: "size", value: {size, unit}}` |
| `typography_letter_spacing` | `letter-spacing` | `{$$type: "size", value: {size, unit}}` |
| `typography_text_transform` | `text-transform` | `{$$type: "string", value: "uppercase"}` |
| `title_color` / `text_color` | `color` | `{$$type: "color", value: "#hex"}` |
| `min_height` | `min-height` | `{$$type: "size", value: {size, unit}}` |

### Responsive: breakpoint suffixes → variants

v3 uses suffixed keys: `padding_tablet`, `min_height_mobile`, `typography_font_size_tablet`.
In v4, these become additional variants in the same style definition:

```json
"variants": [
  {"meta": {"breakpoint": "desktop", "state": null}, "props": {"font-size": ...}},
  {"meta": {"breakpoint": "tablet", "state": null}, "props": {"font-size": ...}},
  {"meta": {"breakpoint": "mobile", "state": null}, "props": {"font-size": ...}}
]
```

## Background images

v4 supports background images via the `background-image-overlay` in the
`background` style prop. Structure:

```json
"background": {"$$type": "background", "value": {
  "background-overlay": {"$$type": "background-overlay", "value": [
    {"$$type": "background-image-overlay", "value": {
      "image": {"$$type": "image", "value": {
        "src": {"$$type": "image-src", "value": {
          "id": {"$$type": "image-attachment-id", "value": 123},
          "url": {"$$type": "url", "value": "https://example.com/image.jpg"}
        }},
        "size": {"$$type": "string", "value": "full"}
      }},
      "repeat": {"$$type": "string", "value": "no-repeat"},
      "size": {"$$type": "string", "value": "cover"},
      "position": {"$$type": "string", "value": "center center"}
    }}
  ]},
  "color": {"$$type": "color", "value": "#E52600"}
}}
```

You can combine a background color with an image overlay.

### Dynamic background images (the ONE manual case)

For every other `__dynamic__` tag in the tree — `title`, `paragraph`,
`link`, `text`, etc. — the server auto-converts to the v4
`{$$type: "dynamic"}` format. You just pass the v3 tag string
unchanged and it works.

**`background_image` on containers is the only exception.** Background
images in v4 live in `styles` (not in widget settings), and
`__dynamic__` only auto-converts widget settings. So for dynamic
container backgrounds you must build the v4 style manually: put
`{$$type: "dynamic"}` directly inside the style's `image.src` slot,
like this:

```json
"background": {"$$type": "background", "value": {
  "background-overlay": {"$$type": "background-overlay", "value": [
    {"$$type": "background-image-overlay", "value": {
      "image": {"$$type": "image", "value": {
        "src": {"$$type": "dynamic", "value": {
          "name": "dynamic-shortcodes-image",
          "group": "dynamic-shortcodes",
          "settings": {"content": {"$$type": "string", "value": "{media:url @ID={pw:default-bg}}"}}
        }},
        "size": {"$$type": "string", "value": "full"}
      }},
      "size": {"$$type": "string", "value": "cover"},
      "position": {"$$type": "string", "value": "center center"},
      "repeat": {"$$type": "string", "value": "no-repeat"}
    }}
  ]},
  "color": {"$$type": "color", "value": "#E52600"}
}}
```

To convert a v3 `__dynamic__.background_image` tag:
1. Parse the `[elementor-tag ...]` string to extract `name` and `settings`
2. URL-decode the settings JSON
3. Build the `{$$type: "dynamic", value: {name, group, settings}}` object
4. Place it as `image.src` in the `background-image-overlay` style prop
5. The `group` is typically `"dynamic-shortcodes"` for Dynamic Shortcodes tags

**The server does NOT auto-convert `__dynamic__` for style properties.**
This is a manual step the agent must perform during conversion.

## Common conversion mistakes

### 1. Full-width containers become boxed
v3 containers with `content_width: "full"` or `boxed_width` span the full
viewport. In v4, atomic containers are rendered inside Elementor's page
wrapper which may add padding/max-width. To get true full-width, set
`width: 100%` in styles and verify visually.

### 2. `__globals__` colors override direct values
In v3, `__globals__` takes precedence over direct color values. If a button
has `background_color: "#E52600"` but also `__globals__.background_color:
"globals/colors?id=823ede9"` (#D1D3D4), the ACTUAL color is #D1D3D4, not
red. Always resolve `__globals__` first — direct values are only fallbacks.

### 3. Negative margin overlap lost
v3 sections with `margin-top: -50px` overlap the section above (e.g. cards
overlapping the header). In v4 this works via `margin` in styles, but the
visual effect depends on the parent container's `overflow` setting. If the
overlap is clipped, set `overflow: visible` on the parent.

### 4. `custom_css` on converted widgets
v3 widgets carry `custom_css` on their `settings`. Atomic widgets also
support `custom_css` — but at a different location: inside each per-element
style variant, as `styles[style_id].variants[N].custom_css.raw`.

**Preferred form: migrate into native style props first.** Most v3
`custom_css` expresses things that atomic `styles` props already cover
(color, background, spacing, typography, borders, shadows). Rewrite as
props in the `styles` map. Only what genuinely cannot be expressed as a
prop (pseudo-elements like `::before`, sibling selectors, keyframes) goes
into `variants[0].custom_css.raw` on the default variant.

**Write via the dedicated ability, not raw PHP.** Pass the shape through
`novamira/elementor-add-element` (or `edit-element` / `set-content`) in
the `styles` parameter. Shape:

```json
"styles": {
  "s-1": {
    "id": "s-1",
    "type": "class",
    "label": "my-custom-style",
    "variants": [{
      "meta": {"breakpoint": "desktop", "state": null},
      "props": { /* native style props */ },
      "custom_css": {"raw": "selector::before { content: '★'; }"}
    }]
  }
}
```

**Render caveat — tell the user.** Atomic `custom_css` is render-gated
by Elementor Pro: the hook returns it only when `API::is_license_active()`
AND the license has the `atomic-custom-css` feature flag enabled. If
the license lapses or the feature flag is off, the CSS is silently
stripped on the frontend — data persists, render disappears. Novamira
Pro also restores it unconditionally, but do not rely on that for
production (Novamira is a dev-time tool).

When you migrate v3 `custom_css` into atomic, flag the dependency to
the user: "this requires Pro with the atomic-custom-css feature; if
the license or feature flag changes, the style stops rendering." Do
NOT drop `custom_css` as "lost".

### 5. Dynamic background images need manual conversion
v3 containers with `__dynamic__.background_image` have dynamic backgrounds.
The server auto-converts `__dynamic__` for widget **settings** (title,
paragraph, link) but NOT for **style properties**. You must manually
build the `{$$type: "dynamic"}` value and place it in
`styles.background.background-overlay.image.src`. See the "Dynamic
background images" section under "Background images" for the full format.

## Properties without v4 equivalent

These v3 features have no style-schema equivalent — report them to the user:
- Shape dividers (`shape_divider_bottom`, etc.)
- Motion effects (`motion_fx_*`)

(`custom_css` is NOT in this list — atomic supports it as
`styles[id].variants[N].custom_css.raw`. See the `custom_css` item under
"Data loss scenarios" for how to migrate it.)

(`boxed_width` is NOT in this list — pass it through as a setting and
the server splits the container into the outer/inner pattern. See the
Boxed containers section.)

## Abilities reference

| Ability | Purpose |
|---|---|
| `novamira/elementor-get-content` | Read tree (skeleton or `element_id` for subtree) |
| `novamira/elementor-add-element` | Insert subtree with `tree:` parameter |
| `novamira/elementor-get-schema` | Widget/container schema discovery |
| `novamira/elementor-get-style-schema` | v4 Style Schema — `{$$type, value}` shapes |
| `novamira/elementor-list-v3-styles` | v3 global colors/typography (get actual hex values) |
| `novamira/elementor-list-global-classes` | Existing v4 Global Classes |
| `novamira/elementor-create-global-class` | Create shared Global Classes |
| `novamira/elementor-apply-dynamic-tag` | Re-apply dynamic tags if needed |
| `novamira/create-post` | Create the target page |

## Multi-page conversions and memory

When converting multiple pages on the same site:

1. **Before starting**: check memory for Global Classes and templates
   already converted in previous conversations
2. **Reuse Global Classes**: don't recreate classes that already exist —
   use `list-global-classes` and reference existing IDs
3. **After each page**: save to memory via `novamira/memory-save`:
   - Global Classes created (IDs, labels, what they style)
   - Templates converted (header/footer IDs, status)
   - v3 widgets kept (which types, on which pages)
4. **For site-wide conversion**: audit all pages first, create shared
   Global Classes upfront, then convert pages one by one

Proceed section by section. After each section, verify the result visually
before moving to the next.
