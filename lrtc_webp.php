<?php
/**
 * Plugin Name:       LRTC WebP
 * Plugin URI:        https://littleridge.ca/lrtc_webp
 * Description:       Convert WordPress image uploads to WebP using the server’s Imagick or GD editor. Does not ship ImageMagick.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            Little Ridge Tech Consulting
 * Author URI:        https://littleridge.ca
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lrtc-webp
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LRTC_WEBP_VERSION', '0.1.0' );
define( 'LRTC_WEBP_FILE', __FILE__ );
define( 'LRTC_WEBP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LRTC_WEBP_URL', plugin_dir_url( __FILE__ ) );

require_once LRTC_WEBP_DIR . 'includes/class-capabilities.php';
require_once LRTC_WEBP_DIR . 'includes/class-settings.php';
require_once LRTC_WEBP_DIR . 'includes/class-converter.php';
require_once LRTC_WEBP_DIR . 'includes/class-library.php';
require_once LRTC_WEBP_DIR . 'includes/class-upload.php';
require_once LRTC_WEBP_DIR . 'includes/class-plugin.php';

lrtc_webp\Plugin::init();
