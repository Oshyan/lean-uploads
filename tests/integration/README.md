# Integration checks

These run against a real WordPress install with the plugin active, via WP-CLI:

```bash
wp eval-file tests/integration/verify-pipeline.php
wp eval-file tests/integration/verify-bulk.php
wp eval-file tests/integration/verify-quality.php
wp eval-file tests/integration/verify-color-converter.php
wp eval-file tests/integration/verify-color-pipeline.php
wp eval-file tests/integration/verify-color-library.php
```

Each script creates its own fixtures (attachment, draft post, post meta, an option), exercises the plugin, and deletes only what it created, by the IDs it received when creating them. They never select records by "newest" or by pattern, and they restore any bulk-run state the site had. Run them on a staging or personal site, never on a production site.

The color suites use generated ICC profiles and synthetic pixels. They reopen saved outputs, exercise capped and uncapped conversions, verify pixel transforms and alpha, force unsafe fallback and profile-loss failures, and check byte-identical rollback. They require Imagick with LittleCMS; AVIF cases are reported as skipped if that encoder is unavailable. No client or device profiles belong in these fixtures.
