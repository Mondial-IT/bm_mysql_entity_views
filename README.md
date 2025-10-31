# MySQL Entity Views (Drupal 11)

Flatten Drupal content entities into **read-only MySQL VIEWs**, one per bundle. Great for BI/reporting tools that prefer a “single wide table” instead of many JOINs across `node` + `field_*` tables.

* ✅ **Drupal**: 11.2.x
* ✅ **PHP**: 8.3+
* ✅ **DB**: MySQL / MariaDB only
* ✅ **Extras**: Settings UI, deterministic ordering, meta-views with column “comments”

---

## What it creates

For each bundle, a view:

```
view_<entity_type>__<bundle>
# e.g. view_node__article, view_media__image
```

* Base columns: `id, langcode, type, [title], uid, status, created, changed`
* One column per field (multi-value fields are **GROUP_CONCAT**’ed)

And (optionally) a companion **meta view**:

```
view_<entity_type>__<bundle>__meta (column_name, comment, source)
```

* Sources comments from Drupal Field UI (label + description) and sensible defaults for base cols.

---

## Install

### With Composer (recommended)

```bash
composer require bluemarloc/mysql_entity_views
drush en mysql_entity_views -y
```

> The module auto-builds views on install.

### Manually

Copy the `mysql_entity_views/` directory into `web/modules/custom/` (or `modules/custom/`) and enable:

```bash
drush en mysql_entity_views -y
```

---

## Configure

**UI:** Configuration → Development → **MySQL Entity Views**

* **GROUP_CONCAT separator** (default: `/`)
* **group_concat_max_len** (default: `1048576`)
* **Ordering of multi-values**: `delta` (original entry order), `value`, or `none`
* **Create meta views**: on/off

Use **Add/Update** buttons per bundle or **Rebuild all**.

---

## Usage examples (SQL)

List the 100 latest Articles:

```sql
SELECT id, langcode, title, status, created, field_tags
FROM view_node__article
ORDER BY created DESC
LIMIT 100;
```

See column documentation:

```sql
SELECT *
FROM view_node__article__meta
ORDER BY column_name;
```

Join data and documentation:

```sql
SELECT a.*, m.comment
FROM view_node__article a
LEFT JOIN view_node__article__meta m
  ON m.column_name = 'field_tags';
```

---

## Notes & Limitations

* **Read-only**: VIEWs are projections; do not use them to write to Drupal data.
* **MySQL/MariaDB only**: Other drivers are ignored.
* **Schema drift**: After adding/removing fields, click **Rebuild** (or run Drush task below).
* **Truncation**: Very long multi-value fields can hit `group_concat_max_len`. Increase in settings if needed.
* **Deterministic concatenation**: Ordering is configurable; default is `delta`.

---

## Drush snippets

Rebuild all views (via the route controller):

```bash
drush ev "\Drupal::service('mysql_entity_views.generator')->rebuildAll();"
```

Drop all views created by this module:

```bash
drush ev "\Drupal::service('mysql_entity_views.generator')->dropAll();"
```

---

## Performance tips

* Add selective indexes in your BI database or replicate to a read-replica.
* Use `LIMIT/OFFSET` or date windows in BI connectors.
* For extremely wide entities, consider exporting to a data mart (ETL) rather than querying VIEWs live.

---

## Security

* Expose these VIEWs only to trusted readers.
* Keep Drupal ACL/permissions concerns in mind: VIEWs bypass Drupal access checks. For per-user/row security, export through the **JSON:API** or build a controlled ETL.

---

## Compatibility

* Tested on **Drupal 11.2.x** + **PHP 8.3** with MySQL/MariaDB.
* Works for all *content* entity types that have a base table and bundles (nodes, media, taxonomy terms with bundles, etc.).

---

## Contributing

PRs welcome! Popular requests:

* Include field metadata (type, required, cardinality, target entity) in meta-views
* Per-field custom separators
* Exclude fields / include-only lists
* CLI Drush commands (wrappers)

---

## License

GPL-2.0-or-later (compatible with Drupal).

---
