=== Lean Uploads ===
Contributors: oshyan
Tags: webp, avif, images, optimization, uploads
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts uploaded images to WebP or AVIF, caps their dimensions, and generates thumbnails in the same format. Replaces the original instead of keeping it.

== Description ==

Lean Uploads treats the file you upload as what it is: a display copy. On upload, JPEG, PNG, BMP and TIFF images are converted to WebP (or AVIF where available), scaled down to a maximum size, and stored in that form. Every thumbnail size is generated in the same format. The original is not kept.

* Conversion rides on WordPress's own image-size generation, so it covers the Media Library, the block editor, the REST API, sideloads and WP-CLI imports. The full size and every thumbnail are each one encode from the original; only then is the original removed.
* HEIC uploads are converted too, where the server can decode them.
* WebP, AVIF and SVG uploads are never re-encoded. Animated GIFs are never touched.
* A file whose name contains `--keep` is stored exactly as uploaded.
* If a conversion fails, the original goes through and you are told.
* A file is never replaced with a larger one unless it was also resized.
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
