<?php

namespace lrtc_webp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Batched conversion of images already in the Media Library.
 *
 * Attachment IDs stay the same. Original files stay on disk so URLs we
 * cannot rewrite still resolve. Metadata and safely rewritten strings
 * point at the WebP files.
 */
class Library
{
    const JOB_OPTION = 'lrtc_webp_library_job';
    const SOURCE_META = '_lrtc_webp_source';
    const BATCH_SIZE = 3;
    const LEFTOVER_CAP = 40;

    public static function init() {
        add_action( 'wp_ajax_lrtc_webp_library_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_lrtc_webp_library_step', array( __CLASS__, 'ajax_step' ) );
    }

    public static function enqueue( $hook ) {
        if ( $hook !== 'settings_page_' . Settings::PAGE ) {
            return;
        }

        wp_enqueue_script(
            'lrtc-webp-admin-library',
            LRTC_WEBP_URL . 'assets/js/admin-library.js',
            array( 'jquery' ),
            LRTC_WEBP_VERSION,
            true
        );

        wp_localize_script(
            'lrtc-webp-admin-library',
            'lrtcWebpLibrary',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'lrtc_webp_library' ),
                'canWrite' => Capabilities::can_write_webp() ? 1 : 0,
                'i18n'    => array(
                    'starting'  => __( 'Counting images…', Plugin::TEXT_DOMAIN ),
                    'running'   => __( 'Converting…', Plugin::TEXT_DOMAIN ),
                    'done'      => __( 'Library conversion finished.', Plugin::TEXT_DOMAIN ),
                    'failed'    => __( 'Library conversion failed.', Plugin::TEXT_DOMAIN ),
                    'noEngine'  => __( 'This server cannot write WebP.', Plugin::TEXT_DOMAIN ),
                    'none'      => __( 'No JPEG, PNG, or GIF attachments to convert.', Plugin::TEXT_DOMAIN ),
                ),
            )
        );
    }

    public static function render_section() {
        $can = Capabilities::can_write_webp();
        $count = $can ? self::candidate_count() : 0;

        echo '<div class="lrtc-webp-library" id="lrtc-webp-library">';
        echo '<h2>' . esc_html__( 'Existing images', Plugin::TEXT_DOMAIN ) . '</h2>';
        echo '<p>' . esc_html__( 'Convert JPEG, PNG, and GIF files already in the Media Library to WebP. Attachment IDs stay the same, so anything that stores an ID (featured images, galleries, lot photos) follows automatically. Original files are left on disk so URLs we cannot rewrite still work.', Plugin::TEXT_DOMAIN ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Uses the quality and skip settings above. Save settings before running. This walks post content, attachment GUIDs, post meta, and options and rewrites URLs it can safely touch. Serialized data is unserialized before replacing. Leftovers are listed when a string could not be updated.', Plugin::TEXT_DOMAIN ) . '</p>';

        if ( ! $can ) {
            echo '<p><button type="button" class="button button-secondary" disabled="disabled">' . esc_html__( 'Convert library', Plugin::TEXT_DOMAIN ) . '</button></p>';
            echo '</div>';
            return;
        }

        echo '<p>';
        echo '<button type="button" class="button button-secondary" id="lrtc-webp-library-start">';
        echo esc_html__( 'Convert library', Plugin::TEXT_DOMAIN );
        echo '</button> ';
        echo '<span class="lrtc-webp-library-count" id="lrtc-webp-library-count">';
        echo esc_html(
            sprintf(
                /* translators: %d: number of candidate attachments */
                _n( '%d candidate image.', '%d candidate images.', $count, Plugin::TEXT_DOMAIN ),
                $count
            )
        );
        echo '</span>';
        echo '</p>';

        echo '<div class="lrtc-webp-progress" id="lrtc-webp-progress" hidden>';
        echo '<div class="lrtc-webp-progress-bar"><span id="lrtc-webp-progress-fill"></span></div>';
        echo '<p class="lrtc-webp-progress-status" id="lrtc-webp-progress-status"></p>';
        echo '<ul class="lrtc-webp-progress-log" id="lrtc-webp-progress-log"></ul>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * @return int
     */
    public static function candidate_count() {
        $query = new \WP_Query(
            array_merge(
                self::candidate_args(),
                array(
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => false,
                )
            )
        );

        return (int) $query->found_posts;
    }

    /**
     * Convert one attachment. Keeps the original file.
     *
     * @param int $id
     * @return array|\WP_Error
     */
    public static function convert_attachment( $id ) {
        $id = (int) $id;
        if ( $id < 1 || get_post_type( $id ) !== 'attachment' ) {
            return new \WP_Error( 'lrtc_webp_not_attachment', __( 'Not a Media Library attachment.', Plugin::TEXT_DOMAIN ) );
        }

        $mime = strtolower( (string) get_post_mime_type( $id ) );
        $path = get_attached_file( $id );
        $old_urls = self::collect_public_urls( $id );
        $old_rels = self::collect_relative_paths( $id );

        $converted = Converter::convert_path( $path, $mime, false );
        if ( is_wp_error( $converted ) ) {
            return $converted;
        }

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $source_rel = isset( $old_rels['full'] ) ? $old_rels['full'] : '';
        if ( $source_rel !== '' ) {
            update_post_meta( $id, self::SOURCE_META, $source_rel );
        }

        update_attached_file( $id, $converted['file'] );

        $new_url = self::path_to_url( $converted['file'] );
        wp_update_post(
            array(
                'ID'             => $id,
                'post_mime_type' => 'image/webp',
                'guid'           => $new_url,
            )
        );

        $new_meta = wp_generate_attachment_metadata( $id, $converted['file'] );
        if ( ! is_wp_error( $new_meta ) && is_array( $new_meta ) ) {
            wp_update_attachment_metadata( $id, $new_meta );
        }

        clean_post_cache( $id );

        $map = self::build_replace_map( $old_urls, $old_rels, $id );
        $rewrite = self::rewrite_map( $map );

        return array(
            'id'        => $id,
            'file'      => $converted['file'],
            'original'  => $path,
            'kept'      => file_exists( $path ),
            'rewritten' => $rewrite['rewritten'],
            'leftovers' => $rewrite['leftovers'],
        );
    }

    public static function ajax_start() {
        self::ajax_guard();

        if ( ! Capabilities::can_write_webp() ) {
            wp_send_json_error(
                array( 'message' => __( 'This server cannot write WebP.', Plugin::TEXT_DOMAIN ) ),
                400
            );
        }

        $total = self::candidate_count();
        $job   = self::fresh_job( $total );
        update_option( self::JOB_OPTION, $job, false );

        wp_send_json_success(
            array(
                'total' => $total,
                'batch' => self::BATCH_SIZE,
                'job'   => self::public_job( $job ),
            )
        );
    }

    public static function ajax_step() {
        self::ajax_guard();

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 60 );
        }

        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || empty( $job['started'] ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No library conversion is in progress.', Plugin::TEXT_DOMAIN ) ),
                400
            );
        }

        $ids = self::candidate_ids( (int) $job['offset'], self::BATCH_SIZE );
        if ( empty( $ids ) ) {
            $job['done'] = 1;
            update_option( self::JOB_OPTION, $job, false );
            wp_send_json_success( self::public_job( $job ) );
        }

        foreach ( $ids as $id ) {
            $job['offset']++;
            $job['processed']++;

            $result = self::convert_attachment( $id );
            if ( is_wp_error( $result ) ) {
                $code = $result->get_error_code();
                if ( $code === 'lrtc_webp_skip' || $code === 'lrtc_webp_already' ) {
                    $job['skipped']++;
                    $job['log'][] = sprintf(
                        '#%d skipped: %s',
                        $id,
                        $result->get_error_message()
                    );
                } else {
                    $job['errors']++;
                    $job['log'][] = sprintf(
                        '#%d error: %s',
                        $id,
                        $result->get_error_message()
                    );
                }
                continue;
            }

            $job['converted']++;
            $job['rewritten'] += (int) $result['rewritten'];
            foreach ( $result['leftovers'] as $leftover ) {
                if ( count( $job['leftovers'] ) >= self::LEFTOVER_CAP ) {
                    break;
                }
                $job['leftovers'][] = $leftover;
            }

            $job['log'][] = sprintf(
                '#%d converted %s → %s (rewrote %d%s)',
                $id,
                wp_basename( $result['original'] ),
                wp_basename( $result['file'] ),
                (int) $result['rewritten'],
                empty( $result['leftovers'] ) ? '' : ', leftovers'
            );
        }

        $job['log'] = array_slice( $job['log'], -40 );
        if ( $job['offset'] >= $job['total'] ) {
            $job['done'] = 1;
        }

        update_option( self::JOB_OPTION, $job, false );
        wp_send_json_success( self::public_job( $job ) );
    }

    /**
     * @param array $args
     * @return array
     */
    private static function candidate_args() {
        return array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' ),
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return array
     */
    private static function candidate_ids( $offset, $limit ) {
        $query = new \WP_Query(
            array_merge(
                self::candidate_args(),
                array(
                    'posts_per_page' => (int) $limit,
                    'offset'         => (int) $offset,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                )
            )
        );

        $ids = array();
        foreach ( $query->posts as $id ) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param int $total
     * @return array
     */
    private static function fresh_job( $total ) {
        return array(
            'started'   => 1,
            'done'      => 0,
            'total'     => (int) $total,
            'offset'    => 0,
            'processed' => 0,
            'converted' => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'rewritten' => 0,
            'leftovers' => array(),
            'log'       => array(),
        );
    }

    /**
     * @param array $job
     * @return array
     */
    private static function public_job( $job ) {
        return array(
            'done'      => ! empty( $job['done'] ) ? 1 : 0,
            'total'     => isset( $job['total'] ) ? (int) $job['total'] : 0,
            'processed' => isset( $job['processed'] ) ? (int) $job['processed'] : 0,
            'converted' => isset( $job['converted'] ) ? (int) $job['converted'] : 0,
            'skipped'   => isset( $job['skipped'] ) ? (int) $job['skipped'] : 0,
            'errors'    => isset( $job['errors'] ) ? (int) $job['errors'] : 0,
            'rewritten' => isset( $job['rewritten'] ) ? (int) $job['rewritten'] : 0,
            'leftovers' => isset( $job['leftovers'] ) && is_array( $job['leftovers'] ) ? $job['leftovers'] : array(),
            'log'       => isset( $job['log'] ) && is_array( $job['log'] ) ? $job['log'] : array(),
        );
    }

    private static function ajax_guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', Plugin::TEXT_DOMAIN ) ), 403 );
        }
        check_ajax_referer( 'lrtc_webp_library', 'nonce' );
    }

    /**
     * @param int $id
     * @return array size name => url
     */
    private static function collect_public_urls( $id ) {
        $urls = array();
        $full = wp_get_attachment_url( $id );
        if ( is_string( $full ) && $full !== '' ) {
            $urls['full'] = $full;
        }

        $meta = wp_get_attachment_metadata( $id );
        if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            return $urls;
        }

        foreach ( $meta['sizes'] as $name => $size ) {
            unset( $size );
            $src = wp_get_attachment_image_src( $id, $name );
            if ( is_array( $src ) && ! empty( $src[0] ) ) {
                $urls[ $name ] = $src[0];
            }
        }

        return $urls;
    }

    /**
     * @param int $id
     * @return array size name => uploads-relative path
     */
    private static function collect_relative_paths( $id ) {
        $rels = array();
        $attached = get_post_meta( $id, '_wp_attached_file', true );
        if ( is_string( $attached ) && $attached !== '' ) {
            $rels['full'] = self::normalize_rel( $attached );
        }

        $meta = wp_get_attachment_metadata( $id );
        $dir  = '';
        if ( ! empty( $meta['file'] ) ) {
            $dir = dirname( str_replace( '\\', '/', $meta['file'] ) );
            if ( $dir === '.' ) {
                $dir = '';
            }
        } elseif ( isset( $rels['full'] ) ) {
            $dir = dirname( $rels['full'] );
            if ( $dir === '.' ) {
                $dir = '';
            }
        }

        if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            return $rels;
        }

        foreach ( $meta['sizes'] as $name => $size ) {
            if ( empty( $size['file'] ) ) {
                continue;
            }
            $rels[ $name ] = self::join_rel( $dir, $size['file'] );
        }

        return $rels;
    }

    /**
     * @param array $old_urls
     * @param array $old_rels
     * @param int   $id
     * @return array old string => new string
     */
    private static function build_replace_map( $old_urls, $old_rels, $id ) {
        $map      = array();
        $new_urls = self::collect_public_urls( $id );
        $new_rels = self::collect_relative_paths( $id );

        foreach ( $old_urls as $name => $old_url ) {
            if ( empty( $new_urls[ $name ] ) ) {
                continue;
            }
            self::add_url_pairs( $map, $old_url, $new_urls[ $name ] );
        }

        foreach ( $old_rels as $name => $old_rel ) {
            if ( empty( $new_rels[ $name ] ) ) {
                continue;
            }
            self::add_pair( $map, $old_rel, $new_rels[ $name ] );
        }

        uksort(
            $map,
            function ( $a, $b ) {
                return strlen( $b ) - strlen( $a );
            }
        );

        return $map;
    }

    /**
     * @param array $map
     * @return array { rewritten, leftovers }
     */
    private static function rewrite_map( $map ) {
        $rewritten = 0;
        $leftovers = array();

        if ( empty( $map ) ) {
            return array(
                'rewritten' => 0,
                'leftovers' => array(),
            );
        }

        $needles = self::search_needles( $map );
        $rewritten += self::rewrite_posts( $map, $needles );
        $rewritten += self::rewrite_postmeta( $map, $needles );
        $rewritten += self::rewrite_options( $map, $needles );

        foreach ( $needles as $needle ) {
            $hits = self::leftover_hits( $needle );
            if ( $hits['posts'] + $hits['postmeta'] + $hits['options'] > 0 ) {
                $leftovers[] = array_merge(
                    array( 'needle' => $needle ),
                    $hits
                );
            }
        }

        return array(
            'rewritten' => $rewritten,
            'leftovers' => $leftovers,
        );
    }

    /**
     * Distinctive paths/filenames worth searching for — prefer uploads-relative paths.
     *
     * @param array $map
     * @return array
     */
    private static function search_needles( $map ) {
        $needles = array();
        foreach ( $map as $old => $new ) {
            unset( $new );
            if ( strpos( $old, '/' ) !== false && preg_match( '/\.(jpe?g|png|gif)$/i', $old ) ) {
                $needles[] = $old;
            }
        }
        $needles = array_values( array_unique( $needles ) );
        if ( ! empty( $needles ) ) {
            return $needles;
        }

        return array_keys( $map );
    }

    /**
     * @param array $map
     * @param array $needles
     * @return int
     */
    private static function rewrite_posts( $map, $needles ) {
        global $wpdb;
        $ids = self::ids_matching_like( $wpdb->posts, 'ID', 'post_content', $needles );
        $guid_ids = self::ids_matching_like( $wpdb->posts, 'ID', 'guid', $needles );
        $ids = array_values( array_unique( array_merge( $ids, $guid_ids ) ) );

        $count = 0;
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }
            $content = self::replace_in_data( $post->post_content, $map );
            $guid    = self::replace_in_data( $post->guid, $map );
            if ( $content === $post->post_content && $guid === $post->guid ) {
                continue;
            }
            $update = array( 'ID' => (int) $id );
            if ( $content !== $post->post_content ) {
                $update['post_content'] = $content;
            }
            if ( $guid !== $post->guid ) {
                $update['guid'] = $guid;
            }
            wp_update_post( $update );
            $count++;
        }

        return $count;
    }

    /**
     * @param array $map
     * @param array $needles
     * @return int
     */
    private static function rewrite_postmeta( $map, $needles ) {
        global $wpdb;
        $skip = self::skip_meta_keys();
        $rows = self::rows_matching_like( $wpdb->postmeta, array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ), 'meta_value', $needles );
        $count = 0;

        foreach ( $rows as $row ) {
            if ( in_array( $row['meta_key'], $skip, true ) ) {
                continue;
            }
            $raw      = $row['meta_value'];
            $replaced = self::replace_maybe_serialized( $raw, $map );
            if ( $replaced === $raw ) {
                continue;
            }
            $wpdb->update(
                $wpdb->postmeta,
                array( 'meta_value' => $replaced ),
                array( 'meta_id' => (int) $row['meta_id'] ),
                array( '%s' ),
                array( '%d' )
            );
            wp_cache_delete( (int) $row['post_id'], 'post_meta' );
            $count++;
        }

        return $count;
    }

    /**
     * @param array $map
     * @param array $needles
     * @return int
     */
    private static function rewrite_options( $map, $needles ) {
        global $wpdb;
        $rows = self::rows_matching_like( $wpdb->options, array( 'option_id', 'option_name', 'option_value' ), 'option_value', $needles );
        $count = 0;

        foreach ( $rows as $row ) {
            $name = $row['option_name'];
            if ( strpos( $name, '_transient_' ) !== false || strpos( $name, '_site_transient_' ) !== false ) {
                continue;
            }
            if ( $name === self::JOB_OPTION || $name === Settings::OPTION ) {
                continue;
            }
            $raw      = $row['option_value'];
            $replaced = self::replace_maybe_serialized( $raw, $map );
            if ( $replaced === $raw ) {
                continue;
            }
            $wpdb->update(
                $wpdb->options,
                array( 'option_value' => $replaced ),
                array( 'option_id' => (int) $row['option_id'] ),
                array( '%s' ),
                array( '%d' )
            );
            wp_cache_delete( $name, 'options' );
            $count++;
        }

        return $count;
    }

    /**
     * @param string $needle
     * @return array
     */
    private static function leftover_hits( $needle ) {
        global $wpdb;
        $needles = array( $needle );
        $skip    = self::skip_meta_keys();

        $posts = count( self::ids_matching_like( $wpdb->posts, 'ID', 'post_content', $needles ) );
        $posts += count( self::ids_matching_like( $wpdb->posts, 'ID', 'guid', $needles ) );

        $meta_rows = self::rows_matching_like( $wpdb->postmeta, array( 'meta_id', 'meta_key' ), 'meta_value', $needles );
        $meta = 0;
        foreach ( $meta_rows as $row ) {
            if ( ! in_array( $row['meta_key'], $skip, true ) ) {
                $meta++;
            }
        }

        $option_rows = self::rows_matching_like( $wpdb->options, array( 'option_id', 'option_name' ), 'option_value', $needles );
        $options = 0;
        foreach ( $option_rows as $row ) {
            $name = $row['option_name'];
            if ( strpos( $name, '_transient_' ) !== false || strpos( $name, '_site_transient_' ) !== false ) {
                continue;
            }
            if ( $name === self::JOB_OPTION || $name === Settings::OPTION ) {
                continue;
            }
            $options++;
        }

        return array(
            'posts'    => $posts,
            'postmeta' => $meta,
            'options'  => $options,
        );
    }

    /**
     * @param string $table
     * @param string $id_col
     * @param string $value_col
     * @param array  $needles
     * @return array
     */
    private static function ids_matching_like( $table, $id_col, $value_col, $needles ) {
        $rows = self::rows_matching_like( $table, array( $id_col ), $value_col, $needles );
        $ids  = array();
        foreach ( $rows as $row ) {
            $ids[] = (int) $row[ $id_col ];
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * @param string $table
     * @param array  $cols
     * @param string $value_col
     * @param array  $needles
     * @return array
     */
    private static function rows_matching_like( $table, $cols, $value_col, $needles ) {
        global $wpdb;
        $needles = array_values( array_filter( $needles ) );
        if ( empty( $needles ) ) {
            return array();
        }

        $likes  = array();
        $values = array();
        foreach ( $needles as $needle ) {
            $likes[]  = $value_col . ' LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $needle ) . '%';
        }

        $select = implode( ', ', $cols );
        $sql    = "SELECT {$select} FROM {$table} WHERE " . implode( ' OR ', $likes );
        $prepared = $wpdb->prepare( $sql, $values );
        $found    = $wpdb->get_results( $prepared, ARRAY_A );

        return is_array( $found ) ? $found : array();
    }

    /**
     * @param mixed $raw
     * @param array $map
     * @return mixed
     */
    private static function replace_maybe_serialized( $raw, $map ) {
        if ( ! is_string( $raw ) || $raw === '' ) {
            return $raw;
        }

        if ( ! is_serialized( $raw ) ) {
            return self::replace_in_data( $raw, $map );
        }

        $data = @unserialize( $raw );
        if ( $data === false && $raw !== serialize( false ) ) {
            return self::replace_in_data( $raw, $map );
        }

        $replaced = self::replace_in_data( $data, $map );
        if ( $replaced === $data ) {
            return $raw;
        }

        return maybe_serialize( $replaced );
    }

    /**
     * @param mixed $data
     * @param array $map
     * @return mixed
     */
    private static function replace_in_data( $data, $map ) {
        if ( is_string( $data ) ) {
            return str_replace( array_keys( $map ), array_values( $map ), $data );
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = self::replace_in_data( $value, $map );
            }

            return $data;
        }

        if ( is_object( $data ) && get_class( $data ) === 'stdClass' ) {
            foreach ( $data as $key => $value ) {
                $data->{$key} = self::replace_in_data( $value, $map );
            }

            return $data;
        }

        return $data;
    }

    /**
     * @param array  $map
     * @param string $old
     * @param string $new
     */
    private static function add_url_pairs( &$map, $old, $new ) {
        self::add_pair( $map, $old, $new );
        self::add_pair( $map, set_url_scheme( $old, 'https' ), set_url_scheme( $new, 'https' ) );
        self::add_pair( $map, set_url_scheme( $old, 'http' ), set_url_scheme( $new, 'http' ) );

        $old_path = wp_parse_url( $old, PHP_URL_PATH );
        $new_path = wp_parse_url( $new, PHP_URL_PATH );
        if ( is_string( $old_path ) && is_string( $new_path ) ) {
            self::add_pair( $map, $old_path, $new_path );
        }
    }

    /**
     * @param array  $map
     * @param string $old
     * @param string $new
     */
    private static function add_pair( &$map, $old, $new ) {
        if ( ! is_string( $old ) || ! is_string( $new ) || $old === '' || $new === '' || $old === $new ) {
            return;
        }
        $map[ $old ] = $new;
    }

    /**
     * @return array
     */
    private static function skip_meta_keys() {
        return array(
            '_wp_attached_file',
            '_wp_attachment_metadata',
            '_wp_attachment_backup_sizes',
            self::SOURCE_META,
        );
    }

    /**
     * @param string $path
     * @return string
     */
    private static function path_to_url( $path ) {
        $uploads = wp_get_upload_dir();
        $basedir = wp_normalize_path( $uploads['basedir'] );
        $path    = wp_normalize_path( $path );
        if ( strpos( $path, $basedir ) === 0 ) {
            $rel = ltrim( substr( $path, strlen( $basedir ) ), '/' );

            return trailingslashit( $uploads['baseurl'] ) . $rel;
        }

        return $path;
    }

    /**
     * @param string $rel
     * @return string
     */
    private static function normalize_rel( $rel ) {
        return ltrim( str_replace( '\\', '/', $rel ), '/' );
    }

    /**
     * @param string $dir
     * @param string $file
     * @return string
     */
    private static function join_rel( $dir, $file ) {
        $file = ltrim( str_replace( '\\', '/', $file ), '/' );
        $dir  = trim( str_replace( '\\', '/', $dir ), '/' );
        if ( $dir === '' || $dir === '.' ) {
            return $file;
        }

        return $dir . '/' . $file;
    }
}
