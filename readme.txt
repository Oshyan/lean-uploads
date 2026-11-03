=== Lean Uploads ===
Contributors: oshyan
Tags: webp, avif, images, optimization, uploads
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts uploaded images to WebP or AVIF, caps their dimensions, and generates thumbnails in the same format. Replaces the original instead of keeping it.

== Description ==

Lean Uploads treats the file you upload as what it is: a display copy. On upload, JPEG, PNG, BMP and TIFF images are converted to WebP (or AVIF where available), scaled down to a maximum size, and stored in that form. Every thumbnail size is generated in the same format. The original is not kept.

* Conversion rides on WordPress's own image-size generation, so it covers the Media Library, the block editor, the REST API, sideloads and WP-CLI imports. The full size and every thumbnail are each one encode from the original; only then is the original removed.
* Embedded ICC profiles are preserved by default and verified in saved outputs, addressing profile loss in native WordPress PNG resizing and GD processing. Tagged images never silently fall back to GD.
* Optional real sRGB conversion runs when needed on uploads, or on selected existing assets through the Media Library and WP-CLI. Untagged images are not guessed; existing-file normalization preserves URLs, formats and dimensions. Color normalization can enlarge files or reduce wide-gamut colors.
* HEIC uploads are converted too, where the server can decode them.
* WebP and AVIF uploads are only re-encoded for resizing or opt-in sRGB conversion. SVG is never re-encoded. Animated GIFs are never touched.
* A file whose name contains `--keep` is stored exactly as uploaded.
* If a conversion fails, the original goes through and you are told.
* A file is not replaced with a larger one unless it was resized or deliberately color-normalized.
* The Media Library gets a Format column with a Convert link per image and a bulk action.
* Tools > Lean Uploads converts the existing library in batches (scan, progress, pause, resume, dry run), optionally rewriting old URLs in posts, meta and options. `wp lean-uploads convert --all` does the same from the command line.
* Originals are only removed for new uploads and for conversions you run yourself; regenerations triggered by other plugins or by core never delete an original.

Nothing depends on the web server. No .htaccess or nginx rules, so it works on hosts that serve static files directly.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it. It refuses to activate if the server's image library can write neither WebP nor AVIF.
3. Review the defaults under Settings > Media.
4. Optionally run `wp lean-uploads self-test`.

== Frequently Asked Questions ==

= Does it keep my original files? =

No. Keep your source files outside WordPress. Use the `--keep` token in a file name for the rare upload that must stay as is.

= Will my old images be converted? =

Not automatically. Go to Tools > Lean Uploads, click Scan, tick Dry run and Start to see what would change, then run it for real. With WP-CLI: `wp lean-uploads convert --all --dry-run`, then without `--dry-run`; add `--update-references` to rewrite old URLs in content.

= Which quality should I use? =

75 is the default and is visually transparent for photos. Raise it if you are picky, lower it if you are not.

== Changelog ==

= 0.5.0 =
* Default ICC preservation and saved-file verification, including intermediates and thumbnails; block unsafe GD fallback.
* Optional real sRGB normalization for new uploads and selective existing-file normalization through the Media Library and WP-CLI.
* Verified staging, rollback and recovery journal for existing-file normalization; synthetic color regression tests.

= 0.4.0 =
* Default-on quality enforcement with an opt-out under Settings > Media, scoped to WebP/AVIF processing. JPEG quality and unrelated image edits are unchanged.
* Preserve explicit fallback-conversion quality across WordPress format changes and retain lossless conversion intent.
* Add unit and WordPress integration coverage for quality precedence.

= 0.3.1 =
* Fixes from a second security review: new uploads never reuse the base name of a converted earlier upload (core only checks the uploaded extension, so thumbnails could be overwritten); oversized originals are moved aside and put back if a conversion fails; originals are kept when no thumbnails can be generated; bulk conversion of core-scaled attachments converts from the true original; URL rewriting is bounded, boundary-aware and limited to upload URLs; bulk runs take a lock and save progress per image.

= 0.3.0 =
* Tools > Lean Uploads: scan and convert the existing library in batches, with pause, resume and dry run.
* URL rewriting after conversion works from the admin as well as WP-CLI, serialization-safe.

= 0.2.0 =
* Separate quality settings for WebP and AVIF.
* Originals are only removed for uploads and for conversions you run; other regenerations never delete originals.
* Safer bulk conversion (unique names, transactional metadata), uploads-directory containment, no technical detail in notices.

= 0.1.0 =
* First release.
