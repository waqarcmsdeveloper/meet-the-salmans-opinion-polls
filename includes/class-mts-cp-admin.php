<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MTS_CP_Admin {
    private $core;

    public function __construct( MTS_CP_Core $core ) {
        $this->core = $core;

        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_' . $this->core->post_type, array( $this, 'save_poll' ) );
        add_filter( 'manage_' . $this->core->post_type . '_posts_columns', array( $this, 'columns' ) );
        add_action( 'manage_' . $this->core->post_type . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
        add_action( 'admin_menu', array( $this, 'settings_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_post_mts_cp_export_csv', array( $this, 'export_csv' ) );
        add_action( 'admin_post_mts_cp_create_samples', array( $this, 'create_samples' ) );
        add_action( 'admin_notices', array( $this, 'activation_notice' ) );

        add_filter( 'manage_edit-' . $this->core->taxonomy . '_columns', array( $this, 'category_columns' ) );
        add_filter( 'manage_' . $this->core->taxonomy . '_custom_column', array( $this, 'category_column_content' ), 10, 3 );
        add_action( $this->core->taxonomy . '_add_form_fields', array( $this, 'add_category_order_field' ) );
        add_action( $this->core->taxonomy . '_edit_form_fields', array( $this, 'edit_category_order_field' ) );
        add_action( 'created_' . $this->core->taxonomy, array( $this, 'save_category_order' ) );
        add_action( 'edited_' . $this->core->taxonomy, array( $this, 'save_category_order' ) );
    }

    public function admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        if ( $this->core->post_type === $screen->post_type || false !== strpos( (string) $hook, 'mts-cp-settings' ) ) {
            wp_enqueue_style( 'mts-cp-admin', MTS_CP_URL . 'assets/css/admin.css', array(), MTS_CP_VERSION );
        }
    }

    public function add_meta_boxes() {
        add_meta_box(
            'mts-cp-configuration',
            __( 'Poll Answer Configuration', 'mts-complete-opinion-polls' ),
            array( $this, 'configuration_box' ),
            $this->core->post_type,
            'normal',
            'high'
        );

        add_meta_box(
            'mts-cp-behavior',
            __( 'Poll Behaviour', 'mts-complete-opinion-polls' ),
            array( $this, 'behavior_box' ),
            $this->core->post_type,
            'side',
            'high'
        );

        add_meta_box(
            'mts-cp-results',
            __( 'Results Summary', 'mts-complete-opinion-polls' ),
            array( $this, 'results_box' ),
            $this->core->post_type,
            'normal',
            'default'
        );
    }

    public function configuration_box( $post ) {
        wp_nonce_field( 'mts_cp_save_poll', 'mts_cp_poll_nonce' );

        $type         = $this->core->get_poll_type( $post->ID );
        $options      = $this->core->get_poll_options( $post->ID );
        $matrix_rows  = $this->core->get_matrix_rows( $post->ID );
        $slider_cfg   = $this->core->get_slider_config( $post->ID );
        $maxdiff_lbls = $this->core->get_maxdiff_labels( $post->ID );
        ?>
        <div class="mts-cp-admin-grid">
            <p>
                <label for="mts-cp-poll-type"><strong><?php esc_html_e( 'Question type', 'mts-complete-opinion-polls' ); ?></strong></label><br>
                <select id="mts-cp-poll-type" name="mts_cp_poll_type">
                    <option value="single" <?php selected( $type, 'single' ); ?>><?php esc_html_e( 'Single choice', 'mts-complete-opinion-polls' ); ?></option>
                    <option value="multiple" <?php selected( $type, 'multiple' ); ?>><?php esc_html_e( 'Multiple choice', 'mts-complete-opinion-polls' ); ?></option>
                    <option value="textbox" <?php selected( $type, 'textbox' ); ?>><?php esc_html_e( 'Text Input / Textbox', 'mts-complete-opinion-polls' ); ?></option>
                    <option value="rank" <?php selected( $type, 'rank' ); ?>><?php esc_html_e( 'Rank / Ordering (1st, 2nd, 3rd)', 'mts-complete-opinion-polls' ); ?></option>
                    <option value="maxdiff" <?php selected( $type, 'maxdiff' ); ?>><?php esc_html_e( 'MaxDiff (Most vs Least Important)', 'mts-complete-opinion-polls' ); ?></option>
                    <option value="matrix" <?php selected( $type, 'matrix' ); ?>><?php esc_html_e( 'Matrix / Table Grid', 'mts-complete-opinion-polls' ); ?></option>
                    <option value="slider" <?php selected( $type, 'slider' ); ?>><?php esc_html_e( 'Sliding Scale (Range Slider)', 'mts-complete-opinion-polls' ); ?></option>
                </select>
            </p>
            <p class="description"><?php esc_html_e( 'Use the post title as the poll question. Use the main editor for optional supporting text.', 'mts-complete-opinion-polls' ); ?></p>
        </div>

        <!-- Answer Choices / Scale Columns -->
        <p class="mts-cp-options-wrapper" style="<?php echo in_array( $type, array( 'textbox', 'slider' ), true ) ? 'display:none;' : ''; ?>">
            <label for="mts-cp-options"><strong id="mts-cp-options-label"><?php echo 'matrix' === $type ? esc_html__( 'Rating Scale Columns (One per line)', 'mts-complete-opinion-polls' ) : esc_html__( 'Answer choices (One per line)', 'mts-complete-opinion-polls' ); ?></strong></label>
            <textarea id="mts-cp-options" class="widefat" name="mts_cp_poll_options" rows="6" placeholder="<?php echo 'matrix' === $type ? 'Strongly Disagree&#10;Disagree&#10;Neutral&#10;Agree&#10;Strongly Agree' : 'Yes&#10;No&#10;It depends'; ?>"><?php echo esc_textarea( implode( "\n", $options ) ); ?></textarea>
            <span class="description"><?php esc_html_e( 'Enter one option per line.', 'mts-complete-opinion-polls' ); ?></span>
        </p>

        <!-- Matrix Statements / Rows -->
        <p class="mts-cp-matrix-wrapper" style="<?php echo ( 'matrix' === $type ) ? '' : 'display:none;'; ?>">
            <label for="mts-cp-matrix-rows"><strong><?php esc_html_e( 'Statements / Table Rows (One per line)', 'mts-complete-opinion-polls' ); ?></strong></label>
            <textarea id="mts-cp-matrix-rows" class="widefat" name="mts_cp_matrix_rows" rows="6" placeholder="Statement 1&#10;Statement 2&#10;Statement 3"><?php echo esc_textarea( implode( "\n", $matrix_rows ) ); ?></textarea>
            <span class="description"><?php esc_html_e( 'Enter the list of statements that users will evaluate on the rating scale.', 'mts-complete-opinion-polls' ); ?></span>
        </p>

        <!-- Sliding Scale Settings -->
        <div class="mts-cp-slider-wrapper" style="<?php echo ( 'slider' === $type ) ? '' : 'display:none;'; ?>">
            <p><strong><?php esc_html_e( 'Slider Scale Configuration', 'mts-complete-opinion-polls' ); ?></strong></p>
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:10px;">
                <label><span>Min Value:</span> <input type="number" name="mts_cp_slider_min" value="<?php echo esc_attr( $slider_cfg['min'] ); ?>" style="width:70px;"></label>
                <label><span>Max Value:</span> <input type="number" name="mts_cp_slider_max" value="<?php echo esc_attr( $slider_cfg['max'] ); ?>" style="width:70px;"></label>
                <label><span>Step:</span> <input type="number" name="mts_cp_slider_step" value="<?php echo esc_attr( $slider_cfg['step'] ); ?>" style="width:70px;"></label>
            </div>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <label style="flex:1;"><span>Min Label (e.g. 1 / Lowest):</span> <input type="text" class="widefat" name="mts_cp_slider_min_label" value="<?php echo esc_attr( $slider_cfg['min_label'] ); ?>"></label>
                <label style="flex:1;"><span>Max Label (e.g. 10 / Highest):</span> <input type="text" class="widefat" name="mts_cp_slider_max_label" value="<?php echo esc_attr( $slider_cfg['max_label'] ); ?>"></label>
            </div>
        </div>

        <!-- MaxDiff Custom Labels -->
        <div class="mts-cp-maxdiff-wrapper" style="<?php echo ( 'maxdiff' === $type ) ? '' : 'display:none;'; ?>">
            <p><strong><?php esc_html_e( 'MaxDiff Header Labels', 'mts-complete-opinion-polls' ); ?></strong></p>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <label style="flex:1;"><span>Best / High Label:</span> <input type="text" class="widefat" name="mts_cp_maxdiff_high_label" value="<?php echo esc_attr( $maxdiff_lbls['high'] ); ?>" placeholder="Most Important"></label>
                <label style="flex:1;"><span>Worst / Low Label:</span> <input type="text" class="widefat" name="mts_cp_maxdiff_low_label" value="<?php echo esc_attr( $maxdiff_lbls['low'] ); ?>" placeholder="Least Important"></label>
            </div>
        </div>

        <!-- Dynamic JS for Admin Section -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function togglePollFields() {
                var selectedType = $('#mts-cp-poll-type').val();
                
                $('.mts-cp-options-wrapper').toggle(selectedType !== 'textbox' && selectedType !== 'slider');
                $('.mts-cp-matrix-wrapper').toggle(selectedType === 'matrix');
                $('.mts-cp-slider-wrapper').toggle(selectedType === 'slider');
                $('.mts-cp-maxdiff-wrapper').toggle(selectedType === 'maxdiff');

                if (selectedType === 'matrix') {
                    $('#mts-cp-options-label').text('Rating Scale Columns (One per line)');
                } else {
                    $('#mts-cp-options-label').text('Answer choices (One per line)');
                }

                if (selectedType === 'textbox') {
                    $('#mts-cp-other-option-wrap').show();
                } else {
                    $('#mts-cp-other-option-wrap').hide();
                    $('#mts-cp-allow-other-check').prop('checked', false);
                }
            }

            $(document).on('change', '#mts-cp-poll-type', togglePollFields);
            togglePollFields();
        });
        </script>
        <?php
    }

    public function behavior_box( $post ) {
        $type          = $this->core->get_poll_type( $post->ID );
        $randomize     = $this->core->flag( $post->ID, '_mts_cp_randomize', '1' );
        $allow_other   = $this->core->flag( $post->ID, '_mts_cp_allow_other', '1' );
        $allow_context = $this->core->flag( $post->ID, '_mts_cp_allow_context', '1' );
        $allow_change  = $this->core->flag( $post->ID, '_mts_cp_allow_change', '0' );
        $status        = get_post_meta( $post->ID, '_mts_cp_status', true );
        $status        = 'closed' === $status ? 'closed' : 'open';
        ?>
        <p>
            <label for="mts-cp-status"><strong><?php esc_html_e( 'Status', 'mts-complete-opinion-polls' ); ?></strong></label><br>
            <select id="mts-cp-status" name="mts_cp_status" class="widefat">
                <option value="open" <?php selected( $status, 'open' ); ?>><?php esc_html_e( 'Open for answers', 'mts-complete-opinion-polls' ); ?></option>
                <option value="closed" <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'mts-complete-opinion-polls' ); ?></option>
            </select>
        </p>
        <label class="mts-cp-check"><input type="checkbox" name="mts_cp_randomize" value="1" <?php checked( $randomize ); ?>> <span><strong><?php esc_html_e( 'Randomize answer order', 'mts-complete-opinion-polls' ); ?></strong><small><?php esc_html_e( 'Reduces first-listed answer bias.', 'mts-complete-opinion-polls' ); ?></small></span></label>
        
        <!-- Conditional "Other" Option Wrap (Displays strictly when Type = 'textbox') -->
        <div id="mts-cp-other-option-wrap" style="<?php echo ( 'textbox' === $type ) ? '' : 'display:none;'; ?>">
            <label class="mts-cp-check">
                <input type="checkbox" id="mts-cp-allow-other-check" name="mts_cp_allow_other" value="1" <?php checked( $allow_other ); ?>> 
                <span><strong><?php esc_html_e( 'Add “Other” answer / Custom Textbox', 'mts-complete-opinion-polls' ); ?></strong><small><?php esc_html_e( 'Opens a text field when selected.', 'mts-complete-opinion-polls' ); ?></small></span>
            </label>
        </div>

        <label class="mts-cp-check"><input type="checkbox" name="mts_cp_allow_context" value="1" <?php checked( $allow_context ); ?>> <span><strong><?php esc_html_e( 'Allow added context', 'mts-complete-opinion-polls' ); ?></strong><small><?php esc_html_e( 'Shows the Add Context button.', 'mts-complete-opinion-polls' ); ?></small></span></label>
        <label class="mts-cp-check"><input type="checkbox" name="mts_cp_allow_change" value="1" <?php checked( $allow_change ); ?>> <span><strong><?php esc_html_e( 'Allow answer changes', 'mts-complete-opinion-polls' ); ?></strong><small><?php esc_html_e( 'A new submission replaces the previous one.', 'mts-complete-opinion-polls' ); ?></small></span></label>
        <p class="description"><?php esc_html_e( 'Results are always available immediately. There is no minimum response threshold.', 'mts-complete-opinion-polls' ); ?></p>
        <?php
    }

    public function results_box( $post ) {
        $results = $this->core->get_results( $post->ID );
        $total   = absint( $results['total_votes'] );
        $type    = isset( $results['type'] ) ? $results['type'] : 'single';
        ?>
        <div class="mts-cp-admin-results">
            <div class="mts-cp-total-card">
                <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
                <span><?php echo esc_html( _n( 'total vote', 'total votes', $total, 'mts-complete-opinion-polls' ) ); ?></span>
            </div>
            <div class="mts-cp-admin-bars">
                <?php if ( 'rank' === $type && ! empty( $results['rank_data'] ) ) : ?>
                    <?php foreach ( $results['rank_data'] as $label => $info ) : ?>
                        <div class="mts-cp-admin-result-row">
                            <div><span><?php echo esc_html( $label ); ?></span><strong>Avg Rank: <?php echo esc_html( $info['avg_rank'] ); ?> (<?php echo esc_html( $info['first_place_pct'] ); ?>% #1)</strong></div>
                            <div class="mts-cp-admin-bar"><span style="width:<?php echo esc_attr( max( 5, 100 - ( ( $info['avg_rank'] - 1 ) * 25 ) ) ); ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ( 'maxdiff' === $type && ! empty( $results['maxdiff_data'] ) ) : ?>
                    <?php foreach ( $results['maxdiff_data'] as $label => $info ) : ?>
                        <div class="mts-cp-admin-result-row">
                            <div><span><?php echo esc_html( $label ); ?></span><strong>Net: <?php echo esc_html( $info['net_score'] > 0 ? '+' . $info['net_score'] : $info['net_score'] ); ?>% (Most: <?php echo esc_html( $info['most_pct'] ); ?>%, Least: <?php echo esc_html( $info['least_pct'] ); ?>%)</strong></div>
                            <div class="mts-cp-admin-bar"><span style="width:<?php echo esc_attr( min( 100, max( 5, $info['most_pct'] ) ) ); ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ( 'matrix' === $type && ! empty( $results['matrix_counts'] ) ) : ?>
                    <?php foreach ( $results['matrix_counts'] as $stmt => $cols ) : ?>
                        <div class="mts-cp-admin-result-row" style="margin-bottom:10px;">
                            <strong><?php echo esc_html( $stmt ); ?></strong>
                            <div style="font-size:12px; color:#555;">
                                <?php
                                $col_bits = array();
                                foreach ( $cols as $cname => $ccount ) {
                                    $col_bits[] = esc_html( $cname ) . ': ' . absint( $ccount );
                                }
                                echo implode( ' | ', $col_bits );
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ( 'slider' === $type ) : ?>
                    <div class="mts-cp-admin-result-row">
                        <div><span>Average Score</span><strong><?php echo esc_html( $results['avg_val'] ); ?> / <?php echo esc_html( $results['config']['max'] ); ?></strong></div>
                        <div class="mts-cp-admin-bar"><span style="width:<?php echo esc_attr( $results['config']['max'] > 0 ? min( 100, round( ( $results['avg_val'] / $results['config']['max'] ) * 100 ) ) : 0 ); ?>%"></span></div>
                    </div>
                <?php elseif ( ! empty( $results['counts'] ) ) : ?>
                    <?php foreach ( $results['counts'] as $label => $count ) :
                        $percentage = $total > 0 ? round( ( absint( $count ) / $total ) * 100, 1 ) : 0;
                        ?>
                        <div class="mts-cp-admin-result-row">
                            <div><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $count ) ); ?> (<?php echo esc_html( $percentage ); ?>%)</strong></div>
                            <div class="mts-cp-admin-bar"><span style="width:<?php echo esc_attr( min( 100, $percentage ) ); ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mts_cp_export_csv&poll_id=' . $post->ID ), 'mts_cp_export_' . $post->ID ) ); ?>"><?php esc_html_e( 'Export Responses as CSV', 'mts-complete-opinion-polls' ); ?></a>
        </p>
        <p class="description"><?php esc_html_e( 'Public result charts show combined totals only. The CSV is available to administrators and includes response text and optional context.', 'mts-complete-opinion-polls' ); ?></p>
        <?php
    }

    public function save_poll( $post_id ) {
        if ( ! isset( $_POST['mts_cp_poll_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mts_cp_poll_nonce'] ) ), 'mts_cp_save_poll' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $type = isset( $_POST['mts_cp_poll_type'] ) ? sanitize_key( wp_unslash( $_POST['mts_cp_poll_type'] ) ) : 'single';
        if ( ! in_array( $type, array( 'single', 'multiple', 'textbox', 'rank', 'maxdiff', 'matrix', 'slider' ), true ) ) {
            $type = 'single';
        }

        $raw_options = isset( $_POST['mts_cp_poll_options'] ) ? (string) wp_unslash( $_POST['mts_cp_poll_options'] ) : '';
        $lines       = preg_split( '/\r\n|\r|\n/', $raw_options );
        $options     = array();
        foreach ( (array) $lines as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( '' !== $line && ! in_array( $line, $options, true ) ) {
                $options[] = $line;
            }
        }

        $raw_matrix_rows = isset( $_POST['mts_cp_matrix_rows'] ) ? (string) wp_unslash( $_POST['mts_cp_matrix_rows'] ) : '';
        $matrix_lines    = preg_split( '/\r\n|\r|\n/', $raw_matrix_rows );
        $matrix_rows     = array();
        foreach ( (array) $matrix_lines as $mline ) {
            $mline = trim( sanitize_text_field( $mline ) );
            if ( '' !== $mline && ! in_array( $mline, $matrix_rows, true ) ) {
                $matrix_rows[] = $mline;
            }
        }

        $status = isset( $_POST['mts_cp_status'] ) ? sanitize_key( wp_unslash( $_POST['mts_cp_status'] ) ) : 'open';
        $status = 'closed' === $status ? 'closed' : 'open';

        update_post_meta( $post_id, '_mts_cp_poll_type', $type );
        update_post_meta( $post_id, '_mts_cp_poll_options', wp_slash( wp_json_encode( $options, JSON_UNESCAPED_UNICODE ) ) );
        update_post_meta( $post_id, '_mts_cp_matrix_rows', wp_slash( wp_json_encode( $matrix_rows, JSON_UNESCAPED_UNICODE ) ) );

        update_post_meta( $post_id, '_mts_cp_slider_min', isset( $_POST['mts_cp_slider_min'] ) ? intval( $_POST['mts_cp_slider_min'] ) : 1 );
        update_post_meta( $post_id, '_mts_cp_slider_max', isset( $_POST['mts_cp_slider_max'] ) ? intval( $_POST['mts_cp_slider_max'] ) : 10 );
        update_post_meta( $post_id, '_mts_cp_slider_step', isset( $_POST['mts_cp_slider_step'] ) ? max( 1, intval( $_POST['mts_cp_slider_step'] ) ) : 1 );
        update_post_meta( $post_id, '_mts_cp_slider_min_label', isset( $_POST['mts_cp_slider_min_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mts_cp_slider_min_label'] ) ) : '' );
        update_post_meta( $post_id, '_mts_cp_slider_max_label', isset( $_POST['mts_cp_slider_max_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mts_cp_slider_max_label'] ) ) : '' );

        update_post_meta( $post_id, '_mts_cp_maxdiff_high_label', isset( $_POST['mts_cp_maxdiff_high_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mts_cp_maxdiff_high_label'] ) ) : '' );
        update_post_meta( $post_id, '_mts_cp_maxdiff_low_label', isset( $_POST['mts_cp_maxdiff_low_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mts_cp_maxdiff_low_label'] ) ) : '' );

        update_post_meta( $post_id, '_mts_cp_randomize', isset( $_POST['mts_cp_randomize'] ) ? '1' : '0' );
        
        // Save allow_other as '1' ONLY IF Question Type is 'textbox' AND checkbox is ticked
        if ( 'textbox' === $type && isset( $_POST['mts_cp_allow_other'] ) ) {
            update_post_meta( $post_id, '_mts_cp_allow_other', '1' );
        } else {
            update_post_meta( $post_id, '_mts_cp_allow_other', '0' );
        }

        update_post_meta( $post_id, '_mts_cp_allow_context', isset( $_POST['mts_cp_allow_context'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_mts_cp_allow_change', isset( $_POST['mts_cp_allow_change'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_mts_cp_status', $status );

        if ( '' === get_post_meta( $post_id, '_mts_cp_vote_count', true ) ) {
            update_post_meta( $post_id, '_mts_cp_vote_count', 0 );
        }
    }

    public function columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['mts_cp_votes']  = __( 'Votes', 'mts-complete-opinion-polls' );
                $new['mts_cp_status'] = __( 'Status', 'mts-complete-opinion-polls' );
            }
        }
        return $new;
    }

    public function column_content( $column, $post_id ) {
        if ( 'mts_cp_votes' === $column ) {
            echo esc_html( number_format_i18n( $this->core->get_vote_count( $post_id ) ) );
        }
        if ( 'mts_cp_status' === $column ) {
            echo $this->core->poll_is_open( $post_id ) ? '<span class="mts-cp-status-open">Open</span>' : '<span class="mts-cp-status-closed">Closed</span>';
        }
    }

    public function settings_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->core->post_type,
            __( 'Opinion Poll Settings', 'mts-complete-opinion-polls' ),
            __( 'Settings & Setup', 'mts-complete-opinion-polls' ),
            'manage_options',
            'mts-cp-settings',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'mts_cp_settings_group',
            'mts_cp_settings',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => MTS_CP_Core::default_settings(),
            )
        );
    }

    public function sanitize_settings( $input ) {
        $defaults = MTS_CP_Core::default_settings();
        $output   = $defaults;

        foreach ( array( 'register_page_id', 'login_page_id', 'polls_page_id', 'account_page_id' ) as $key ) {
            $output[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
        }

        foreach ( array( 'accent_color', 'heading_bg', 'soft_bg' ) as $key ) {
            $value          = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
            $output[ $key ] = $value ? $value : $defaults[ $key ];
        }

        foreach ( array( 'library_heading', 'library_subheading', 'empty_text', 'registration_heading', 'login_heading' ) as $key ) {
            $output[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
        }

        $output['privacy_text']        = isset( $input['privacy_text'] ) ? sanitize_textarea_field( $input['privacy_text'] ) : $defaults['privacy_text'];
        $output['require_login']       = '0';
        $output['allow_answer_change'] = isset( $input['allow_answer_change'] ) ? '1' : '0';
        $output['delete_on_uninstall'] = isset( $input['delete_on_uninstall'] ) ? '1' : '0';

        return $output;
    }

    private function page_dropdown( $name, $selected ) {
        wp_dropdown_pages(
            array(
                'name'              => 'mts_cp_settings[' . esc_attr( $name ) . ']',
                'selected'          => absint( $selected ),
                'show_option_none'  => __( '— Select a page —', 'mts-complete-opinion-polls' ),
                'option_none_value' => 0,
            )
        );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->core->settings();
        ?>
        <div class="wrap mts-cp-settings-wrap">
            <h1><?php esc_html_e( 'Opinion Polls — Settings & Setup', 'mts-complete-opinion-polls' ); ?></h1>

            <?php if ( isset( $_GET['samples'] ) && 'created' === sanitize_key( wp_unslash( $_GET['samples'] ) ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sample poll questions were created.', 'mts-complete-opinion-polls' ); ?></p></div>
            <?php endif; ?>

            <div class="mts-cp-setup-flow">
                <h2><?php esc_html_e( 'User Flow', 'mts-complete-opinion-polls' ); ?></h2>
                <div class="mts-cp-flow-steps">
                    <span>1. Opinion Polls</span><b>→</b><span>2. Select Category / Search</span><b>→</b><span>3. Answer / Results</span>
                </div>
                <p><?php esc_html_e( 'Visitors can directly view opinion polls, filter by category or search keywords, submit answers, and view live results without registration or login.', 'mts-complete-opinion-polls' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'mts_cp_settings_group' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Registration page', 'mts-complete-opinion-polls' ); ?></th>
                        <td><?php $this->page_dropdown( 'register_page_id', $settings['register_page_id'] ); ?><p class="description"><code>[mts_poll_register]</code></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Login page', 'mts-complete-opinion-polls' ); ?></th>
                        <td><?php $this->page_dropdown( 'login_page_id', $settings['login_page_id'] ); ?><p class="description"><code>[mts_poll_login]</code></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Opinion Polls page', 'mts-complete-opinion-polls' ); ?></th>
                        <td><?php $this->page_dropdown( 'polls_page_id', $settings['polls_page_id'] ); ?><p class="description"><code>[mts_opinion_polls]</code></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'My answers page', 'mts-complete-opinion-polls' ); ?></th>
                        <td><?php $this->page_dropdown( 'account_page_id', $settings['account_page_id'] ); ?><p class="description"><code>[mts_my_poll_answers]</code></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Accent color', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input type="color" name="mts_cp_settings[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Category heading background', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input type="color" name="mts_cp_settings[heading_bg]" value="<?php echo esc_attr( $settings['heading_bg'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Soft panel background', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input type="color" name="mts_cp_settings[soft_bg]" value="<?php echo esc_attr( $settings['soft_bg'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Poll library heading', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input class="regular-text" type="text" name="mts_cp_settings[library_heading]" value="<?php echo esc_attr( $settings['library_heading'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Poll library subheading', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input class="large-text" type="text" name="mts_cp_settings[library_subheading]" value="<?php echo esc_attr( $settings['library_subheading'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Registration heading', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input class="regular-text" type="text" name="mts_cp_settings[registration_heading]" value="<?php echo esc_attr( $settings['registration_heading'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Login heading', 'mts-complete-opinion-polls' ); ?></th>
                        <td><input class="regular-text" type="text" name="mts_cp_settings[login_heading]" value="<?php echo esc_attr( $settings['login_heading'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Privacy message', 'mts-complete-opinion-polls' ); ?></th>
                        <td><textarea class="large-text" rows="3" name="mts_cp_settings[privacy_text]"><?php echo esc_textarea( $settings['privacy_text'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Global answer changes', 'mts-complete-opinion-polls' ); ?></th>
                        <td><label><input type="checkbox" name="mts_cp_settings[allow_answer_change]" value="1" <?php checked( $settings['allow_answer_change'], '1' ); ?>> <?php esc_html_e( 'Allow users to change answers unless a poll overrides this setting', 'mts-complete-opinion-polls' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Uninstall cleanup', 'mts-complete-opinion-polls' ); ?></th>
                        <td><label><input type="checkbox" name="mts_cp_settings[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'], '1' ); ?>> <?php esc_html_e( 'Delete all polls, votes, categories, and settings when the plugin is deleted', 'mts-complete-opinion-polls' ); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <div class="mts-cp-help-card">
                <h2><?php esc_html_e( 'Sample Poll Questions', 'mts-complete-opinion-polls' ); ?></h2>
                <p><?php esc_html_e( 'The plugin creates sample questions across all 11 client categories on first activation. Use the button below only if they were removed and need to be restored.', 'mts-complete-opinion-polls' ); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mts_cp_create_samples' ), 'mts_cp_create_samples' ) ); ?>"><?php esc_html_e( 'Create Missing Sample Polls', 'mts-complete-opinion-polls' ); ?></a>
            </div>
        </div>
        <?php
    }

    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to export poll responses.', 'mts-complete-opinion-polls' ) );
        }

        $poll_id = isset( $_GET['poll_id'] ) ? absint( $_GET['poll_id'] ) : 0;
        check_admin_referer( 'mts_cp_export_' . $poll_id );

        if ( ! $poll_id || $this->core->post_type !== get_post_type( $poll_id ) ) {
            wp_die( esc_html__( 'Invalid poll.', 'mts-complete-opinion-polls' ) );
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->core->table} WHERE poll_id = %d ORDER BY created_at ASC", $poll_id ) );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="poll-' . $poll_id . '-responses.csv"' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'Response ID', 'Gender', 'Answer', 'Other response', 'Additional context', 'Submitted at', 'Updated at' ) );

        foreach ( $rows as $row ) {
            $answers = json_decode( (string) $row->answer_json, true );
            if ( is_array( $answers ) ) {
                $formatted_bits = array();
                foreach ( $answers as $k => $v ) {
                    if ( is_numeric( $k ) ) {
                        $formatted_bits[] = '__other__' === $v ? 'Other' : $v;
                    } else {
                        $formatted_bits[] = $k . ': ' . ( is_array( $v ) ? implode( ',', $v ) : $v );
                    }
                }
                $ans_str = implode( ' | ', $formatted_bits );
            } else {
                $ans_str = (string) $answers;
            }

            fputcsv(
                $output,
                array(
                    absint( $row->id ),
                    sanitize_text_field( $row->gender ),
                    $ans_str,
                    sanitize_textarea_field( $row->other_text ),
                    sanitize_textarea_field( $row->context_text ),
                    sanitize_text_field( $row->created_at ),
                    sanitize_text_field( $row->updated_at ),
                )
            );
        }

        fclose( $output );
        exit;
    }

    public function create_samples() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to create sample polls.', 'mts-complete-opinion-polls' ) );
        }
        check_admin_referer( 'mts_cp_create_samples' );

        delete_option( 'mts_cp_sample_polls_created' );
        $this->core->create_sample_polls();

        wp_safe_redirect( add_query_arg( 'samples', 'created', admin_url( 'edit.php?post_type=' . $this->core->post_type . '&page=mts-cp-settings' ) ) );
        exit;
    }

    public function activation_notice() {
        if ( ! current_user_can( 'manage_options' ) || get_option( 'mts_cp_activation_notice_dismissed' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'plugins' !== $screen->base ) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php esc_html_e( 'MTS Complete Opinion Polls is ready.', 'mts-complete-opinion-polls' ); ?></strong> <?php esc_html_e( 'Registration, Login, Opinion Polls, and My Answers pages were created automatically. Sample questions were also added across all client categories.', 'mts-complete-opinion-polls' ); ?></p>
        </div>
        <?php
        update_option( 'mts_cp_activation_notice_dismissed', 1 );
    }

    public function category_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $title ) {
            $new[ $key ] = $title;
            if ( 'name' === $key ) {
                $new['mts_cp_order'] = __( 'Order', 'mts-complete-opinion-polls' );
            }
        }
        return $new;
    }

    public function category_column_content( $content, $column_name, $term_id ) {
        if ( 'mts_cp_order' === $column_name ) {
            $order = get_term_meta( $term_id, 'mts_cp_order', true );
            return $order ? esc_html( $order ) : '—';
        }
        return $content;
    }

    public function add_category_order_field() {
        ?>
        <div class="form-field term-order-wrap">
            <label for="mts-cp-order"><?php esc_html_e( 'Display Order', 'mts-complete-opinion-polls' ); ?></label>
            <input type="number" name="mts_cp_order" id="mts-cp-order" value="" style="width: 100px;">
            <p><?php esc_html_e( 'Numerical order for sorting (1 = first).', 'mts-complete-opinion-polls' ); ?></p>
        </div>
        <?php
    }

    public function edit_category_order_field( $term ) {
        $order = get_term_meta( $term->term_id, 'mts_cp_order', true );
        ?>
        <tr class="form-field term-order-wrap">
            <th scope="row"><label for="mts-cp-order"><?php esc_html_e( 'Display Order', 'mts-complete-opinion-polls' ); ?></label></th>
            <td>
                <input type="number" name="mts_cp_order" id="mts-cp-order" value="<?php echo esc_attr( $order ); ?>" style="width: 100px;">
                <p class="description"><?php esc_html_e( 'Numerical order for sorting (1 = first).', 'mts-complete-opinion-polls' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_category_order( $term_id ) {
        if ( isset( $_POST['mts_cp_order'] ) ) {
            update_term_meta( $term_id, 'mts_cp_order', intval( $_POST['mts_cp_order'] ) );
        }
    }
}