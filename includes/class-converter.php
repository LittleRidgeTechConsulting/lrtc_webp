<?php

namespace lrtc_webp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convert one image file to WebP through WP_Image_Editor (Imagick or GD).
 */
class Converter
{
    /**
     * @param string $path            Absolute path to an image already in uploads.
     * @param string $mime            Mime type of that file.
     * @param bool   $delete_original When true (uploads), remove the source file after a successful write.
     * @return array|\WP_Error { file, mime } or error / skip reason.
     */
    public static function convert_path( $path, $mime, $delete_original = true ) {
        if ( ! Capabilities::can_write_webp() ) {
            return new \WP_Error( 'lrtc_webp_no_engine', __( 'This server cannot write WebP.', Plugin::TEXT_DOMAIN ) );
        }

        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return new \WP_Error( 'lrtc_webp_missing', __( 'The uploaded file was not found.', Plugin::TEXT_DOMAIN ) );
        }

        $mime = strtolower( (string) $mime );
        if ( $mime === 'image/webp' ) {
            return new \WP_Error( 'lrtc_webp_already', __( 'File is already WebP.', Plugin::TEXT_DOMAIN ) );
        }

        $skip = self::should_skip( $path, $mime );
        if ( $skip ) {
            return new \WP_Error( 'lrtc_webp_skip', $skip );
        }

        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return $editor;
        }

        $editor->set_quality( Settings::quality() );

        $dir      = dirname( $path );
        $basename = pathinfo( $path, PATHINFO_FILENAME );
        $filename = $basename . '.webp';
        // Library convert keeps the original, so the WebP must be a stable sibling
        // (photo.webp next to photo.jpg). wp_unique_filename() would create photo-1.webp
        // when that sibling already exists from a previous run, and rewrites then miss.
        if ( $delete_original ) {
            $filename = wp_unique_filename( $dir, $filename );
        }
        $dest = trailingslashit( $dir ) . $filename;

        $saved = $editor->save( $dest, 'image/webp' );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        $new_path = isset( $saved['path'] ) ? $saved['path'] : $dest;
        if ( ! file_exists( $new_path ) ) {
            return new \WP_Error( 'lrtc_webp_save', __( 'The WebP file was not written.', Plugin::TEXT_DOMAIN ) );
        }

        if (
            $delete_original
            && wp_normalize_path( $new_path ) !== wp_normalize_path( $path )
            && file_exists( $path )
        ) {
            wp_delete_file( $path );
        }

        return array(
            'file' => $new_path,
            'mime' => 'image/webp',
        );
    }

    /**
     * @param string $path
     * @param string $mime
     * @return string Empty when the file should be converted.
     */
    public static function should_skip( $path, $mime ) {
        $convertible = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );
        if ( ! in_array( $mime, $convertible, true ) ) {
            return __( 'Not a JPEG, PNG, or GIF.', Plugin::TEXT_DOMAIN );
        }

        if ( $mime === 'image/gif' && Settings::skip_animated_gif() && self::gif_is_animated( $path ) ) {
            return __( 'Animated GIF.', Plugin::TEXT_DOMAIN );
        }

        if ( $mime === 'image/png' && Settings::skip_transparent_png() && self::png_has_transparency( $path ) ) {
            return __( 'PNG has transparency.', Plugin::TEXT_DOMAIN );
        }

        return '';
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function gif_is_animated( $path ) {
        if ( class_exists( '\\Imagick' ) ) {
            try {
                $image = new \Imagick( $path );
                $count = $image->getNumberImages();
                $image->clear();
                $image->destroy();

                return $count > 1;
            } catch ( \Exception $e ) {
                // Fall through to a byte scan.
            }
        }

        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return true;
        }

        $frames = 0;
        while ( ! feof( $handle ) && $frames < 2 ) {
            $chunk = fread( $handle, 1024 * 64 );
            if ( $chunk === false ) {
                break;
            }
            $frames += substr_count( $chunk, "\x00\x21\xF9\x04" );
        }
        fclose( $handle );

        return $frames > 1;
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function png_has_transparency( $path ) {
        if ( class_exists( '\\Imagick' ) ) {
            try {
                $image = new \Imagick( $path );
                $alpha = method_exists( $image, 'getImageAlphaChannel' ) ? (bool) $image->getImageAlphaChannel() : false;
                $image->clear();
                $image->destroy();
                if ( ! $alpha ) {
                    return false;
                }
                // Alpha channel exists — confirm a pixel is actually transparent.
            } catch ( \Exception $e ) {
                // Fall through to GD.
            }
        }

        if ( ! function_exists( 'imagecreatefrompng' ) ) {
            return true;
        }

        $image = @imagecreatefrompng( $path );
        if ( ! $image ) {
            return true;
        }

        if ( imagecolortransparent( $image ) >= 0 ) {
            imagedestroy( $image );

            return true;
        }

        $width  = imagesx( $image );
        $height = imagesy( $image );
        $step_x = max( 1, (int) floor( $width / 24 ) );
        $step_y = max( 1, (int) floor( $height / 24 ) );

        for ( $x = 0; $x < $width; $x += $step_x ) {
            for ( $y = 0; $y < $height; $y += $step_y ) {
                $rgba  = imagecolorat( $image, $x, $y );
                $alpha = ( $rgba & 0x7F000000 ) >> 24;
                if ( $alpha > 0 ) {
                    imagedestroy( $image );

                    return true;
                }
            }
        }

        imagedestroy( $image );

        return false;
    }
}
