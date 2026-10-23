# Changelog

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
