<?php

namespace lrtc_webp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What the server can actually write. We never ship ImageMagick or libwebp.
 */
class Capabilities
{
    /**
     * @return array
     */
    public static function report() {
        $imagick = self::imagick_status();
        $gd      = self::gd_status();

        return array(
            'imagick' => $imagick,
            'gd'      => $gd,
            'can_write_webp' => ( ! empty( $imagick['write_webp'] ) || ! empty( $gd['write_webp'] ) ),
            'preferred' => ! empty( $imagick['write_webp'] ) ? 'imagick' : ( ! empty( $gd['write_webp'] ) ? 'gd' : '' ),
        );
    }

    /**
     * @return bool
     */
    public static function can_write_webp() {
        $report = self::report();

        return ! empty( $report['can_write_webp'] );
    }

    /**
     * @return array
     */
    public static function imagick_status() {
        $present = class_exists( '\\Imagick' );
        $write   = false;
        $version = '';

        if ( $present && class_exists( 'WP_Image_Editor_Imagick' ) ) {
            $write = (bool) \WP_Image_Editor_Imagick::supports_mime_type( 'image/webp' );
        }

        if ( $present && class_exists( '\\Imagick' ) ) {
            try {
                $info = \Imagick::getVersion();
                if ( is_array( $info ) && ! empty( $info['versionString'] ) ) {
                    $version = (string) $info['versionString'];
                }
            } catch ( \Exception $e ) {
                $version = '';
            }
        }

        return array(
            'present'    => $present,
            'write_webp' => $write,
            'version'    => $version,
        );
    }

    /**
     * @return array
     */
    public static function gd_status() {
        $present = extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' );
        $write   = false;

        if ( $present && class_exists( 'WP_Image_Editor_GD' ) && function_exists( 'imagetypes' ) ) {
            $write = (bool) \WP_Image_Editor_GD::supports_mime_type( 'image/webp' );
        }

        // PHP 7.0.0–7.0.9 can have imagewebp() without the IMG_WEBP constant.
        if ( $present && ! $write && function_exists( 'imagewebp' ) ) {
            $write = true;
        }

        return array(
            'present'    => $present,
            'write_webp' => $write,
        );
    }
}
