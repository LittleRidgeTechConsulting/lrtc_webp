<?php
/**
 * Remove plugin options when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'lrtc_webp_settings' );
delete_option( 'lrtc_webp_library_job' );
