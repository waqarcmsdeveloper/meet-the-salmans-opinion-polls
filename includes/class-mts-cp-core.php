<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MTS_CP_Core {
    private static $instance = null;

    public $post_type = 'mts_cp_poll';
    public $taxonomy  = 'mts_cp_category';
    public $table;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mts_cp_votes';

        add_action( 'init', array( $this, 'register_content_types' ) );
        add_action( 'init', array( $this, 'register_meta' ) );
        add_action( 'init', array( $this, 'maybe_update_db_schema' ) );
        add_action( 'parse_term_query', array( $this, 'customize_category_query' ) );
    }

    public function get_voter_key() {
        if ( is_user_logged_in() ) {
            return 'user_' . get_current_user_id();
        }

        $cookie_name = 'mts_cp_voter_token';
        if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_key( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        }

        $ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $token = 'guest_' . md5( $ip . '|' . $ua . '|' . wp_rand() );

        if ( ! headers_sent() ) {
            setcookie( $cookie_name, $token, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
        }
        $_COOKIE[ $cookie_name ] = $token;

        return sanitize_key( $token );
    }

    public function maybe_update_db_schema() {
        global $wpdb;
        $table_name = $this->table;

        // Check if table exists
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            return;
        }

        // Check if voter_key column exists
        $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'voter_key'" );
        if ( empty( $column_exists ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN voter_key varchar(64) NOT NULL DEFAULT '' AFTER user_id" );
            $wpdb->query( "UPDATE {$table_name} SET voter_key = CONCAT('user_', user_id) WHERE user_id > 0 AND (voter_key IS NULL OR voter_key = '')" );
            
            // Check if old poll_user index exists before dropping
            $index_exists = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'poll_user'" );
            if ( ! empty( $index_exists ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} DROP INDEX poll_user" );
            }
            
            $voter_index = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'poll_voter'" );
            if ( empty( $voter_index ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} ADD UNIQUE KEY poll_voter (poll_id, voter_key)" );
            }
        }
    }

    public static function activate() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'mts_cp_votes';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            voter_key varchar(64) NOT NULL DEFAULT '',
            gender varchar(20) NOT NULL DEFAULT '',
            answer_json longtext NOT NULL,
            other_text text NULL,
            context_text text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY poll_voter (poll_id, voter_key),
            KEY poll_id (poll_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $core = self::instance();
        $core->maybe_update_db_schema();
        $core->register_content_types();
        $core->create_default_categories();
        $core->create_required_pages();
        $core->create_sample_polls();

        if ( false === get_option( 'mts_cp_settings', false ) ) {
            add_option( 'mts_cp_settings', self::default_settings() );
        } else {
            update_option( 'mts_cp_settings', wp_parse_args( get_option( 'mts_cp_settings', array() ), self::default_settings() ) );
        }

        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function default_settings() {
        $page_ids = (array) get_option( 'mts_cp_page_ids', array() );

        return array(
            'register_page_id'     => isset( $page_ids['register'] ) ? absint( $page_ids['register'] ) : 0,
            'login_page_id'        => isset( $page_ids['login'] ) ? absint( $page_ids['login'] ) : 0,
            'polls_page_id'        => isset( $page_ids['polls'] ) ? absint( $page_ids['polls'] ) : 0,
            'account_page_id'      => isset( $page_ids['account'] ) ? absint( $page_ids['account'] ) : 0,
            'accent_color'         => '#2f7583',
            'heading_bg'           => '#ddd9d6',
            'soft_bg'              => '#f8ece8',
            'require_login'        => '0',
            'allow_answer_change'  => '0',
            'library_heading'      => 'Want to hear what others are saying?',
            'library_subheading'   => 'Weigh in on topics that interest you here.',
            'empty_text'           => 'No polls matched your selection.',
            'registration_heading' => 'Opinion Polls',
            'login_heading'        => 'Opinion Polls',
            'privacy_text'         => 'Your answers are displayed publicly only as combined totals. Individual names and email addresses are never shown in poll results.',
            'delete_on_uninstall'  => '0',
        );
    }

    public function settings() {
        return wp_parse_args( (array) get_option( 'mts_cp_settings', array() ), self::default_settings() );
    }

    public function update_settings( $settings ) {
        update_option( 'mts_cp_settings', wp_parse_args( (array) $settings, self::default_settings() ) );
    }

    public function register_content_types() {
        register_post_type(
            $this->post_type,
            array(
                'labels' => array(
                    'name'               => __( 'Opinion Polls', 'mts-complete-opinion-polls' ),
                    'singular_name'      => __( 'Opinion Poll', 'mts-complete-opinion-polls' ),
                    'menu_name'          => __( 'Opinion Polls', 'mts-complete-opinion-polls' ),
                    'add_new'            => __( 'Add New Poll', 'mts-complete-opinion-polls' ),
                    'add_new_item'       => __( 'Add New Opinion Poll', 'mts-complete-opinion-polls' ),
                    'edit_item'          => __( 'Edit Opinion Poll', 'mts-complete-opinion-polls' ),
                    'new_item'           => __( 'New Opinion Poll', 'mts-complete-opinion-polls' ),
                    'view_item'          => __( 'View Opinion Poll', 'mts-complete-opinion-polls' ),
                    'search_items'       => __( 'Search Opinion Polls', 'mts-complete-opinion-polls' ),
                    'not_found'          => __( 'No opinion polls found.', 'mts-complete-opinion-polls' ),
                    'not_found_in_trash' => __( 'No opinion polls found in Trash.', 'mts-complete-opinion-polls' ),
                ),
                'public'              => true,
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'show_in_rest'        => true,
                'menu_icon'           => 'dashicons-chart-bar',
                'supports'            => array( 'title', 'editor', 'excerpt', 'revisions', 'page-attributes' ),
                'has_archive'         => false,
                'rewrite'             => false,
                'taxonomies'          => array( $this->taxonomy ),
            )
        );

        register_taxonomy(
            $this->taxonomy,
            array( $this->post_type ),
            array(
                'labels' => array(
                    'name'          => __( 'Poll Categories', 'mts-complete-opinion-polls' ),
                    'singular_name' => __( 'Poll Category', 'mts-complete-opinion-polls' ),
                    'search_items'  => __( 'Search Poll Categories', 'mts-complete-opinion-polls' ),
                    'all_items'     => __( 'All Poll Categories', 'mts-complete-opinion-polls' ),
                    'edit_item'     => __( 'Edit Poll Category', 'mts-complete-opinion-polls' ),
                    'update_item'   => __( 'Update Poll Category', 'mts-complete-opinion-polls' ),
                    'add_new_item'  => __( 'Add New Poll Category', 'mts-complete-opinion-polls' ),
                    'new_item_name' => __( 'New Poll Category Name', 'mts-complete-opinion-polls' ),
                    'menu_name'     => __( 'Poll Categories', 'mts-complete-opinion-polls' ),
                ),
                'public'            => false,
                'hierarchical'      => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => false,
            )
        );
    }

    public function register_meta() {
        $string_keys = array(
            '_mts_cp_poll_type',
            '_mts_cp_poll_options',
            '_mts_cp_randomize',
            '_mts_cp_allow_other',
            '_mts_cp_allow_context',
            '_mts_cp_allow_change',
            '_mts_cp_status',
            '_mts_cp_matrix_rows',
            '_mts_cp_slider_min_label',
            '_mts_cp_slider_max_label',
            '_mts_cp_maxdiff_high_label',
            '_mts_cp_maxdiff_low_label',
        );

        foreach ( $string_keys as $key ) {
            register_post_meta(
                $this->post_type,
                $key,
                array(
                    'single'            => true,
                    'show_in_rest'      => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback'     => static function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
        }

        foreach ( array( '_mts_cp_vote_count', '_mts_cp_slider_min', '_mts_cp_slider_max', '_mts_cp_slider_step' ) as $int_key ) {
            register_post_meta(
                $this->post_type,
                $int_key,
                array(
                    'single'            => true,
                    'show_in_rest'      => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'auth_callback'     => static function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
        }
    }

    public function customize_category_query( $query ) {
        if ( isset( $query->query_vars['taxonomy'] ) && in_array( $this->taxonomy, (array) $query->query_vars['taxonomy'], true ) ) {
            if ( is_admin() && isset( $_GET['taxonomy'] ) && $_GET['taxonomy'] === $this->taxonomy && empty( $_GET['orderby'] ) ) {
                $query->query_vars['meta_key'] = 'mts_cp_order';
                $query->query_vars['orderby']  = 'meta_value_num';
                $query->query_vars['order']    = 'ASC';
            }
        }
    }

    public function category_names() {
        return array(
            'Childlessness',
            'Church',
            'Dating',
            'Emotional Well-being',
            'Friends & Family',
            'God',
            'Life & Work',
            'The Opposite Sex',
            'Past Romantic Relationships',
            'Sexuality',
            'Single Parents/Previously Married',
        );
    }

    public function create_default_categories() {
        $renames = array(
            'Divorced or Widowed'       => 'Single Parents/Previously Married',
            'Parenting & Childlessness' => 'Childlessness',
            'Past Relationships'        => 'Past Romantic Relationships',
        );
        foreach ( $renames as $old_name => $new_name ) {
            $old_term = get_term_by( 'name', $old_name, $this->taxonomy );
            if ( $old_term && ! is_wp_error( $old_term ) ) {
                wp_update_term( $old_term->term_id, $this->taxonomy, array(
                    'name' => $new_name,
                    'slug' => sanitize_title( $new_name ),
                ) );
            }
        }

        foreach ( $this->category_names() as $index => $name ) {
            $exists = term_exists( $name, $this->taxonomy );
            if ( ! $exists ) {
                $created = wp_insert_term( $name, $this->taxonomy );
                if ( ! is_wp_error( $created ) ) {
                    update_term_meta( absint( $created['term_id'] ), 'mts_cp_order', $index + 1 );
                }
            } else {
                $term_id = is_array( $exists ) ? absint( $exists['term_id'] ) : absint( $exists );
                update_term_meta( $term_id, 'mts_cp_order', $index + 1 );
            }
        }
    }

    public function ordered_categories( $hide_empty = false ) {
        $this->create_default_categories();

        $terms = get_terms(
            array(
                'taxonomy'   => $this->taxonomy,
                'hide_empty' => (bool) $hide_empty,
                'meta_key'   => 'mts_cp_order',
                'orderby'    => 'meta_value_num',
                'order'      => 'ASC',
            )
        );

        return is_wp_error( $terms ) ? array() : $terms;
    }

    public function create_required_pages() {
        $definitions = array(
            'register' => array(
                'title'   => 'Poll Registration',
                'slug'    => 'poll-register',
                'content' => '[mts_poll_register]',
            ),
            'login'    => array(
                'title'   => 'Poll Login',
                'slug'    => 'poll-login',
                'content' => '[mts_poll_login]',
            ),
            'polls'    => array(
                'title'   => 'Opinion Polls',
                'slug'    => 'opinion-polls',
                'content' => '[mts_opinion_polls]',
            ),
            'account'  => array(
                'title'   => 'My Poll Answers',
                'slug'    => 'my-poll-answers',
                'content' => '[mts_my_poll_answers]',
            ),
        );

        $page_ids = (array) get_option( 'mts_cp_page_ids', array() );

        foreach ( $definitions as $key => $definition ) {
            $page_id = isset( $page_ids[ $key ] ) ? absint( $page_ids[ $key ] ) : 0;

            if ( $page_id && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
                continue;
            }

            $existing = get_page_by_path( $definition['slug'] );
            if ( $existing instanceof WP_Post ) {
                $page_ids[ $key ] = $existing->ID;
                continue;
            }

            $created = wp_insert_post(
                array(
                    'post_title'   => $definition['title'],
                    'post_name'    => $definition['slug'],
                    'post_content' => $definition['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ),
                true
            );

            if ( ! is_wp_error( $created ) ) {
                $page_ids[ $key ] = absint( $created );
            }
        }

        update_option( 'mts_cp_page_ids', $page_ids );

        $settings = wp_parse_args( (array) get_option( 'mts_cp_settings', array() ), self::default_settings() );
        foreach ( array( 'register', 'login', 'polls', 'account' ) as $key ) {
            if ( isset( $page_ids[ $key ] ) ) {
                $settings[ $key . '_page_id' ] = absint( $page_ids[ $key ] );
            }
        }
        update_option( 'mts_cp_settings', $settings );
    }

    public function page_url( $key ) {
        $settings = $this->settings();
        $option   = $key . '_page_id';
        $page_id  = isset( $settings[ $option ] ) ? absint( $settings[ $option ] ) : 0;

        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return $url;
            }
        }

        return home_url( '/' );
    }

    public function get_poll_type( $poll_id ) {
        $type = get_post_meta( $poll_id, '_mts_cp_poll_type', true );
        return in_array( $type, array( 'single', 'multiple', 'textbox', 'rank', 'maxdiff', 'matrix', 'slider' ), true ) ? $type : 'single';
    }

    public function get_poll_options( $poll_id ) {
        $raw = get_post_meta( $poll_id, '_mts_cp_poll_options', true );

        if ( is_array( $raw ) ) {
            $options = $raw;
        } else {
            $decoded = json_decode( (string) $raw, true );
            $options = is_array( $decoded ) ? $decoded : array();
        }

        $clean = array();
        foreach ( $options as $option ) {
            $option = trim( sanitize_text_field( (string) $option ) );
            if ( '' !== $option && ! in_array( $option, $clean, true ) ) {
                $clean[] = $option;
            }
        }

        return $clean;
    }

    public function get_matrix_rows( $poll_id ) {
        $raw = get_post_meta( $poll_id, '_mts_cp_matrix_rows', true );

        if ( is_array( $raw ) ) {
            $rows = $raw;
        } else {
            $decoded = json_decode( (string) $raw, true );
            $rows = is_array( $decoded ) ? $decoded : array();
        }

        $clean = array();
        foreach ( $rows as $row ) {
            $row = trim( sanitize_text_field( (string) $row ) );
            if ( '' !== $row && ! in_array( $row, $clean, true ) ) {
                $clean[] = $row;
            }
        }

        return $clean;
    }

    public function get_slider_config( $poll_id ) {
        $min       = get_post_meta( $poll_id, '_mts_cp_slider_min', true );
        $max       = get_post_meta( $poll_id, '_mts_cp_slider_max', true );
        $step      = get_post_meta( $poll_id, '_mts_cp_slider_step', true );
        $min_label = get_post_meta( $poll_id, '_mts_cp_slider_min_label', true );
        $max_label = get_post_meta( $poll_id, '_mts_cp_slider_max_label', true );

        return array(
            'min'       => '' !== $min ? intval( $min ) : 1,
            'max'       => '' !== $max ? intval( $max ) : 10,
            'step'      => '' !== $step ? max( 1, intval( $step ) ) : 1,
            'min_label' => '' !== $min_label ? $min_label : __( 'Lowest', 'mts-complete-opinion-polls' ),
            'max_label' => '' !== $max_label ? $max_label : __( 'Highest', 'mts-complete-opinion-polls' ),
        );
    }

    public function get_maxdiff_labels( $poll_id ) {
        $high = get_post_meta( $poll_id, '_mts_cp_maxdiff_high_label', true );
        $low  = get_post_meta( $poll_id, '_mts_cp_maxdiff_low_label', true );

        return array(
            'high' => '' !== $high ? $high : __( 'Most Important', 'mts-complete-opinion-polls' ),
            'low'  => '' !== $low ? $low : __( 'Least Important', 'mts-complete-opinion-polls' ),
        );
    }

    public function flag( $poll_id, $key, $default = '0' ) {
        $value = get_post_meta( $poll_id, $key, true );
        if ( '' === $value ) {
            $value = $default;
        }
        return '1' === (string) $value;
    }

    public function poll_is_open( $poll_id ) {
        $status = get_post_meta( $poll_id, '_mts_cp_status', true );
        return 'closed' !== $status;
    }

    public function current_user_has_voted( $poll_id ) {
        global $wpdb;
        $voter_key = $this->get_voter_key();
        $user_id   = get_current_user_id();

        if ( $user_id > 0 ) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE poll_id = %d AND ( voter_key = %s OR user_id = %d ) LIMIT 1",
                    absint( $poll_id ),
                    $voter_key,
                    $user_id
                )
            );
        } else {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE poll_id = %d AND voter_key = %s LIMIT 1",
                    absint( $poll_id ),
                    $voter_key
                )
            );
        }

        return ! empty( $exists );
    }

    public function get_user_vote( $poll_id, $user_id = 0 ) {
        global $wpdb;
        $voter_key = $this->get_voter_key();
        $user_id   = $user_id ? absint( $user_id ) : get_current_user_id();

        if ( $user_id > 0 ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE poll_id = %d AND ( voter_key = %s OR user_id = %d ) LIMIT 1",
                    absint( $poll_id ),
                    $voter_key,
                    $user_id
                )
            );
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE poll_id = %d AND voter_key = %s LIMIT 1",
                absint( $poll_id ),
                $voter_key
            )
        );
    }

    public function get_vote_count( $poll_id ) {
        $count = get_post_meta( $poll_id, '_mts_cp_vote_count', true );
        if ( '' !== $count ) {
            return absint( $count );
        }

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE poll_id = %d", absint( $poll_id ) ) );
        update_post_meta( $poll_id, '_mts_cp_vote_count', $count );
        return $count;
    }

    public function refresh_vote_count( $poll_id ) {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE poll_id = %d", absint( $poll_id ) ) );
        update_post_meta( $poll_id, '_mts_cp_vote_count', $count );
        return $count;
    }

    public function get_results( $poll_id ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT gender, answer_json FROM {$this->table} WHERE poll_id = %d ORDER BY id ASC",
                absint( $poll_id )
            )
        );

        $type        = $this->get_poll_type( $poll_id );
        $options     = $this->get_poll_options( $poll_id );
        $total_votes = count( $rows );

        $gender = array(
            'Male'    => 0,
            'Female'  => 0,
            'Skipped' => 0,
        );

        foreach ( $rows as $row ) {
            if ( 'male' === $row->gender ) {
                ++$gender['Male'];
            } elseif ( 'female' === $row->gender ) {
                ++$gender['Female'];
            } else {
                ++$gender['Skipped'];
            }
        }

        if ( 'rank' === $type ) {
            $rank_sums   = array();
            $rank_counts = array();
            $first_place = array();
            foreach ( $options as $opt ) {
                $rank_sums[ $opt ]   = 0;
                $rank_counts[ $opt ] = 0;
                $first_place[ $opt ] = 0;
            }

            foreach ( $rows as $row ) {
                $answers = json_decode( (string) $row->answer_json, true );
                if ( is_array( $answers ) ) {
                    foreach ( $answers as $opt => $rank_num ) {
                        $opt      = sanitize_text_field( (string) $opt );
                        $rank_num = absint( $rank_num );
                        if ( isset( $rank_sums[ $opt ] ) ) {
                            $rank_sums[ $opt ] += $rank_num;
                            ++$rank_counts[ $opt ];
                            if ( 1 === $rank_num ) {
                                ++$first_place[ $opt ];
                            }
                        }
                    }
                }
            }

            $rank_data = array();
            foreach ( $options as $opt ) {
                $count     = $rank_counts[ $opt ];
                $avg_rank  = $count > 0 ? round( $rank_sums[ $opt ] / $count, 2 ) : 0;
                $first_pct = $total_votes > 0 ? round( ( $first_place[ $opt ] / $total_votes ) * 100, 1 ) : 0;
                $rank_data[ $opt ] = array(
                    'avg_rank'          => $avg_rank,
                    'first_place_count' => $first_place[ $opt ],
                    'first_place_pct'   => $first_pct,
                );
            }

            uasort(
                $rank_data,
                static function ( $a, $b ) {
                    if ( 0 == $a['avg_rank'] ) return 1;
                    if ( 0 == $b['avg_rank'] ) return -1;
                    return $a['avg_rank'] <=> $b['avg_rank'];
                }
            );

            return array(
                'type'        => 'rank',
                'total_votes' => $total_votes,
                'rank_data'   => $rank_data,
                'gender'      => $gender,
            );
        }

        if ( 'maxdiff' === $type ) {
            $labels    = $this->get_maxdiff_labels( $poll_id );
            $most_cnt  = array();
            $least_cnt = array();
            foreach ( $options as $opt ) {
                $most_cnt[ $opt ]  = 0;
                $least_cnt[ $opt ] = 0;
            }

            foreach ( $rows as $row ) {
                $answers = json_decode( (string) $row->answer_json, true );
                if ( is_array( $answers ) ) {
                    if ( ! empty( $answers['most'] ) && isset( $most_cnt[ $answers['most'] ] ) ) {
                        ++$most_cnt[ $answers['most'] ];
                    }
                    if ( ! empty( $answers['least'] ) && isset( $least_cnt[ $answers['least'] ] ) ) {
                        ++$least_cnt[ $answers['least'] ];
                    }
                }
            }

            $maxdiff_data = array();
            foreach ( $options as $opt ) {
                $m     = $most_cnt[ $opt ];
                $l     = $least_cnt[ $opt ];
                $m_pct = $total_votes > 0 ? round( ( $m / $total_votes ) * 100, 1 ) : 0;
                $l_pct = $total_votes > 0 ? round( ( $l / $total_votes ) * 100, 1 ) : 0;
                $net   = round( $m_pct - $l_pct, 1 );

                $maxdiff_data[ $opt ] = array(
                    'most_count'  => $m,
                    'most_pct'    => $m_pct,
                    'least_count' => $l,
                    'least_pct'   => $l_pct,
                    'net_score'   => $net,
                );
            }

            uasort(
                $maxdiff_data,
                static function ( $a, $b ) {
                    return $b['net_score'] <=> $a['net_score'];
                }
            );

            return array(
                'type'         => 'maxdiff',
                'labels'       => $labels,
                'total_votes'  => $total_votes,
                'maxdiff_data' => $maxdiff_data,
                'gender'       => $gender,
            );
        }

        if ( 'matrix' === $type ) {
            $matrix_rows = $this->get_matrix_rows( $poll_id );
            $matrix_cols = $options;

            $matrix_counts = array();
            foreach ( $matrix_rows as $mrow ) {
                $matrix_counts[ $mrow ] = array();
                foreach ( $matrix_cols as $mcol ) {
                    $matrix_counts[ $mrow ][ $mcol ] = 0;
                }
            }

            foreach ( $rows as $row ) {
                $answers = json_decode( (string) $row->answer_json, true );
                if ( is_array( $answers ) ) {
                    foreach ( $answers as $mrow => $mcol ) {
                        if ( isset( $matrix_counts[ $mrow ][ $mcol ] ) ) {
                            ++$matrix_counts[ $mrow ][ $mcol ];
                        }
                    }
                }
            }

            return array(
                'type'          => 'matrix',
                'total_votes'   => $total_votes,
                'matrix_rows'   => $matrix_rows,
                'matrix_cols'   => $matrix_cols,
                'matrix_counts' => $matrix_counts,
                'gender'        => $gender,
            );
        }

        if ( 'slider' === $type ) {
            $config   = $this->get_slider_config( $poll_id );
            $val_sum  = 0;
            $val_min  = null;
            $val_max  = null;
            $buckets  = array();

            foreach ( $rows as $row ) {
                $answers = json_decode( (string) $row->answer_json, true );
                $val     = is_array( $answers ) && isset( $answers['value'] ) ? floatval( $answers['value'] ) : floatval( $answers );
                $val_sum += $val;

                if ( null === $val_min || $val < $val_min ) {
                    $val_min = $val;
                }
                if ( null === $val_max || $val > $val_max ) {
                    $val_max = $val;
                }

                $key = (string) round( $val, 1 );
                if ( ! isset( $buckets[ $key ] ) ) {
                    $buckets[ $key ] = 0;
                }
                ++$buckets[ $key ];
            }

            $avg_val = $total_votes > 0 ? round( $val_sum / $total_votes, 1 ) : 0;

            return array(
                'type'        => 'slider',
                'config'      => $config,
                'total_votes' => $total_votes,
                'avg_val'     => $avg_val,
                'min_val'     => null !== $val_min ? $val_min : $config['min'],
                'max_val'     => null !== $val_max ? $val_max : $config['max'],
                'buckets'     => $buckets,
                'gender'      => $gender,
            );
        }

        $counts = array();
        foreach ( $options as $option ) {
            $counts[ $option ] = 0;
        }

        if ( $this->flag( $poll_id, '_mts_cp_allow_other', '1' ) ) {
            $counts['Other'] = 0;
        }

        foreach ( $rows as $row ) {
            $answers = json_decode( (string) $row->answer_json, true );
            $answers = is_array( $answers ) ? $answers : array( $answers );

            foreach ( $answers as $answer ) {
                $label = '__other__' === $answer ? 'Other' : sanitize_text_field( (string) $answer );
                if ( ! isset( $counts[ $label ] ) ) {
                    $counts[ $label ] = 0;
                }
                ++$counts[ $label ];
            }
        }

        return array(
            'type'        => $type,
            'total_votes' => $total_votes,
            'counts'      => $counts,
            'gender'      => $gender,
        );
    }

    public function create_sample_polls() {
        $samples = array(
            'Church' => array(
                array( 'I believe my pastor understands my struggle as a single.', array( 'Strongly agree', 'Agree', 'Neither agree nor disagree', 'Disagree', 'Strongly disagree' ) ),
                array( 'How welcoming is your church toward single adults?', array( 'Very welcoming', 'Somewhat welcoming', 'Neutral', 'Somewhat unwelcoming', 'Very unwelcoming' ) ),
            ),
            'Dating' => array(
                array( 'Should I date someone who never wants to marry?', array( 'Yes', 'No', 'It depends', 'Not sure' ) ),
                array( 'Best online dating platform to find a quality match?', array( 'Christian Mingle', 'eHarmony', 'Hinge', 'Bumble', 'Match' ) ),
            ),
            'Divorced or Widowed' => array(
                array( 'How long should someone wait before dating after divorce or bereavement?', array( 'Less than 6 months', '6–12 months', '1–2 years', 'There is no fixed timeline' ) ),
                array( 'Would you date someone who is divorced or widowed?', array( 'Yes', 'No', 'It depends', 'Not sure' ) ),
            ),
            'Emotional Well-being' => array(
                array( 'How often do you feel lonely as a single adult?', array( 'Never', 'Rarely', 'Sometimes', 'Often', 'Very often' ) ),
                array( 'What most supports your emotional well-being?', array( 'Faith', 'Friends or family', 'Therapy or counseling', 'Exercise', 'Community activities' ) ),
            ),
            'Friends & Family' => array(
                array( 'Do friends and family put pressure on you to marry?', array( 'Never', 'Rarely', 'Sometimes', 'Often', 'Very often' ) ),
                array( 'Do married friends include you in social activities?', array( 'Always', 'Usually', 'Sometimes', 'Rarely', 'Never' ) ),
            ),
            'God' => array(
                array( 'Has being single strengthened your relationship with God?', array( 'Yes, significantly', 'Yes, somewhat', 'No change', 'It has made faith harder', 'Not sure' ) ),
                array( 'Do you believe singleness can be a meaningful calling?', array( 'Yes', 'No', 'For some people', 'Not sure' ) ),
            ),
            'Life & Work' => array(
                array( 'Does being single make career decisions easier?', array( 'Much easier', 'Somewhat easier', 'No difference', 'Somewhat harder', 'Much harder' ) ),
                array( 'What is your biggest practical challenge as a single adult?', array( 'Finances', 'Housing', 'Work-life balance', 'Caregiving', 'Social connection' ) ),
            ),
            'The Opposite Sex' => array(
                array( 'Can single men and women maintain close platonic friendships?', array( 'Yes', 'No', 'Sometimes', 'It depends on boundaries' ) ),
                array( 'What matters most when first getting to know someone?', array( 'Shared faith', 'Character', 'Communication', 'Attraction', 'Shared goals' ) ),
            ),
            'Parenting & Childlessness' => array(
                array( 'Would you consider becoming a parent without being married?', array( 'Yes', 'No', 'Maybe', 'Not applicable' ) ),
                array( 'How difficult is it to discuss childlessness in your community?', array( 'Very easy', 'Somewhat easy', 'Neutral', 'Somewhat difficult', 'Very difficult' ) ),
            ),
            'Past Relationships' => array(
                array( 'How much should past relationships be discussed early in dating?', array( 'In detail', 'Only key facts', 'Very little', 'Not until the relationship is serious' ) ),
                array( 'Can trust be fully rebuilt after betrayal?', array( 'Yes', 'No', 'Sometimes', 'Not sure' ) ),
            ),
            'Sexuality' => array(
                array( 'How comfortable are you discussing sexual boundaries while dating?', array( 'Very comfortable', 'Somewhat comfortable', 'Neutral', 'Somewhat uncomfortable', 'Very uncomfortable' ) ),
                array( 'When should couples discuss physical boundaries?', array( 'Before the first date', 'During the first few dates', 'Once the relationship becomes serious', 'Only when a situation arises' ) ),
            ),
        );

        foreach ( $samples as $category => $questions ) {
            foreach ( $questions as $question_data ) {
                $existing = get_posts(
                    array(
                        'post_type'      => $this->post_type,
                        'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
                        'posts_per_page' => 1,
                        'title'          => $question_data[0],
                        'fields'         => 'ids',
                        'no_found_rows'  => true,
                    )
                );

                if ( ! empty( $existing ) ) {
                    continue;
                }

                $poll_id = wp_insert_post(
                    array(
                        'post_type'    => $this->post_type,
                        'post_status'  => 'publish',
                        'post_title'   => $question_data[0],
                        'post_content' => '',
                        'menu_order'   => 0,
                    ),
                    true
                );

                if ( is_wp_error( $poll_id ) ) {
                    continue;
                }

                wp_set_object_terms( $poll_id, $category, $this->taxonomy, false );
                update_post_meta( $poll_id, '_mts_cp_poll_type', 'single' );
                update_post_meta( $poll_id, '_mts_cp_poll_options', wp_slash( wp_json_encode( $question_data[1], JSON_UNESCAPED_UNICODE ) ) );
                update_post_meta( $poll_id, '_mts_cp_randomize', '1' );
                update_post_meta( $poll_id, '_mts_cp_allow_other', '1' );
                update_post_meta( $poll_id, '_mts_cp_allow_context', '1' );
                update_post_meta( $poll_id, '_mts_cp_allow_change', '0' );
                update_post_meta( $poll_id, '_mts_cp_status', 'open' );
                update_post_meta( $poll_id, '_mts_cp_vote_count', 0 );
                update_post_meta( $poll_id, '_mts_cp_is_sample', '1' );
            }
        }

        $special_samples = array(
            array(
                'title'       => 'Rank these dating priorities in order of importance to you (1st to 4th)',
                'category'    => 'Dating',
                'type'        => 'rank',
                'options'     => array( 'Shared Faith & Values', 'Character & Integrity', 'Emotional Maturity', 'Physical Attraction' ),
            ),
            array(
                'title'       => 'MaxDiff Evaluation: Which quality is Most Important and which is Least Important when choosing a partner?',
                'category'    => 'The Opposite Sex',
                'type'        => 'maxdiff',
                'options'     => array( 'Good Communication', 'Sense of Humor', 'Financial Stability', 'Shared Goals & Vision' ),
                'high_label'  => 'Most Important',
                'low_label'   => 'Least Important',
            ),
            array(
                'title'       => 'Matrix Evaluation: How much do you agree with the following statements about your church community?',
                'category'    => 'Church',
                'type'        => 'matrix',
                'options'     => array( 'Strongly Disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly Agree' ),
                'matrix_rows' => array( 'Single adults are well supported by church leadership', 'I feel comfortable attending church events alone', 'Small groups cater well to single members' ),
            ),
            array(
                'title'       => 'Sliding Scale: On a scale of 1 to 10, how satisfied are you with your current work-life balance?',
                'category'    => 'Life & Work',
                'type'        => 'slider',
                'min'         => 1,
                'max'         => 10,
                'step'        => 1,
                'min_label'   => '1 - Very Dissatisfied',
                'max_label'   => '10 - Very Satisfied',
            ),
        );

        foreach ( $special_samples as $sample ) {
            $existing = get_posts(
                array(
                    'post_type'      => $this->post_type,
                    'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
                    'posts_per_page' => 1,
                    'title'          => $sample['title'],
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                )
            );

            if ( ! empty( $existing ) ) {
                continue;
            }

            $poll_id = wp_insert_post(
                array(
                    'post_type'   => $this->post_type,
                    'post_status' => 'publish',
                    'post_title'  => $sample['title'],
                    'menu_order'  => 0,
                )
            );

            if ( is_wp_error( $poll_id ) ) {
                continue;
            }

            wp_set_object_terms( $poll_id, $sample['category'], $this->taxonomy, false );
            update_post_meta( $poll_id, '_mts_cp_poll_type', $sample['type'] );

            if ( ! empty( $sample['options'] ) ) {
                update_post_meta( $poll_id, '_mts_cp_poll_options', wp_slash( wp_json_encode( $sample['options'], JSON_UNESCAPED_UNICODE ) ) );
            }
            if ( ! empty( $sample['matrix_rows'] ) ) {
                update_post_meta( $poll_id, '_mts_cp_matrix_rows', wp_slash( wp_json_encode( $sample['matrix_rows'], JSON_UNESCAPED_UNICODE ) ) );
            }
            if ( isset( $sample['min'] ) ) {
                update_post_meta( $poll_id, '_mts_cp_slider_min', $sample['min'] );
                update_post_meta( $poll_id, '_mts_cp_slider_max', $sample['max'] );
                update_post_meta( $poll_id, '_mts_cp_slider_step', $sample['step'] );
                update_post_meta( $poll_id, '_mts_cp_slider_min_label', $sample['min_label'] );
                update_post_meta( $poll_id, '_mts_cp_slider_max_label', $sample['max_label'] );
            }
            if ( isset( $sample['high_label'] ) ) {
                update_post_meta( $poll_id, '_mts_cp_maxdiff_high_label', $sample['high_label'] );
                update_post_meta( $poll_id, '_mts_cp_maxdiff_low_label', $sample['low_label'] );
            }

            update_post_meta( $poll_id, '_mts_cp_randomize', '0' );
            update_post_meta( $poll_id, '_mts_cp_allow_other', '0' );
            update_post_meta( $poll_id, '_mts_cp_allow_context', '1' );
            update_post_meta( $poll_id, '_mts_cp_allow_change', '0' );
            update_post_meta( $poll_id, '_mts_cp_status', 'open' );
            update_post_meta( $poll_id, '_mts_cp_vote_count', 0 );
            update_post_meta( $poll_id, '_mts_cp_is_sample', '1' );
        }

        update_option( 'mts_cp_sample_polls_created', 1 );
    }

}
