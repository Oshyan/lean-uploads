# Lean Uploads

A WordPress plugin that converts every uploaded image to WebP (or AVIF), scales it down to a sane maximum size, and generates all the thumbnails in the same format. The original upload is replaced, not kept alongside.

## Why I built it

Every "image optimizer" plugin I've tried makes the same timid choice: it adds a WebP copy next to the original and leaves the original in place. So the Media Library still hands out the 3MB PNG whenever someone copies an image URL, the uploads folder doubles in size, and the host keeps billing for it. Even WordPress core's own WebP support only touches the generated sizes, never the file you uploaded.

That caution comes from treating WordPress as an archive. It isn't one. The file you upload to a website is a display copy; your source files live in Lightroom, Figma, a NAS, wherever you made them. Once you accept that, the right behavior is obvious: convert on the way in, cap the dimensions, throw the original away. WebP at quality 75 is visually indistinguishable from a JPEG for photos at a third of the size, and it handles transparency, so there's no reason for a PNG to ever land in `wp-content/uploads` again.

So this plugin does that, by default, for everyone, and gives you an escape hatch for the rare file that really does need to stay as uploaded.

## What it does

- **Converts originals.** JPEG, PNG, BMP, TIFF and HEIC (where the server can decode it) become WebP (or AVIF where the server can encode it). Works for the Media Library, the block editor, the REST API, `media_sideload_image()`, and `wp media import`, because all of them end in the same core function that generates image sizes.
- **One generation, never two.** WordPress cuts the converted full-size image and every thumbnail from the uploaded original, each one encode away from the source. Only then is the original deleted. Thumbnails are never made from the already-compressed full size.
- **Caps dimensions, efficiently.** Anything larger than the maximum (default 2560px, the same threshold WordPress uses for its `-scaled` copies) is resampled once, losslessly, to the cap, and everything is derived from that. A 60-megapixel upload is decoded exactly once; on hosts where Imagick cannot hold it, GD does that one decode. Set the cap to 0 to disable.
- **Thumbnails in the same format.** Every registered image size is generated as WebP/AVIF at the same quality, including for images that were uploaded before the plugin.
- **Metadata handled the WordPress way.** EXIF orientation is applied, IPTC/EXIF title, caption and camera data are read into the attachment before the original goes, and the stored files carry no EXIF.
- **Already-efficient uploads are left alone.** WebP and AVIF are never re-encoded, only resized if they exceed the cap. SVG is never touched. Animated GIFs are never touched.
- **An opt-out that teaches.** Put `--keep` anywhere in a file name and that file is stored exactly as uploaded, with the token removed from the saved name. `logo--keep.png` becomes `logo.png`, untouched, and its thumbnails stay PNG too. The attachment is flagged so later regenerations respect it.
- **Never makes a file bigger.** If the converted file wouldn't be smaller and nothing was resized, the original is kept (filterable). Images above 120 megapixels are left alone rather than risk PHP's limits.
- **Never blocks an upload.** If conversion fails for any reason, the original is kept and the uploader sees a notice saying so.
- **Media Library integration.** A Format column shows each image's type and size with a one-click Convert link, plus a bulk action for selected images.
- **Bulk conversion of the existing library**, from Tools > Lean Uploads (scan, then convert in batches with a progress bar, pause and resume, dry run) or from WP-CLI. Each image is its own transaction. Old URLs in posts, meta and options can be rewritten to the new files; that rewrite is serialization-safe and never unserializes stored data (it walks the serialized format and fixes string lengths), so it cannot be used to instantiate objects.

## Settings

Under **Settings > Media**. There are intentionally few:

| Setting | Default | Notes |
|---|---|---|
| Convert uploads | on | The master switch for originals. |
| Output format | WebP | AVIF is offered when the server's image library can write it, and is only used if you choose it. Falls back to WebP if it turns out to be unavailable. |
| WebP quality | 75 | Lossy quality for WebP originals and thumbnails. |
| AVIF quality | 60 | AVIF's scale differs; 60 looks about like WebP 75. Only applies when AVIF is the output format. |
| Maximum dimension | 2560 px | Longest side. 0 disables. |
| Source formats | JPEG, PNG, BMP/TIFF on; GIF off | Lossless PNG conversion is available when Imagick is present. |
| Thumbnails | on | Generate all sizes in the output format. |
| Opt-out token | `--keep` | Empty disables the escape hatch. |

Developers get filters `lean_uploads_settings`, `lean_uploads_should_convert`, `lean_uploads_keep_smaller_original`, `lean_uploads_max_pixels`, and actions `lean_uploads_converted`, `lean_uploads_failed`, `lean_uploads_attachment_converted`.

## How it compares

I looked at the established plugins (Smush, ShortPixel, EWWW, Imagify, WebP Express, Converter for Media, and core's Modern Image Formats) before writing this. They all keep the original and add converted copies beside it, and most serve those copies through server rewrite rules or `<picture>` markup. Several have genuinely good ideas this plugin borrows: EWWW's "never create a larger image" rule, upload-time resizing, a Media Library column with per-image actions, WP-CLI for big libraries. What it deliberately does not borrow is the backup-and-restore machinery, because the premise here is that the uploaded file was never the master copy.

## WP-CLI

```bash
wp lean-uploads status
wp lean-uploads self-test
wp lean-uploads scan
wp lean-uploads convert --all --dry-run
wp lean-uploads convert --all --update-references
wp lean-uploads convert 123 456
```

`self-test` pushes generated images through the real upload pipeline into the Media Library, checks the results (format, extension, dimensions, thumbnail formats, opt-out), and deletes everything it created. Run it after activating on a new host.

`scan` is the Tools page summary in text form. `convert` replaces each attachment's file in place, regenerates its sizes, deletes the old files, and with `--update-references` rewrites every old URL (original and each size) to the new one in the site's posts, meta and options. Do a `--dry-run` first and have a backup; this is a one-way door by design. The Tools page does the same thing in batches of five from the browser, and is the better choice for anyone without shell access.

## Requirements

WordPress 6.1 or newer, PHP 7.4 or newer, and an image library that can write WebP: Imagick or GD, which nearly every host has. AVIF needs WordPress 6.5 and a newer Imagick or GD build. The plugin refuses to activate if it can write neither format, rather than silently doing nothing.

It uses WordPress's own image editor for the actual encoding, so it behaves the same as thumbnail generation does on your host and respects the `wp_image_editors` filter. Nothing depends on the web server: no `.htaccess` rules, no nginx config, no CDN cooperation. That's deliberate; hosts that serve static files straight from nginx (WP Engine, among others) break every rewrite-based WebP plugin, and this one doesn't care.

## Safety properties

- Originals are only removed for attachments the plugin was explicitly handed: new uploads, and attachments you convert through its own Media Library actions or WP-CLI command. Regenerations triggered any other way (core's own "create image subsizes" request, regeneration plugins, `wp media regenerate`) get thumbnails in the output format but never lose their original. That matters because core lets any user who can upload files trigger a regeneration of any attachment.
- Every delete and rename is confined to the uploads directory, and file names from attachment metadata are reduced to their base name before use.
- Target file names are claimed exclusively before anything is renamed over them, so two uploads differing only by extension cannot overwrite each other.
- Bulk conversion is a transaction per attachment: if regeneration comes back incomplete, the previous metadata is restored and no file is deleted.
- Failure notices show the file name only; technical detail goes to the error log (when `WP_DEBUG` is on) and the `lean_uploads_failed` action.

## What it doesn't do

- It doesn't optimize images referenced by URL in pasted HTML. If someone hotlinks a 6000px JPEG from somewhere else, that's still a 6000px JPEG.
- It doesn't keep the original. That is the point, but read it twice before enabling on a site where someone has been using the Media Library as the only copy of their photos. The `--keep` token and the "Convert uploads" switch are there for the transition.
- It doesn't strip or preserve metadata beyond what WordPress's editor does by default.

## Development

```bash
composer install
composer test
```

Unit tests cover the decision logic and settings sanitization without needing a WordPress install. The integration test is `wp lean-uploads self-test` against a real site.

## Status

Early. It's been exercised on two hosts (WP Engine and a self-managed CloudPanel VPS, both Imagick, WordPress 7.1): the self-test, WP-CLI imports, the bulk converter with URL rewriting, and real uploads through the browser uploader. I'd particularly like to hear from anyone on a GD-only host, or where the activation check refuses to run.

License: GPL-2.0-or-later.
