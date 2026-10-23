# Lean Uploads

A WordPress plugin that converts every uploaded image to WebP (or AVIF), scales it down to a sane maximum size, and generates all the thumbnails in the same format. The original upload is replaced, not kept alongside.

## Why I built it

Every "image optimizer" plugin I've tried makes the same timid choice: it adds a WebP copy next to the original and leaves the original in place. So the Media Library still hands out the 3MB PNG whenever someone copies an image URL, the uploads folder doubles in size, and the host keeps billing for it. Even WordPress core's own WebP support only touches the generated sizes, never the file you uploaded.

That caution comes from treating WordPress as an archive. It isn't one. The file you upload to a website is a display copy; your source files live in Lightroom, Figma, a NAS, wherever you made them. Once you accept that, the right behavior is obvious: convert on the way in, cap the dimensions, throw the original away. WebP at quality 75 is visually indistinguishable from a JPEG for photos at a third of the size, and it handles transparency, so there's no reason for a PNG to ever land in `wp-content/uploads` again.

So this plugin does that, by default, for everyone, and gives you an escape hatch for the rare file that really does need to stay as uploaded.

## What it does

- **Converts originals at upload time.** JPEG, PNG, BMP, TIFF and HEIC (where the server can decode it) become WebP (or AVIF where the server can encode it). The conversion happens before WordPress moves the file into the uploads folder, so the original never touches disk. Works for the Media Library, the block editor, the REST API, `media_sideload_image()`, and `wp media import`, because all of them go through the same core hook.
- **Caps dimensions.** Anything larger than the maximum (default 2560px, the same threshold WordPress uses for its `-scaled` copies) is scaled down to fit before encoding. Set it to 0 to disable.
- **Thumbnails in the same format.** Every registered image size is generated as WebP/AVIF at the same quality, including for images that were uploaded before the plugin or opted out.
- **Already-efficient uploads are left alone.** WebP and AVIF are never re-encoded, only resized if they exceed the cap. SVG is never touched. Animated GIFs are never touched.
- **An opt-out that teaches.** Put `--keep` anywhere in a file name and that file is stored exactly as uploaded, with the token removed from the saved name. `logo--keep.png` becomes `logo.png`, untouched, and its thumbnails stay PNG too. The attachment is flagged so later regenerations respect it.
- **Never makes a file bigger.** If the converted file wouldn't be smaller and nothing was resized, the original is kept (filterable). Images above 120 megapixels are left alone rather than risk PHP's limits.
- **Never blocks an upload.** If conversion fails for any reason, the original goes through and the uploader sees a notice saying so. When Imagick can't hold an image in its pixel cache, GD takes over automatically.
- **Media Library integration.** A Format column shows each image's type and size with a one-click Convert link, plus a bulk action for selected images.
- **Bulk conversion for the existing library** via WP-CLI, with a dry run and optional database-wide URL rewriting (serialization-safe, it delegates to `wp search-replace`).

## Settings

Under **Settings > Media**. There are intentionally few:

| Setting | Default | Notes |
|---|---|---|
| Convert uploads | on | The master switch for originals. |
| Output format | WebP | AVIF is offered when the server's image library can write it, and falls back to WebP if not. |
| Quality | 75 | Lossy quality for originals and thumbnails. |
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
wp lean-uploads convert --all --dry-run
wp lean-uploads convert --all --update-references
wp lean-uploads convert 123 456
```

`self-test` pushes generated images through the real upload pipeline into the Media Library, checks the results (format, extension, dimensions, thumbnail formats, opt-out), and deletes everything it created. Run it after activating on a new host.

`convert` replaces each attachment's file in place, regenerates its sizes, deletes the old files, and with `--update-references` rewrites every old URL (original and each size) to the new one across all tables. Do a `--dry-run` first and have a backup; this is a one-way door by design.

## Requirements

WordPress 6.1 or newer, PHP 7.4 or newer, and an image library that can write WebP: Imagick or GD, which nearly every host has. AVIF needs WordPress 6.5 and a newer Imagick or GD build. The plugin refuses to activate if it can write neither format, rather than silently doing nothing.

It uses WordPress's own image editor for the actual encoding, so it behaves the same as thumbnail generation does on your host and respects the `wp_image_editors` filter. Nothing depends on the web server: no `.htaccess` rules, no nginx config, no CDN cooperation. That's deliberate; hosts that serve static files straight from nginx (WP Engine, among others) break every rewrite-based WebP plugin, and this one doesn't care.

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

Early. It's been exercised on a WP Engine development install (Imagick, WordPress 7.1) through the self-test, WP-CLI imports and the bulk converter; the browser upload path shares the same hook but has had less mileage. I'd particularly like to hear from anyone on a host where AVIF is available, or where the activation check refuses to run.

License: GPL-2.0-or-later.
