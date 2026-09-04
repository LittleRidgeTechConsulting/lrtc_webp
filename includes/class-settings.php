<?php

namespace lrtc_webp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings
{
    const OPTION = 'lrtc_webp_settings';
    const GROUP  = 'lrtc_webp_settings_group';
    const PAGE   = 'lrtc-webp';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( LRTC_WEBP_FILE ), array( __CLASS__, 'action_links' ) );
    }

    /**
     * @return array
     */
    public static function defaults() {
        return array(
            'convert_uploads'      => 0,
            'quality'              => 82,
            'skip_animated_gif'    => 1,
            'skip_transparent_png' => 1,
        );
    }

    /**
     * @return array
     */
    public static function get() {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return array_merge( self::defaults(), $stored );
    }

    /**
     * @return bool
     */
    public static function convert_uploads_enabled() {
        $settings = self::get();

        return ! empty( $settings['convert_uploads'] ) && Capabilities::can_write_webp();
    }

    /**
     * @return int
     */
    public static function quality() {
        $settings = self::get();
        $quality  = isset( $settings['quality'] ) ? (int) $settings['quality'] : 82;
        if ( $quality < 1 ) {
            return 1;
        }
        if ( $quality > 100 ) {
            return 100;
        }

        return $quality;
    }

    /**
     * @return bool
     */
    public static function skip_animated_gif() {
        $settings = self::get();

        return ! empty( $settings['skip_animated_gif'] );
    }

    /**
     * @return bool
     */
    public static function skip_transparent_png() {
        $settings = self::get();

        return ! empty( $settings['skip_transparent_png'] );
    }

    public static function add_page() {
        add_options_page(
            __( 'WebP', Plugin::TEXT_DOMAIN ),
            __( 'WebP', Plugin::TEXT_DOMAIN ),
            'manage_options',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register() {
        register_setting(
            self::GROUP,
            self::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize' ),
                'default'           => self::defaults(),
            )
        );

        add_settings_section(
            'lrtc_webp_status',
            __( 'Server support', Plugin::TEXT_DOMAIN ),
            array( __CLASS__, 'render_status_section' ),
            self::PAGE
        );

        add_settings_section(
            'lrtc_webp_uploads',
            __( 'Uploads', Plugin::TEXT_DOMAIN ),
            array( __CLASS__, 'render_uploads_intro' ),
            self::PAGE
        );

        add_settings_field(
            'convert_uploads',
            __( 'Convert uploads to WebP', Plugin::TEXT_DOMAIN ),
            array( __CLASS__, 'render_convert_uploads' ),
            self::PAGE,
            'lrtc_webp_uploads'
        );

        add_settings_field(
            'quality',
            __( 'WebP quality', Plugin::TEXT_DOMAIN ),
            array( __CLASS__, 'render_quality' ),
            self::PAGE,
            'lrtc_webp_uploads'
        );

        add_settings_field(
            'skip_animated_gif',
            __( 'Skip animated GIFs', Plugin::TEXT_DOMAIN ),
            array( __CLASS__, 'render_skip_animated_gif' ),
            self::PAGE,
            'lrtc_webp_uploads'
        );

        add_settings_field(
            'skip_transparent_png',
            __( 'Skip transparent PNGs', Plugin::TEXT_DOMAIN ),
            array( __CLASS__, 'render_skip_transparent_png' ),
            self::PAGE,
            'lrtc_webp_uploads'
        );
    }

    /**
     * @param mixed $value
     * @return array
     */
    public static function sanitize( $value ) {
        $defaults = self::defaults();
        if ( ! is_array( $value ) ) {
            $value = array();
        }

        $quality = isset( $value['quality'] ) ? (int) $value['quality'] : $defaults['quality'];
        if ( $quality < 1 ) {
            $quality = 1;
        }
        if ( $quality > 100 ) {
            $quality = 100;
        }

        $convert = ! empty( $value['convert_uploads'] ) ? 1 : 0;
        if ( $convert && ! Capabilities::can_write_webp() ) {
            $convert = 0;
            add_settings_error(
                self::OPTION,
                'lrtc_webp_no_engine',
                __( 'Uploads were not set to convert because this server cannot write WebP (Imagick or GD).', Plugin::TEXT_DOMAIN ),
                'error'
            );
        }

        return array(
            'convert_uploads'      => $convert,
            'quality'              => $quality,
            'skip_animated_gif'    => ! empty( $value['skip_animated_gif'] ) ? 1 : 0,
            'skip_transparent_png' => ! empty( $value['skip_transparent_png'] ) ? 1 : 0,
        );
    }

    /**
     * @param string $hook
     */
    public static function enqueue( $hook ) {
        if ( $hook !== 'settings_page_' . self::PAGE ) {
            return;
        }

        wp_enqueue_style(
            'lrtc-webp-admin',
            LRTC_WEBP_URL . 'assets/css/admin.css',
            array(),
            LRTC_WEBP_VERSION
        );

        Library::enqueue( $hook );
    }

    /**
     * @param array $links
     * @return array
     */
    public static function action_links( $links ) {
        $url = admin_url( 'options-general.php?page=' . self::PAGE );
        array_unshift(
            $links,
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', Plugin::TEXT_DOMAIN ) . '</a>'
        );

        return $links;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $classes = 'wrap lrtc-webp-wrap';
        if ( Plugin::spruce_is_active() ) {
            $classes .= ' lrtc-webp-wrap--spruce';
        }

        echo '<div class="' . esc_attr( $classes ) . '">';
        echo '<h1>' . esc_html__( 'WebP', Plugin::TEXT_DOMAIN ) . '</h1>';
        echo '<p class="lrtc-webp-lead">' . esc_html__( 'Convert Media Library images to WebP using the image editor already on this server. ImageMagick is not bundled with this plugin.', Plugin::TEXT_DOMAIN ) . '</p>';

        settings_errors( self::OPTION );

        echo '<form method="post" action="options.php">';
        settings_fields( self::GROUP );
        do_settings_sections( self::PAGE );
        submit_button();
        echo '</form>';
        Library::render_section();
        echo '</div>';
    }

    public static function render_status_section() {
        $report = Capabilities::report();
        echo '<div class="lrtc-webp-status">';
        self::render_engine_card(
            __( 'Imagick', Plugin::TEXT_DOMAIN ),
            $report['imagick'],
            $report['imagick']['version']
        );
        self::render_engine_card(
            __( 'GD', Plugin::TEXT_DOMAIN ),
            $report['gd'],
            ''
        );
        echo '</div>';

        if ( empty( $report['can_write_webp'] ) ) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__( 'Neither Imagick nor GD can write WebP on this server. Ask the host to enable php-imagick with WebP, or GD with imagewebp, before turning conversion on.', Plugin::TEXT_DOMAIN );
            echo '</p></div>';
        } elseif ( $report['preferred'] === 'gd' ) {
            echo '<p class="description">';
            echo esc_html__( 'Imagick is not available. New uploads will be converted with GD.', Plugin::TEXT_DOMAIN );
            echo '</p>';
        }
    }

    /**
     * @param string $label
     * @param array  $status
     * @param string $extra
     */
    private static function render_engine_card( $label, $status, $extra ) {
        $ok = ! empty( $status['write_webp'] );
        $class = $ok ? 'is-ok' : 'is-missing';
        echo '<div class="lrtc-webp-card ' . esc_attr( $class ) . '">';
        echo '<h3>' . esc_html( $label ) . '</h3>';
        if ( empty( $status['present'] ) ) {
            echo '<p>' . esc_html__( 'Not installed', Plugin::TEXT_DOMAIN ) . '</p>';
        } elseif ( $ok ) {
            echo '<p>' . esc_html__( 'Can write WebP', Plugin::TEXT_DOMAIN ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'Installed, but WebP write is not available', Plugin::TEXT_DOMAIN ) . '</p>';
        }
        if ( $extra !== '' ) {
            echo '<p class="description">' . esc_html( $extra ) . '</p>';
        }
        echo '</div>';
    }

    public static function render_uploads_intro() {
        echo '<p>' . esc_html__( 'When this is on, JPEG, PNG, and GIF files chosen in the WordPress media uploader are converted to WebP before they are stored. The Media picker then shows the WebP file.', Plugin::TEXT_DOMAIN ) . '</p>';
    }

    public static function render_convert_uploads() {
        $settings = self::get();
        self::render_toggle(
            'convert_uploads',
            __( 'Replace new image uploads with a WebP file', Plugin::TEXT_DOMAIN ),
            ! empty( $settings['convert_uploads'] ),
            ! Capabilities::can_write_webp()
        );
    }

    public static function render_quality() {
        $settings = self::get();
        echo '<input type="number" class="small-text" min="1" max="100" name="' . esc_attr( self::OPTION ) . '[quality]" value="' . esc_attr( (string) (int) $settings['quality'] ) . '" />';
        echo '<p class="description">' . esc_html__( '1 is smallest, 100 is least compressed. Default 82.', Plugin::TEXT_DOMAIN ) . '</p>';
    }

    public static function render_skip_animated_gif() {
        $settings = self::get();
        self::render_toggle(
            'skip_animated_gif',
            __( 'Leave animated GIFs as GIF (recommended)', Plugin::TEXT_DOMAIN ),
            ! empty( $settings['skip_animated_gif'] ),
            false
        );
    }

    public static function render_skip_transparent_png() {
        $settings = self::get();
        self::render_toggle(
            'skip_transparent_png',
            __( 'Leave PNGs that have transparency as PNG', Plugin::TEXT_DOMAIN ),
            ! empty( $settings['skip_transparent_png'] ),
            false
        );
    }

    /**
     * Spruce-style switch. Own markup so this plugin does not require Spruce.
     *
     * @param string $key
     * @param string $label
     * @param bool   $checked
     * @param bool   $disabled
     */
    private static function render_toggle( $key, $label, $checked, $disabled ) {
        echo '<label class="toggle-switch">';
        echo '<input type="checkbox" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="1"' . checked( true, $checked, false );
        if ( $disabled ) {
            echo ' disabled="disabled"';
        }
        echo ' />';
        echo '<span class="slider" aria-hidden="true"></span>';
        echo '<span class="toggle-label">' . esc_html( $label ) . '</span>';
        echo '</label>';
    }
}
