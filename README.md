# LRTC WebP

Convert WordPress image uploads to WebP using the server’s Imagick or GD editor. Developed by [Little Ridge Tech Consulting](https://littleridge.ca/).

This plugin does **not** ship ImageMagick, Imagick, or libwebp. It uses `WP_Image_Editor` and whatever the host already provides.

## Requirements

- WordPress 5.8+
- PHP 7.0+ (developed against 8.0-style code, tested with 7.1 in mind)
- Imagick with WebP **or** GD with `imagewebp`

## Settings

**Settings → WebP**

- Convert uploads to WebP (off by default)
- Quality (1–100, default 82)
- Skip animated GIFs
- Skip transparent PNGs

The Media picker stores and shows the WebP file when conversion succeeds.

**Convert library** on the same screen converts existing JPEG/PNG/GIF attachments in batches. IDs stay the same. Original files are not deleted. Post content, GUIDs, post meta, and options are rewritten when the string can be updated safely (including serialized PHP). Leftovers are listed.

## Spruce

Standalone. If [Spruce](https://littleridge.ca/spruce) is active, the settings screen picks up a light style hook only. Spruce is not required.

## License

GPL-2.0-or-later. See `LICENSE`.
