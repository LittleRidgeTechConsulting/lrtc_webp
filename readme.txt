=== LRTC WebP ===
Contributors: littleridgetech
Tags: webp, images, media, performance, imagick
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert WordPress image uploads and existing Media Library files to WebP using the server’s Imagick or GD editor. Does not ship ImageMagick.

== Description ==

LRTC WebP replaces JPEG, PNG, and (optionally) GIF uploads with a WebP file before they are stored in the Media Library. The media picker then shows the WebP file, and WordPress generates sizes from that original.

It can also convert images already in the library. Attachment IDs stay the same. Original files are left on disk so any URL the plugin cannot rewrite still works.

This plugin does **not** bundle ImageMagick, Imagick, or libwebp. It uses WordPress’s `WP_Image_Editor` and whatever the host already provides:

* Imagick (the PHP extension), when it can write WebP
* GD with `imagewebp`, otherwise

Settings live under Settings → WebP:

* Convert uploads to WebP (off by default)
* Quality (1–100)
* Skip animated GIFs
* Skip PNGs that have transparency

A **Convert library** button on the same screen walks existing attachments in batches, updates their metadata, and rewrites URLs in post content, GUIDs, post meta, and options. Serialized values are unserialized before replacing. Leftovers are listed when a string could not be updated.

Developed by [Little Ridge Tech Consulting](https://littleridge.ca/). Compatible with the Spruce plugin suite but does not require Spruce.

== Installation ==

1. Upload the `lrtc_webp` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Settings → WebP and confirm Imagick or GD can write WebP.
4. Turn on “Convert uploads to WebP” if you want new uploads converted.

== Frequently Asked Questions ==

= Do I need to install ImageMagick? =

Only on the server, if you want Imagick. Many hosts already have it. This plugin will not install it. GD with WebP support is enough.

= What happens to animated GIFs? =

By default they are left as GIF. A single-frame GIF can still be converted.

= What about transparent PNGs? =

By default they are left as PNG so alpha is not flattened. Opaque PNGs are converted.

= Will this rewrite images already in the Media Library? =

Yes. Use **Convert library** on Settings → WebP. IDs stay the same. Original JPEG/PNG/GIF files are not deleted.

= What if a page still points at the old .jpg URL? =

That URL should still work because the original file is left in place. The plugin reports leftover database strings it could not rewrite.

== Changelog ==

= 0.1.1 =
* Write library URL rewrites through the database so Divi 5 JSON is not unslashed or filtered.
* Keep a stable photo.webp sibling instead of photo-1.webp on library convert.
* Walk library batches by attachment ID so converted files are not skipped.
* Rewrite JSON-escaped slashes in stored URLs.

= 0.1.0 =
* First release: server capability check, settings page, convert new uploads, and batched library conversion that keeps original files.

== Upgrade Notice ==

= 0.1.1 =
Fixes library convert corrupting Divi 5 page JSON and skipping remaining images.

= 0.1.0 =
Initial release.
