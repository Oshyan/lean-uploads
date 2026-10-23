# Changelog

## 0.1.0

- convert JPEG, PNG, BMP and TIFF uploads to WebP or AVIF before they are stored
- cap the longest side (default 2560px) before encoding
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
