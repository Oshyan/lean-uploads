# Changelog

## 0.3.1

Second security review; everything it found is fixed here.

- a new upload whose base name matches an earlier upload that was converted (say, a second `photo.jpg` in the same month after the first became `photo.webp`) now gets a numbered name; core only checks the uploaded extension, so its thumbnails would have overwritten the first attachment's
- an oversized original is moved aside while the lossless intermediate stands in for it, and put back byte-for-byte if the conversion fails; before, a failed attempt left a downscaled PNG under the original's name
- when no thumbnails can be generated for a converted image, the original is kept beside it (core's own "-scaled" arrangement) instead of being deleted; bulk conversion also never deletes a converted file that has become the only copy
- bulk conversion of a legacy attachment that core had scaled converts from the true original rather than the lossy "-scaled" copy, and cleans up both afterwards
- URL rewriting: nesting depth is bounded, values with surrounding whitespace are treated as serialized (as WordPress does), PHP enum tokens pass through, replacement stops at a name boundary (`.../x` no longer touches `.../xylophone.jpg`, `photo.jpg.webp` siblings are left alone), only URLs under the uploads URL are ever rewritten, tables are scanned in pages, and JSON-escaped (`\/`) forms of the URLs are rewritten too
- bulk runs take a lock so two tabs cannot process the same attachment at once; progress is saved per attachment, and an attachment a dead request left unfinished is reported as failed once instead of retried forever
- the unique-name move before bulk conversion claims its target exclusively; a WebP or AVIF file that only needs resizing keeps its name instead of being renamed to "-1"
- animated GIF detection no longer counts a frame marker twice when it ends exactly on a read boundary
- mime type updates write the one column instead of re-saving the attachment through the content filters
- Tools page: JSON printed into the inline script is HTML-safe whatever it contains; clearer messages for an expired session or a run already active in another tab

## 0.3.0

- Tools > Lean Uploads: scan the library, convert it in batches from the browser with progress, pause/resume and dry run (administrators only)
- URL rewriting in PHP for both the Tools page and WP-CLI: serialization-safe without unserializing stored data, scoped to the current site, with targeted cache invalidation instead of a global flush
- `wp lean-uploads scan`

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
