# Changelog

## 0.2.0

- per-format quality: separate WebP and AVIF settings (the old single value migrates to WebP)
- originals are only removed for attachments the plugin was explicitly handed (new uploads, its own bulk/convert actions); core's regenerate paths, which any uploader can trigger, no longer convert or delete originals
- the lossless intermediate no longer changes which per-type rules apply to an oversized upload
- bulk conversion gives each attachment a base name unique for the target extension first, so two legacy files sharing a name cannot overwrite each other's thumbnails
- bulk conversion restores previous metadata and deletes nothing when regeneration comes back incomplete
- target names are claimed exclusively before renaming; all deletes confined to the uploads directory
- opt-out flag no longer leaks to the next upload when core rejects the opted-out file
- animated GIF detection no longer misses a frame marker on a read-chunk boundary
- self-test uses exclusively created temp files and deletes only those
- failure notices no longer expose encoder messages or server paths
- Media Library bulk action capped at 25 items per request; result notice limited to the Media screen
- uninstall removes the option on every site of a network

## 0.1.0

- convert JPEG, PNG, BMP and TIFF uploads to WebP or AVIF and delete the original
- full size and all thumbnails are cut from the original (one encode each), never from the compressed full
- cap the longest side (default 2560px): oversized images are resampled once to a lossless intermediate first
- generate all registered image sizes in the output format at the configured quality
- `--keep` file-name token to store a file as uploaded
- Settings > Media section with server capability readout
- WP-CLI: `status`, `self-test`, `convert` (bulk, dry run, URL rewriting)
- admin notice when a conversion fails and the original is kept
- opted-out uploads are flagged and exempt from thumbnail format mapping, so they stay as uploaded
- never replace a file with a larger one unless it was also resized
- 120-megapixel ceiling; GD fallback when Imagick's pixel cache can't hold the image
- Media Library Format column with per-image Convert link and a bulk action
- HEIC as a convertible source where the server can decode it
