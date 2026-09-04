<?php

namespace lrtc_webp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convert a file after WordPress has placed it in uploads, so the Media
 * picker stores and shows the WebP. Generated sizes then inherit WebP.
 */
class Upload
{
    public static function init() {
        add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_upload' ), 20, 2 );
        add_filter( 'image_editor_output_format', array( __CLASS__, 'output_format' ), 10, 3 );
        add_filter( 'wp_editor_set_quality', array( __CLASS__, 'set_quality' ), 10, 2 );
    }

    /**
     * @param array  $upload
     * @param string $context
     * @return array
     */
    public static function handle_upload( $upload, $context ) {
        unset( $context );

        if ( ! Settings::convert_uploads_enabled() ) {
            return $upload;
        }

        if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
            return $upload;
        }

        if ( strpos( (string) $upload['type'], 'image/' ) !== 0 ) {
            return $upload;
        }

        $converted = Converter::convert_path( $upload['file'], $upload['type'] );
        if ( is_wp_error( $converted ) ) {
            return $upload;
        }

        $old_file = $upload['file'];
        $new_file = $converted['file'];

        $upload['file'] = $new_file;
        $upload['type'] = $converted['mime'];
        if ( ! empty( $upload['url'] ) ) {
            $upload['url'] = str_replace(
                rawurlencode( wp_basename( $old_file ) ),
                rawurlencode( wp_basename( $new_file ) ),
                $upload['url']
            );
            $upload['url'] = str_replace(
                wp_basename( $old_file ),
                wp_basename( $new_file ),
                $upload['url']
            );
        }

        return $upload;
    }

    /**
     * @param array  $formats
     * @param string $filename
     * @param string $mime_type
     * @return array
     */
    public static function output_format( $formats, $filename, $mime_type ) {
        unset( $filename, $mime_type );

        if ( ! Settings::convert_uploads_enabled() ) {
            return $formats;
        }

        if ( ! is_array( $formats ) ) {
            $formats = array();
        }

        $formats['image/jpeg'] = 'image/webp';
        $formats['image/jpg']  = 'image/webp';

        if ( ! Settings::skip_transparent_png() ) {
            $formats['image/png'] = 'image/webp';
        }

        if ( ! Settings::skip_animated_gif() ) {
            $formats['image/gif'] = 'image/webp';
        }

        return $formats;
    }

    /**
     * @param int    $quality
     * @param string $mime_type
     * @return int
     */
    public static function set_quality( $quality, $mime_type ) {
        if ( $mime_type === 'image/webp' && Settings::convert_uploads_enabled() ) {
            return Settings::quality();
        }

        return $quality;
    }
}
