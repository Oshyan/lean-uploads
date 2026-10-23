=== Lean Uploads ===
Contributors: oshyan
Tags: webp, avif, images, optimization, uploads
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts uploaded images to WebP or AVIF, caps their dimensions, and generates thumbnails in the same format. Replaces the original instead of keeping it.

== Description ==

Lean Uploads treats the file you upload as what it is: a display copy. On upload, JPEG, PNG, BMP and TIFF images are converted to WebP (or AVIF where available), scaled down to a maximum size, and stored in that form. Every thumbnail size is generated in the same format. The original is not kept.

* Conversion happens before WordPress moves the file into the uploads folder, so it covers the Media Library, the block editor, the REST API, sideloads and WP-CLI imports.
* WebP, AVIF and SVG uploads are never re-encoded. Animated GIFs are never touched.
* A file whose name contains `--keep` is stored exactly as uploaded.
* If a conversion fails, the original goes through and you are told.
* A file is never replaced with a larger one unless it was also resized.
* The Media Library gets a Format column with a Convert link per image and a bulk action.
* `wp lean-uploads convert --all` converts the existing library in place, with a dry run and optional URL rewriting across the database.

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

Not automatically. Run `wp lean-uploads convert --all --dry-run` to see what would change, then without `--dry-run` to do it. Add `--update-references` to rewrite old URLs in content.

= Which quality should I use? =

75 is the default and is visually transparent for photos. Raise it if you are picky, lower it if you are not.

== Changelog ==

= 0.2.0 =
* Separate quality settings for WebP and AVIF.
* Originals are only removed for uploads and for conversions you run; other regenerations never delete originals.
* Safer bulk conversion (unique names, transactional metadata), uploads-directory containment, no technical detail in notices.

= 0.1.0 =
* First release.
