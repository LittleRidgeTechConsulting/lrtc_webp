<?php

namespace lrtc_webp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstrap. Standalone — spruce is optional styling only.
 */
class Plugin
{
    const TEXT_DOMAIN = 'lrtc-webp';

    private static $booted = false;

    public static function init() {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
        Settings::init();
        Library::init();
        Upload::init();
    }

    public static function load_textdomain() {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname( plugin_basename( LRTC_WEBP_FILE ) ) . '/languages'
        );
    }

    /**
     * True when the Spruce foundation is active. Used only for admin chrome.
     */
    public static function spruce_is_active() {
        return defined( 'SPRUCE' ) || class_exists( '\\spruce\\spruce' );
    }
}
