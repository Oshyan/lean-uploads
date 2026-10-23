# Integration checks

These run against a real WordPress install with the plugin active, via WP-CLI:

```bash
wp eval-file tests/integration/verify-pipeline.php
wp eval-file tests/integration/verify-bulk.php
```

Each script creates its own fixtures (attachment, draft post, post meta, an option), exercises the plugin, and deletes only what it created, by the IDs it received when creating them. They never select records by "newest" or by pattern, and they restore any bulk-run state the site had. Run them on a staging or personal site, never on a production site.
