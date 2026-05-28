---
name: jetengine-integration
description: Activate when working with JetEngine on a WordPress site — meta boxes, options pages, Custom Content Types (CCT), Query Builder queries, listings, relations, or glossaries. Establishes the source-awareness model (db / php), the replace-vs-merge edit semantics, the CCT-type-vs-CCT-record distinction, and the typical discover→read→modify→write workflow shared across all JetEngine abilities. Also documents wp/run-php patterns for entities not yet covered by Novamira Pro abilities (queries, listings, relations, glossaries).
---

# Working with JetEngine

This skill is for using the 21 `novamira/jetengine-*` abilities together. Read it once at the start of any JetEngine-related task and refer back when in doubt.

## When to use

Activate when the user asks to inspect, create, edit, or delete:

- JetEngine meta boxes (custom fields attached to posts, taxonomies, users)
- JetEngine options pages (admin pages with custom fields)
- JetEngine Custom Content Types (CCT) — both type definitions and the records inside them
- Or to read/execute JetEngine Query Builder queries, listings, relations, glossaries (via the `wp/run-php` patterns at the end of this skill)

Do NOT activate for: ACF (use the ACF abilities), generic WordPress meta operations (use the WordPress abilities), Bricks/Elementor builder data (use those builder abilities).

For a plugin-agnostic representation of the site's content model (snapshots, audits, cross-plugin migrations), activate the `content-model-schema` skill alongside this one.

## Discover before you act

Start every non-trivial JetEngine task with discovery:

1. `novamira/jetengine-check-setup` — verify JetEngine is active, version meets the minimum (3.7), and the user can manage. The `cct_module_active` flag tells you whether the 8 CCT abilities are callable.
2. `novamira/jetengine-list-meta-boxes` / `list-options-pages` / `list-ccts` — overview of what already exists. Note `editable: false` on PHP-registered records (read-only at runtime).
3. `novamira/jetengine-get-*` — fetch the full record for the entity you're about to modify. The returned shape is what `create-*` and `edit-*` accept, so a round-trip is safe.

## Routing — when both Novamira and JetEngine MCP servers are available

JetEngine ships its own MCP server at `jet-engine/v1/mcp`. Whether you (the agent) see its tools depends on your client config — the WordPress-level toggle is informational, not authoritative.

Check your own currently-available tools:

- If both `add-meta-box` (or `add-cct`, `add-options-page`) from JetEngine AND `novamira/jetengine-create-meta-box` (or the corresponding Novamira Pro create) are visible: **prefer the `novamira/*` tool** for output consistency with the rest of the Novamira ability surface.
- If only one of the two is visible: use it.
- For any operation that is not `create-*` (i.e. `list-*`, `get-*`, `edit-*`, `delete-*`, and all CCT record operations): always use Novamira Pro's. JetEngine's MCP does not expose these.

## UX hint when JetEngine MCP is enabled but not configured client-side

`jetengine-check-setup` is for diagnosing the JetEngine environment, not for routing decisions. Routing is done by inspecting your own available tool list (see above).

If `jetengine-check-setup` returns `jetengine_mcp_enabled: true` AND your tool list does not show JetEngine's `add-*` tools, surface this once to the user:

> Your site has JetEngine's MCP server enabled at the WordPress level, but it isn't configured in your AI client. If you also configure it (`<site>/wp-json/jet-engine/v1/mcp`), you'll gain access to `add-listing`, `add-query`, `add-relation`, `add-glossary` and other JetEngine tools that Novamira Pro doesn't cover.

## Edit semantics

Two patterns:

- **Type-level edit (meta-box, options-page, CCT type):** merge top-level keys, replace-on-array. Top-level keys you omit are left untouched; arrays you provide (`meta_fields`, `args.allowed_post_type`, `db_field_data`) replace atomically. Nested objects (`args`) merge by their own keys. To keep a record's name and only change its fields, pass `{ id, meta_fields: [...] }` — name is left as-is.
- **Record-level edit (CCT records via `edit-cct-record`):** pure field-by-field merge. Only the keys present in `values` are written; all other columns untouched.

To safely refactor a complex meta-box: fetch via `get-meta-box`, modify the returned shape locally, then send the modified payload to `edit-meta-box`. Round-trip is safe because get and create/edit share the same shape.

## Source awareness

Every meta-box, options-page, and CCT type can be registered via PHP using filters like `jet-engine/meta-boxes/data`. PHP-registered records:

- show up in `list-*` with `source: "php"` and `editable: false`
- `edit-*` and `delete-*` refuse with `<entity>_not_editable`
- to modify them, edit the source PHP file via `novamira/read-file` and `novamira/edit-file` / `novamira/write-file`

Read operations (`list-*`, `get-*`) work uniformly on every source.

## CCT type vs CCT record

These are different concepts with different abilities — do not confuse them:

- **CCT type** is the schema. Stored in option `jet-engine-cct-types` plus a dedicated DB table per type. Managed via `list-ccts`, `get-cct`, `create-cct`, `edit-cct`, `delete-cct`.
- **CCT record** is one row of data inside a CCT's table. Managed via `list-cct-records`, `get-cct-record`, `create-cct-record`, `edit-cct-record`, `delete-cct-record`.

`edit-cct` performs a schema migration; `edit-cct-record` updates a row's values.

### CCT schema migration constraints (`edit-cct`)

- Adding a field is OK (JetEngine adds the column).
- Removing a field is OK and destroys the column data.
- **Renaming a field is rejected** (`cct_field_rename_not_supported`) — would orphan data. Workaround: add new field, manually migrate via `wp/run-php`, drop old field.
- **Changing the SQL type of an existing field is rejected** (`cct_field_type_change_not_supported`).
- **Toggling `is_key_field` on an existing field is rejected** (`cct_key_field_change_not_supported`) — it's a structural property.

### CCT delete with content

`delete-cct` refuses with `cct_has_content` and `{ records_count: N }` if the table has rows. Pass `force: true` to drop the table along with the type definition. There is no recovery; the records are gone.

## Key field semantics on CCT

A CCT may declare one of its fields as `is_key_field: true` — the human-readable unique identifier (e.g. SKU on a Product CCT). When a key field is set:

- `get-cct-record`, `edit-cct-record`, `delete-cct-record` accept either `_ID` (auto-increment PK) or `key_field_value` (the value of the key field).
- `create-cct-record` rejects with `cct_record_key_collision` if the supplied key value duplicates an existing row.

When no field is flagged `is_key_field: true`:

- Only `_ID` is accepted as the selector.
- Passing `key_field_value` returns `cct_no_key_field`.

## Idempotency

`create-meta-box`, `create-options-page`, and `create-cct` are idempotent on **identical** payloads with the same id/slug — calling them again is a no-op and returns the existing record. A different payload with the same id/slug is rejected with `<entity>_id_exists` or `cct_slug_exists`.

`create-cct-record` is **not** idempotent: each call inserts a new row.

## Typical workflow

For most JetEngine tasks the canonical sequence is:

1. **Discover** — `check-setup` → `list-*` → `get-*` for the relevant entity.
2. **Compose** — locally build the new payload from the discovered shape.
3. **Write** — `create-*` for new entities, `edit-*` for in-place updates.
4. **Verify** — `get-*` to confirm the change persisted.

Skip steps you do not need. For a known atomic edit (e.g. add one field to an existing meta-box), the sequence collapses to `get-meta-box → edit-meta-box → get-meta-box`.

## `wp/run-php` patterns for entities not covered

The 21 abilities cover meta boxes, options pages, CCT types, and CCT records. For other JetEngine entities — Query Builder queries, Listings, Relations, Glossaries — use `novamira/run-php` with the patterns below. These are stable in JetEngine 3.7+; if the user reports a different signature on a newer version, adapt.

These recipes are patterns, not implementations: take the structure, compose your specific call with the user's parameters, send through `novamira/run-php`.

### 1. Execute a Query Builder query and return its rows

```php
$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );
if ( ! $query ) {
    return [ 'error' => 'query_not_found', 'query_id' => $query_id ];
}
$items = $query->get_items();
return [ 'count' => count( $items ), 'items' => $items ];
```

### 2. Inventory all listings (the Listings post type is `jet-engine`)

```php
$listings = get_posts( [
    'post_type'      => 'jet-engine',
    'posts_per_page' => -1,
    'post_status'    => [ 'publish', 'draft' ],
    'fields'         => 'ids',
] );
return array_map( static function ( int $id ): array {
    $data = get_post_meta( $id, '_listing_data', true );
    $data = is_array( $data ) ? $data : [];
    return [
        'id'         => $id,
        'title'      => get_the_title( $id ),
        'query_id'   => (int) ( $data['query_id'] ?? 0 ),
        'item_class' => (string) ( $data['item_class'] ?? '' ),
    ];
}, $listings );
```

### 3. Read the active relations registry

```php
$relations = jet_engine()->relations->get_active_relations();
return array_map( static fn( $r ): array => [
    'id'             => $r->get_id(),
    'parent_object'  => $r->get_args( 'parent_object' ),
    'child_object'   => $r->get_args( 'child_object' ),
    'parent_objects' => $r->get_args( 'parent_objects' ),
    'child_objects'  => $r->get_args( 'child_objects' ),
    'type'           => $r->get_args( 'type' ),
], $relations );
```

### 4. Attach a child item to a parent in a relation (`update` upserts the link)

```php
$relation = jet_engine()->relations->get_active_relations()[ $relation_id ] ?? null;
if ( ! $relation ) {
    return [ 'error' => 'relation_not_found', 'relation_id' => $relation_id ];
}
$relation->update( $parent_id, $child_id );
return [ 'attached' => true, 'parent' => $parent_id, 'child' => $child_id ];
```

### 5. Detach a child from a parent

```php
$relation = jet_engine()->relations->get_active_relations()[ $relation_id ] ?? null;
if ( ! $relation ) {
    return [ 'error' => 'relation_not_found' ];
}
$relation->delete_rows( [ 'parent_object_id' => $parent_id, 'child_object_id' => $child_id ] );
return [ 'detached' => true ];
```

### 6. List all glossaries

```php
$glossaries = jet_engine()->glossaries->settings->get();
return array_map( static fn( $g ): array => [
    'id'           => $g['id'] ?? null,
    'name'         => $g['name'] ?? '',
    'source'       => $g['source'] ?? 'manual',
    'fields_count' => isset( $g['fields'] ) ? count( $g['fields'] ) : 0,
], $glossaries );
```

### 7. Read a glossary's items by id

```php
$glossaries = jet_engine()->glossaries->settings->get();
$glossary   = current( array_filter( $glossaries, static fn( $g ): bool => ( $g['id'] ?? null ) === $glossary_id ) );
return $glossary['fields'] ?? [];
```

## Ability quick map

| Ability | Use |
| --- | --- |
| `novamira/jetengine-check-setup` | Verify JetEngine is active, version check, module flags |
| `novamira/jetengine-list-meta-boxes` | Enumerate all meta boxes |
| `novamira/jetengine-get-meta-box` | Fetch a single meta box by id |
| `novamira/jetengine-create-meta-box` | Create a new meta box |
| `novamira/jetengine-edit-meta-box` | Edit an existing meta box (merge top-level / replace arrays) |
| `novamira/jetengine-delete-meta-box` | Delete a meta box |
| `novamira/jetengine-list-options-pages` | Enumerate all options pages |
| `novamira/jetengine-get-options-page` | Fetch a single options page by id |
| `novamira/jetengine-create-options-page` | Create a new options page |
| `novamira/jetengine-edit-options-page` | Edit an existing options page |
| `novamira/jetengine-delete-options-page` | Delete an options page |
| `novamira/jetengine-list-ccts` | Enumerate all CCT types |
| `novamira/jetengine-get-cct` | Fetch a single CCT type by slug |
| `novamira/jetengine-create-cct` | Create a new CCT type |
| `novamira/jetengine-edit-cct` | Migrate a CCT schema (add/remove fields) |
| `novamira/jetengine-delete-cct` | Delete a CCT type (use `force: true` to drop rows too) |
| `novamira/jetengine-list-cct-records` | List rows inside a CCT table |
| `novamira/jetengine-get-cct-record` | Fetch one CCT row by `_ID` or `key_field_value` |
| `novamira/jetengine-create-cct-record` | Insert a new CCT row |
| `novamira/jetengine-edit-cct-record` | Update fields on a CCT row (field-by-field merge) |
| `novamira/jetengine-delete-cct-record` | Delete a CCT row |

## What this skill is not

- It is **not** a guide for ACF field groups. For that, use the ACF abilities.
- It is **not** a guide for Bricks or Elementor builder data. Use those builder abilities.
- It is **not** a schema dump. Use `get-meta-box`, `get-options-page`, or `get-cct` for concrete field shapes.
