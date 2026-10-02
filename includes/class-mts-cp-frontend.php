<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MTS_CP_Frontend {
    private $core;
    private $assets_loaded = false;

    public function __construct( MTS_CP_Core $core ) {
        $this->core = $core;

        add_shortcode( 'mts_poll_register', array( $this, 'register_shortcode' ) );
        add_shortcode( 'mts_poll_login', array( $this, 'login_shortcode' ) );
        add_shortcode( 'mts_opinion_polls', array( $this, 'poll_library_shortcode' ) );
        add_shortcode( 'mts_my_poll_answers', array( $this, 'my_answers_shortcode' ) );
        add_shortcode( 'mts_single_opinion_poll', array( $this, 'single_poll_shortcode' ) );

        add_action( 'template_redirect', array( $this, 'enforce_registration_first_flow' ), 1 );
        add_action( 'template_redirect', array( $this, 'handle_auth_requests' ), 5 );

        add_action( 'wp_ajax_mts_cp_filter_polls', array( $this, 'ajax_filter_polls' ) );
        add_action( 'wp_ajax_mts_cp_get_answer_form', array( $this, 'ajax_get_answer_form' ) );
        add_action( 'wp_ajax_mts_cp_submit_vote', array( $this, 'ajax_submit_vote' ) );
        add_action( 'wp_ajax_mts_cp_get_results', array( $this, 'ajax_get_results' ) );

        add_action( 'wp_ajax_nopriv_mts_cp_filter_polls', array( $this, 'ajax_filter_polls' ) );
        add_action( 'wp_ajax_nopriv_mts_cp_get_answer_form', array( $this, 'ajax_get_answer_form' ) );
        add_action( 'wp_ajax_nopriv_mts_cp_submit_vote', array( $this, 'ajax_submit_vote' ) );
        add_action( 'wp_ajax_nopriv_mts_cp_get_results', array( $this, 'ajax_get_results' ) );
    }


    /**
     * Registration/login enforcement disabled. Direct access allowed to Opinion Polls.
     */
    public function enforce_registration_first_flow() {
        return;
    }

    private function enqueue_assets() {
        if ( $this->assets_loaded ) {
            return;
        }

        $this->assets_loaded = true;
        $settings = $this->core->settings();

        wp_enqueue_style( 'mts-cp-frontend', MTS_CP_URL . 'assets/css/frontend.css', array(), MTS_CP_VERSION );
        wp_enqueue_script( 'mts-cp-frontend', MTS_CP_URL . 'assets/js/frontend.js', array(), MTS_CP_VERSION, true );
        wp_localize_script(
            'mts-cp-frontend',
            'MTSCompletePolls',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'mts_cp_frontend' ),
                'loginUrl'    => $this->core->page_url( 'login' ),
                'registerUrl' => $this->core->page_url( 'register' ),
                'pollsUrl'    => $this->core->page_url( 'polls' ),
                'strings'     => array(
                    'loading'          => __( 'Loading…', 'mts-complete-opinion-polls' ),
                    'error'            => __( 'Something went wrong. Please try again.', 'mts-complete-opinion-polls' ),
                    'chooseAnswer'     => __( 'Please select an answer.', 'mts-complete-opinion-polls' ),
                    'enterOther'       => __( 'Please type your “Other” answer.', 'mts-complete-opinion-polls' ),
                    'tooManyChoices'   => __( 'Please select no more than the allowed number of answers.', 'mts-complete-opinion-polls' ),
                    'loginRequired'    => __( 'Please log in to continue.', 'mts-complete-opinion-polls' ),
                    'confirmSubmit'    => __( 'Submit this answer?', 'mts-complete-opinion-polls' ),
                    'searching'        => __( 'Searching polls…', 'mts-complete-opinion-polls' ),
                ),
                'settings'    => array(
                    'accentColor' => $settings['accent_color'],
                ),
            )
        );
    }

    private function app_style() {
        $settings = $this->core->settings();
        return sprintf(
            '--mts-cp-accent:%1$s;--mts-cp-heading-bg:%2$s;--mts-cp-soft-bg:%3$s;',
            esc_attr( $settings['accent_color'] ),
            esc_attr( $settings['heading_bg'] ),
            esc_attr( $settings['soft_bg'] )
        );
    }

    public function handle_auth_requests() {
        if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
            return;
        }

        $action = isset( $_POST['mts_cp_auth_action'] ) ? sanitize_key( wp_unslash( $_POST['mts_cp_auth_action'] ) ) : '';
        if ( ! in_array( $action, array( 'register', 'login' ), true ) ) {
            return;
        }

        if ( ! isset( $_POST['mts_cp_auth_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mts_cp_auth_nonce'] ) ), 'mts_cp_auth' ) ) {
            $this->redirect_with_notice( 'error', __( 'Security check failed. Please try again.', 'mts-complete-opinion-polls' ), 'register' === $action ? 'register' : 'login' );
        }

        if ( 'register' === $action ) {
            $this->process_registration();
        }

        $this->process_login();
    }

    private function process_registration() {
        if ( is_user_logged_in() ) {
            wp_safe_redirect( $this->core->page_url( 'polls' ) );
            exit;
        }

        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password   = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $confirm    = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';
        $consent    = ! empty( $_POST['privacy_consent'] );
        $website    = isset( $_POST['website'] ) ? trim( (string) wp_unslash( $_POST['website'] ) ) : '';
        $started_at = isset( $_POST['started_at'] ) ? absint( $_POST['started_at'] ) : 0;

        if ( '' !== $website || ( $started_at && time() - $started_at < 2 ) ) {
            $this->redirect_with_notice( 'error', __( 'Registration could not be completed. Please try again.', 'mts-complete-opinion-polls' ), 'register' );
        }

        if ( '' === $first_name ) {
            $this->redirect_with_notice( 'error', __( 'Please enter your first name.', 'mts-complete-opinion-polls' ), 'register' );
        }
        if ( ! is_email( $email ) ) {
            $this->redirect_with_notice( 'error', __( 'Please enter a valid email address.', 'mts-complete-opinion-polls' ), 'register' );
        }
        if ( email_exists( $email ) ) {
            $this->redirect_with_notice( 'error', __( 'An account already exists for this email. Please log in instead.', 'mts-complete-opinion-polls' ), 'register' );
        }
        if ( strlen( $password ) < 8 ) {
            $this->redirect_with_notice( 'error', __( 'Your password must contain at least 8 characters.', 'mts-complete-opinion-polls' ), 'register' );
        }
        if ( $password !== $confirm ) {
            $this->redirect_with_notice( 'error', __( 'The passwords do not match.', 'mts-complete-opinion-polls' ), 'register' );
        }
        if ( ! $consent ) {
            $this->redirect_with_notice( 'error', __( 'Please accept the privacy statement to create your account.', 'mts-complete-opinion-polls' ), 'register' );
        }

        $base     = sanitize_user( strstr( $email, '@', true ), true );
        $base     = $base ? $base : 'poll-user';
        $username = $base;
        $suffix   = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $suffix;
            ++$suffix;
        }

        $display_name = trim( $first_name . ' ' . $last_name );
        $user_id      = wp_insert_user(
            array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => $password,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => $display_name ? $display_name : $username,
                'role'         => 'subscriber',
            )
        );

        if ( is_wp_error( $user_id ) ) {
            $this->redirect_with_notice( 'error', $user_id->get_error_message(), 'register' );
        }

        update_user_meta( $user_id, 'mts_cp_privacy_consent', current_time( 'mysql' ) );

        $login_url = add_query_arg(
            array(
                'mts_cp_notice' => 'registered',
                'email'         => rawurlencode( $email ),
            ),
            $this->core->page_url( 'login' )
        );
        wp_safe_redirect( $login_url );
        exit;
    }

    private function process_login() {
        if ( is_user_logged_in() ) {
            wp_safe_redirect( $this->core->page_url( 'polls' ) );
            exit;
        }

        $identity = isset( $_POST['identity'] ) ? sanitize_text_field( wp_unslash( $_POST['identity'] ) ) : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $remember = ! empty( $_POST['remember'] );

        if ( is_email( $identity ) ) {
            $user = get_user_by( 'email', sanitize_email( $identity ) );
            if ( $user ) {
                $identity = $user->user_login;
            }
        }

        $result = wp_signon(
            array(
                'user_login'    => $identity,
                'user_password' => $password,
                'remember'      => $remember,
            ),
            is_ssl()
        );

        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( 'error', __( 'The email/username or password is incorrect.', 'mts-complete-opinion-polls' ), 'login' );
        }

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
        if ( ! $redirect || 0 !== strpos( $redirect, home_url() ) ) {
            $redirect = $this->core->page_url( 'polls' );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    private function redirect_with_notice( $type, $message, $page_key ) {
        $url = add_query_arg(
            array(
                'mts_cp_notice' => sanitize_key( $type ),
                'mts_cp_message' => rawurlencode( $message ),
            ),
            $this->core->page_url( $page_key )
        );
        wp_safe_redirect( $url );
        exit;
    }

    private function render_notice_from_query() {
        $notice = isset( $_GET['mts_cp_notice'] ) ? sanitize_key( wp_unslash( $_GET['mts_cp_notice'] ) ) : '';
        if ( ! $notice ) {
            return '';
        }

        if ( 'registered' === $notice ) {
            $message = __( 'Registration successful. Please log in to continue to the Opinion Polls page.', 'mts-complete-opinion-polls' );
            $class   = 'success';
        } else {
            $message = isset( $_GET['mts_cp_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mts_cp_message'] ) ) ) : '';
            $class   = 'success' === $notice ? 'success' : 'error';
        }

        if ( ! $message ) {
            return '';
        }

        return '<div class="mts-cp-notice mts-cp-notice--' . esc_attr( $class ) . '" role="alert">' . esc_html( $message ) . '</div>';
    }

    public function register_shortcode() {
        $this->enqueue_assets();
        $settings = $this->core->settings();

        if ( is_user_logged_in() ) {
            return $this->logged_in_account_card();
        }

        ob_start();
        ?>
        <section class="mts-cp-app mts-cp-auth-page" style="<?php echo esc_attr( $this->app_style() ); ?>">
            <?php echo $this->render_notice_from_query(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="mts-cp-auth-shell">
                <div class="mts-cp-auth-copy">
                    <span class="mts-cp-eyebrow"><?php esc_html_e( 'Step 1 of 2', 'mts-complete-opinion-polls' ); ?></span>
                    <h1><?php echo esc_html( $settings['registration_heading'] ); ?></h1>
                    <p><?php esc_html_e( 'Register once to answer polls, view live results, and prevent duplicate voting.', 'mts-complete-opinion-polls' ); ?></p>
                    <ol class="mts-cp-flow-list">
                        <li><span>1</span><?php esc_html_e( 'Create your account', 'mts-complete-opinion-polls' ); ?></li>
                        <li><span>2</span><?php esc_html_e( 'Log in securely', 'mts-complete-opinion-polls' ); ?></li>
                        <li><span>3</span><?php esc_html_e( 'Choose a category or search', 'mts-complete-opinion-polls' ); ?></li>
                        <li><span>4</span><?php esc_html_e( 'Answer and view live results', 'mts-complete-opinion-polls' ); ?></li>
                    </ol>
                </div>
                <form method="post" class="mts-cp-auth-card" autocomplete="on">
                    <?php wp_nonce_field( 'mts_cp_auth', 'mts_cp_auth_nonce' ); ?>
                    <input type="hidden" name="mts_cp_auth_action" value="register">
                    <input type="hidden" name="started_at" value="<?php echo esc_attr( time() ); ?>">
                    <label class="mts-cp-honeypot" aria-hidden="true" tabindex="-1"><span>Website</span><input type="text" name="website" value="" autocomplete="off" tabindex="-1"></label>
                    <h2><?php esc_html_e( 'Create Account', 'mts-complete-opinion-polls' ); ?></h2>
                    <div class="mts-cp-two-fields">
                        <label><span><?php esc_html_e( 'First name', 'mts-complete-opinion-polls' ); ?></span><input type="text" name="first_name" autocomplete="given-name" required></label>
                        <label><span><?php esc_html_e( 'Last name', 'mts-complete-opinion-polls' ); ?></span><input type="text" name="last_name" autocomplete="family-name"></label>
                    </div>
                    <label><span><?php esc_html_e( 'Email address', 'mts-complete-opinion-polls' ); ?></span><input type="email" name="email" autocomplete="email" required></label>
                    <label><span><?php esc_html_e( 'Password', 'mts-complete-opinion-polls' ); ?></span><input type="password" name="password" minlength="8" autocomplete="new-password" required><small><?php esc_html_e( 'Minimum 8 characters', 'mts-complete-opinion-polls' ); ?></small></label>
                    <label><span><?php esc_html_e( 'Confirm password', 'mts-complete-opinion-polls' ); ?></span><input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required></label>
                    <label class="mts-cp-consent"><input type="checkbox" name="privacy_consent" value="1" required><span><?php echo esc_html( $settings['privacy_text'] ); ?></span></label>
                    <button type="submit" class="mts-cp-primary-button mts-cp-full-button"><?php esc_html_e( 'Register & Continue to Login', 'mts-complete-opinion-polls' ); ?></button>
                    <p class="mts-cp-auth-switch"><?php esc_html_e( 'Already registered?', 'mts-complete-opinion-polls' ); ?> <a href="<?php echo esc_url( $this->core->page_url( 'login' ) ); ?>"><?php esc_html_e( 'Log in here', 'mts-complete-opinion-polls' ); ?></a></p>
                </form>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function login_shortcode() {
        $this->enqueue_assets();
        $settings = $this->core->settings();

        if ( is_user_logged_in() ) {
            return $this->logged_in_account_card();
        }

        $email = isset( $_GET['email'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['email'] ) ) ) : '';
        ob_start();
        ?>
        <section class="mts-cp-app mts-cp-auth-page" style="<?php echo esc_attr( $this->app_style() ); ?>">
            <?php echo $this->render_notice_from_query(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="mts-cp-auth-shell mts-cp-auth-shell--login">
                <div class="mts-cp-auth-copy">
                    <span class="mts-cp-eyebrow"><?php esc_html_e( 'Step 2 of 2', 'mts-complete-opinion-polls' ); ?></span>
                    <h1><?php echo esc_html( $settings['login_heading'] ); ?></h1>
                    <p><?php esc_html_e( 'Log in and you will be taken directly to the Opinion Polls page.', 'mts-complete-opinion-polls' ); ?></p>
                    <div class="mts-cp-mini-flow"><span><?php esc_html_e( 'Login', 'mts-complete-opinion-polls' ); ?></span><b>→</b><span><?php esc_html_e( 'Select Category', 'mts-complete-opinion-polls' ); ?></span><b>→</b><span><?php esc_html_e( 'Answer / Results', 'mts-complete-opinion-polls' ); ?></span></div>
                </div>
                <form method="post" class="mts-cp-auth-card" autocomplete="on">
                    <?php wp_nonce_field( 'mts_cp_auth', 'mts_cp_auth_nonce' ); ?>
                    <input type="hidden" name="mts_cp_auth_action" value="login">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->core->page_url( 'polls' ) ); ?>">
                    <h2><?php esc_html_e( 'Log In', 'mts-complete-opinion-polls' ); ?></h2>
                    <label><span><?php esc_html_e( 'Email or username', 'mts-complete-opinion-polls' ); ?></span><input type="text" name="identity" value="<?php echo esc_attr( $email ); ?>" autocomplete="username" required></label>
                    <label><span><?php esc_html_e( 'Password', 'mts-complete-opinion-polls' ); ?></span><input type="password" name="password" autocomplete="current-password" required></label>
                    <label class="mts-cp-remember"><input type="checkbox" name="remember" value="1"><span><?php esc_html_e( 'Keep me logged in', 'mts-complete-opinion-polls' ); ?></span></label>
                    <button type="submit" class="mts-cp-primary-button mts-cp-full-button"><?php esc_html_e( 'Log In & Open Opinion Polls', 'mts-complete-opinion-polls' ); ?></button>
                    <div class="mts-cp-auth-links">
                        <a href="<?php echo esc_url( wp_lostpassword_url( $this->core->page_url( 'login' ) ) ); ?>"><?php esc_html_e( 'Forgot password?', 'mts-complete-opinion-polls' ); ?></a>
                        <a href="<?php echo esc_url( $this->core->page_url( 'register' ) ); ?>"><?php esc_html_e( 'Create an account', 'mts-complete-opinion-polls' ); ?></a>
                    </div>
                </form>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function logged_in_account_card() {
        $user = wp_get_current_user();
        ob_start();
        ?>
        <section class="mts-cp-app" style="<?php echo esc_attr( $this->app_style() ); ?>">
            <div class="mts-cp-account-card">
                <h2><?php echo esc_html( sprintf( __( 'You are logged in as %s', 'mts-complete-opinion-polls' ), $user->display_name ) ); ?></h2>
                <div class="mts-cp-account-actions">
                    <a class="mts-cp-primary-button" href="<?php echo esc_url( $this->core->page_url( 'polls' ) ); ?>"><?php esc_html_e( 'Open Opinion Polls', 'mts-complete-opinion-polls' ); ?></a>
                    <a class="mts-cp-secondary-button" href="<?php echo esc_url( wp_logout_url( $this->core->page_url( 'login' ) ) ); ?>"><?php esc_html_e( 'Log Out', 'mts-complete-opinion-polls' ); ?></a>
                </div>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function poll_library_shortcode( $atts ) {
        $this->enqueue_assets();
        $atts = shortcode_atts(
            array(
                'show_intro' => 'yes',
                'open_first' => 'yes',
            ),
            $atts,
            'mts_opinion_polls'
        );

        $settings   = $this->core->settings();
        $categories = $this->core->ordered_categories( false );
        $user       = is_user_logged_in() ? wp_get_current_user() : null;

        ob_start();
        ?>
        <section class="mts-cp-app mts-cp-library" style="<?php echo esc_attr( $this->app_style() ); ?>" data-open-first="<?php echo esc_attr( $atts['open_first'] ); ?>">
            <div class="mts-cp-userbar">
                <?php if ( $user ) : ?>
                    <div><span><?php esc_html_e( 'Signed in as', 'mts-complete-opinion-polls' ); ?></span><strong><?php echo esc_html( $user->display_name ); ?></strong></div>
                    <div>
                        <a href="<?php echo esc_url( $this->core->page_url( 'account' ) ); ?>"><?php esc_html_e( 'My Answers', 'mts-complete-opinion-polls' ); ?></a>
                        <a href="<?php echo esc_url( wp_logout_url( $this->core->page_url( 'login' ) ) ); ?>"><?php esc_html_e( 'Log Out', 'mts-complete-opinion-polls' ); ?></a>
                    </div>
                <?php else : ?>
                    <div><span><?php esc_html_e( 'Opinion Polls', 'mts-complete-opinion-polls' ); ?></span></div>
                    <div>
                        <a href="<?php echo esc_url( $this->core->page_url( 'account' ) ); ?>"><?php esc_html_e( 'My Answers', 'mts-complete-opinion-polls' ); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( 'yes' === $atts['show_intro'] ) : ?>
                <header class="mts-cp-library-intro">
                    <h2><?php echo esc_html( $settings['library_heading'] ); ?></h2>
                    <p><?php echo esc_html( $settings['library_subheading'] ); ?></p>
                </header>
            <?php endif; ?>

            <div class="mts-cp-filter-panel">
                <div class="mts-cp-filter-heading">
                    <h3><?php esc_html_e( 'Poll Categories', 'mts-complete-opinion-polls' ); ?></h3>
                    <p><?php esc_html_e( 'Select a category button to jump directly to its section.', 'mts-complete-opinion-polls' ); ?></p>
                </div>
                <div class="mts-cp-category-grid" role="group" aria-label="<?php esc_attr_e( 'Poll Categories', 'mts-complete-opinion-polls' ); ?>">
                    <?php foreach ( $categories as $category ) : ?>
                        <button type="button" class="mts-cp-category-button" data-category="<?php echo esc_attr( $category->slug ); ?>"><?php echo esc_html( $category->name ); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

 <div class="mts-cp-search-panel">
    <div class="mts-cp-keyword-control">
        <label class="screen-reader-text" for="mts-cp-keyword-<?php echo esc_attr( wp_unique_id() ); ?>"><?php esc_html_e( 'Search by keyword', 'mts-complete-opinion-polls' ); ?></label>
        <span class="mts-cp-search-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </span>
        <input class="mts-cp-keyword-input" type="search" placeholder="<?php esc_attr_e( 'Search by Keyword', 'mts-complete-opinion-polls' ); ?>">
    </div>

    <div class="mts-cp-sort-wrapper">
        <label for="mts-cp-sort-select" class="mts-cp-sort-label"><?php esc_html_e( 'Sort by', 'mts-complete-opinion-polls' ); ?></label>
        <div class="mts-cp-select-container">
            <select id="mts-cp-sort-select" class="mts-cp-sort-select">
                <option value="recent"><?php esc_html_e( 'Most Recent', 'mts-complete-opinion-polls' ); ?></option>
                <option value="popular"><?php esc_html_e( 'Most Popular', 'mts-complete-opinion-polls' ); ?></option>
            </select>
        </div>
    </div>
</div>

			<div class="mts-cp-elementor-template-wrapper">
    <?php echo do_shortcode('[elementor-template id="5358"]'); ?>
</div>
			
<div class="mts-cp-active-filter" aria-live="polite"></div>
            <div class="mts-cp-poll-sections" aria-live="polite">
                <?php echo $this->render_poll_sections(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <?php echo $this->modal_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function logged_out_gate() {
        ob_start();
        ?>
        <section class="mts-cp-app" style="<?php echo esc_attr( $this->app_style() ); ?>">
            <div class="mts-cp-gate">
                <span class="mts-cp-eyebrow"><?php esc_html_e( 'Opinion Polls', 'mts-complete-opinion-polls' ); ?></span>
                <h2><?php esc_html_e( 'Register or log in to participate', 'mts-complete-opinion-polls' ); ?></h2>
                <p><?php esc_html_e( 'Your account prevents duplicate voting and gives you access to categories, keyword search, answer forms, and live result charts.', 'mts-complete-opinion-polls' ); ?></p>
                <div class="mts-cp-gate-actions">
                    <a class="mts-cp-primary-button" href="<?php echo esc_url( $this->core->page_url( 'register' ) ); ?>"><?php esc_html_e( 'Register', 'mts-complete-opinion-polls' ); ?></a>
                    <a class="mts-cp-secondary-button" href="<?php echo esc_url( $this->core->page_url( 'login' ) ); ?>"><?php esc_html_e( 'Log In', 'mts-complete-opinion-polls' ); ?></a>
                </div>
                <div class="mts-cp-gate-flow"><span><?php esc_html_e( 'Register', 'mts-complete-opinion-polls' ); ?></span><b>→</b><span><?php esc_html_e( 'Login', 'mts-complete-opinion-polls' ); ?></span><b>→</b><span><?php esc_html_e( 'Poll Categories & Search', 'mts-complete-opinion-polls' ); ?></span><b>→</b><span><?php esc_html_e( 'Answer & Results', 'mts-complete-opinion-polls' ); ?></span></div>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function query_polls( $category = '', $keyword = '', $sort = 'default' ) {
        $args = array(
            'post_type'      => $this->core->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'no_found_rows'  => true,
        );

        if ( $category ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => $this->core->taxonomy,
                    'field'    => 'slug',
                    'terms'    => $category,
                ),
            );
        }

        if ( $keyword ) {
            $args['s'] = $keyword;
        }

        if ( 'popular' === $sort ) {
            $args['meta_key'] = '_mts_cp_vote_count';
            $args['orderby']  = array(
                'meta_value_num' => 'DESC',
                'date'           => 'DESC',
            );
        } elseif ( 'recent' === $sort ) {
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        } else {
            $args['orderby'] = array(
                'menu_order' => 'ASC',
                'date'       => 'DESC',
            );
        }

        return new WP_Query( $args );
    }

    private function render_poll_sections( $category = '', $keyword = '', $sort = 'default' ) {
        $query    = $this->query_polls( $category, $keyword, $sort );
        $settings = $this->core->settings();

        if ( ! $query->have_posts() ) {
            return '<div class="mts-cp-empty"><h3>' . esc_html__( 'No matching polls found', 'mts-complete-opinion-polls' ) . '</h3><p>' . esc_html( $settings['empty_text'] ) . '</p></div>';
        }

        $grouped = array();
        while ( $query->have_posts() ) {
            $query->the_post();
            $poll_id = get_the_ID();
            $terms   = wp_get_post_terms( $poll_id, $this->core->taxonomy );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                $grouped['uncategorized']['term']    = null;
                $grouped['uncategorized']['polls'][] = $poll_id;
                continue;
            }

            foreach ( $terms as $term ) {
                $grouped[ $term->slug ]['term']    = $term;
                $grouped[ $term->slug ]['polls'][] = $poll_id;
            }
        }
        wp_reset_postdata();

        $ordered_keys = array();
        foreach ( $this->core->ordered_categories( false ) as $term ) {
            $ordered_keys[] = $term->slug;
        }
        if ( isset( $grouped['uncategorized'] ) ) {
            $ordered_keys[] = 'uncategorized';
        }

        ob_start();
        $section_index = 0;
        foreach ( $ordered_keys as $key ) {
            if ( empty( $grouped[ $key ]['polls'] ) ) {
                continue;
            }
            $term    = $grouped[ $key ]['term'];
            $name    = $term ? $term->name : __( 'Other Polls', 'mts-complete-opinion-polls' );
            $is_open = 0 === $section_index;
            ?>
            <section class="mts-cp-category-section <?php echo $is_open ? 'is-open' : ''; ?>" data-category-slug="<?php echo esc_attr( $key ); ?>" id="mts-cp-cat-<?php echo esc_attr( $key ); ?>">
                <button type="button" class="mts-cp-accordion-button" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
                    <span><?php echo esc_html( $name ); ?></span>
                    <span class="mts-cp-accordion-symbol" aria-hidden="true"><?php echo $is_open ? '−' : '+'; ?></span>
                </button>
                <div class="mts-cp-category-content" <?php echo $is_open ? '' : 'hidden'; ?>>
                    <?php foreach ( array_unique( $grouped[ $key ]['polls'] ) as $poll_id ) : ?>
                        <?php echo $this->render_poll_row( absint( $poll_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php
            ++$section_index;
        }
        return (string) ob_get_clean();
    }

    private function render_poll_row( $poll_id ) {
        $vote_count = $this->core->get_vote_count( $poll_id );
        $has_voted  = $this->core->current_user_has_voted( $poll_id );
        $open       = $this->core->poll_is_open( $poll_id );
        $excerpt    = get_the_excerpt( $poll_id );

        ob_start();
        ?>
        <article class="mts-cp-poll-row" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
            <div class="mts-cp-poll-copy">
                <h3><?php echo esc_html( get_the_title( $poll_id ) ); ?></h3>
                <?php if ( $excerpt ) : ?><p><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
                <div class="mts-cp-poll-meta">
                    <span class="mts-cp-vote-count"><?php echo esc_html( sprintf( _n( '%s vote', '%s votes', $vote_count, 'mts-complete-opinion-polls' ), number_format_i18n( $vote_count ) ) ); ?></span>
                    <?php if ( $has_voted ) : ?><span class="mts-cp-answered-badge">✓ <?php esc_html_e( 'Answered', 'mts-complete-opinion-polls' ); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="mts-cp-poll-actions">
                <button type="button" class="mts-cp-primary-button mts-cp-answer-button" data-poll-id="<?php echo esc_attr( $poll_id ); ?>" <?php disabled( ! $open ); ?>><?php echo $open ? esc_html__( 'Answer', 'mts-complete-opinion-polls' ) : esc_html__( 'Closed', 'mts-complete-opinion-polls' ); ?></button>
                <button type="button" class="mts-cp-secondary-button mts-cp-results-button" data-poll-id="<?php echo esc_attr( $poll_id ); ?>"><?php esc_html_e( 'View Results', 'mts-complete-opinion-polls' ); ?></button>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private function modal_markup() {
        return '<div class="mts-cp-modal" hidden><div class="mts-cp-modal-backdrop" data-mts-cp-close></div><div class="mts-cp-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mts-cp-modal-title"><button type="button" class="mts-cp-modal-close" data-mts-cp-close aria-label="' . esc_attr__( 'Close', 'mts-complete-opinion-polls' ) . '">×</button><div class="mts-cp-modal-content"></div></div></div>';
    }

    public function ajax_login_required() {
        wp_send_json_error(
            array(
                'message'  => __( 'Please log in to continue.', 'mts-complete-opinion-polls' ),
                'loginUrl' => $this->core->page_url( 'login' ),
            ),
            401
        );
    }

    public function ajax_filter_polls() {
        check_ajax_referer( 'mts_cp_frontend', 'nonce' );

        $category = isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : '';
        $keyword  = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $sort     = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'default';
        if ( ! in_array( $sort, array( 'default', 'popular', 'recent' ), true ) ) {
            $sort = 'default';
        }

        wp_send_json_success(
            array(
                'html' => $this->render_poll_sections( $category, $keyword, $sort ),
            )
        );
    }

    public function ajax_get_answer_form() {
        check_ajax_referer( 'mts_cp_frontend', 'nonce' );

        $poll_id = isset( $_POST['poll_id'] ) ? absint( $_POST['poll_id'] ) : 0;
        if ( ! $this->valid_poll( $poll_id ) ) {
            wp_send_json_error( array( 'message' => __( 'This poll could not be found.', 'mts-complete-opinion-polls' ) ), 404 );
        }

        wp_send_json_success( array( 'html' => $this->render_answer_form( $poll_id ) ) );
    }

    private function render_answer_form( $poll_id ) {
        $options       = $this->core->get_poll_options( $poll_id );
        $type          = $this->core->get_poll_type( $poll_id );
        $randomize     = $this->core->flag( $poll_id, '_mts_cp_randomize', '1' );
        $allow_other   = $this->core->flag( $poll_id, '_mts_cp_allow_other', '1' );
        $allow_context = $this->core->flag( $poll_id, '_mts_cp_allow_context', '1' );
        $allow_change  = $this->core->flag( $poll_id, '_mts_cp_allow_change', $this->core->settings()['allow_answer_change'] );
        $existing      = $this->core->get_user_vote( $poll_id );

        if ( $randomize && count( $options ) > 1 && in_array( $type, array( 'single', 'multiple', 'rank', 'maxdiff' ), true ) ) {
            shuffle( $options );
        }

        if ( ! $this->core->poll_is_open( $poll_id ) ) {
            return '<div class="mts-cp-modal-panel"><h2 id="mts-cp-modal-title">' . esc_html( get_the_title( $poll_id ) ) . '</h2><div class="mts-cp-notice mts-cp-notice--info">' . esc_html__( 'This poll is currently closed.', 'mts-complete-opinion-polls' ) . '</div></div>';
        }

        if ( $existing && ! $allow_change ) {
            ob_start();
            ?>
            <div class="mts-cp-modal-panel">
                <span class="mts-cp-modal-kicker"><?php esc_html_e( 'Already answered', 'mts-complete-opinion-polls' ); ?></span>
                <h2 id="mts-cp-modal-title"><?php echo esc_html( get_the_title( $poll_id ) ); ?></h2>
                <div class="mts-cp-thank-you">
                    <div class="mts-cp-checkmark">✓</div>
                    <h3><?php esc_html_e( 'Thank you for participating.', 'mts-complete-opinion-polls' ); ?></h3>
                    <p><?php esc_html_e( 'Each registered user can submit one answer to this poll.', 'mts-complete-opinion-polls' ); ?></p>
                    <button type="button" class="mts-cp-primary-button mts-cp-results-button" data-poll-id="<?php echo esc_attr( $poll_id ); ?>"><?php esc_html_e( 'View Live Results', 'mts-complete-opinion-polls' ); ?></button>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        $existing_answers = array();
        $existing_gender  = 'skip';
        $existing_other   = '';
        $existing_context = '';
        if ( $existing ) {
            $decoded          = json_decode( (string) $existing->answer_json, true );
            $existing_answers = is_array( $decoded ) ? $decoded : array();
            $existing_gender  = in_array( $existing->gender, array( 'male', 'female' ), true ) ? $existing->gender : 'skip';
            $existing_other   = (string) $existing->other_text;
            $existing_context = (string) $existing->context_text;
        }

        ob_start();
        ?>
        <div class="mts-cp-modal-panel">
            <span class="mts-cp-modal-kicker"><?php echo $existing ? esc_html__( 'Update your answer', 'mts-complete-opinion-polls' ) : esc_html__( 'Answer this poll', 'mts-complete-opinion-polls' ); ?></span>
            <h2 id="mts-cp-modal-title"><?php echo esc_html( get_the_title( $poll_id ) ); ?></h2>
            <?php if ( get_post_field( 'post_content', $poll_id ) ) : ?>
                <div class="mts-cp-poll-description"><?php echo wp_kses_post( apply_filters( 'the_content', get_post_field( 'post_content', $poll_id ) ) ); ?></div>
            <?php endif; ?>

            <form class="mts-cp-vote-form" data-poll-id="<?php echo esc_attr( $poll_id ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
                <input type="hidden" name="poll_id" value="<?php echo esc_attr( $poll_id ); ?>">

                <section class="mts-cp-answer-step">
                    <div class="mts-cp-step-number">1</div>
                    <div class="mts-cp-step-content">
                        <h3><?php esc_html_e( 'Gender', 'mts-complete-opinion-polls' ); ?> <span><?php esc_html_e( '(optional)', 'mts-complete-opinion-polls' ); ?></span></h3>
                        <p><?php esc_html_e( 'Choose Male, Female, or Skip.', 'mts-complete-opinion-polls' ); ?></p>
                        <div class="mts-cp-gender-options">
                            <label><input type="radio" name="gender" value="male" <?php checked( $existing_gender, 'male' ); ?>><span><?php esc_html_e( 'Male', 'mts-complete-opinion-polls' ); ?></span></label>
                            <label><input type="radio" name="gender" value="female" <?php checked( $existing_gender, 'female' ); ?>><span><?php esc_html_e( 'Female', 'mts-complete-opinion-polls' ); ?></span></label>
                            <label><input type="radio" name="gender" value="skip" <?php checked( $existing_gender, 'skip' ); ?>><span><?php esc_html_e( 'Skip', 'mts-complete-opinion-polls' ); ?></span></label>
                        </div>
                    </div>
                </section>

                <section class="mts-cp-answer-step">
                    <div class="mts-cp-step-number">2</div>
                    <div class="mts-cp-step-content">
                        <h3><?php esc_html_e( 'Answer the Question', 'mts-complete-opinion-polls' ); ?></h3>
                        <?php if ( $randomize && in_array( $type, array( 'single', 'multiple', 'rank', 'maxdiff' ), true ) ) : ?>
                            <p class="mts-cp-random-note">↻ <?php esc_html_e( 'Answer choices are shown in a random order to reduce first-listed bias.', 'mts-complete-opinion-polls' ); ?></p>
                        <?php endif; ?>

                        <?php if ( 'rank' === $type ) : ?>
                            <div class="mts-cp-rank-container">
                                <p class="mts-cp-subnote"><?php esc_html_e( 'Assign rank numbers (1 = top choice) to order your preferences:', 'mts-complete-opinion-polls' ); ?></p>
                                <div class="mts-cp-rank-choices">
                                    <?php foreach ( $options as $idx => $option ) :
                                        $cur_rank = isset( $existing_answers[ $option ] ) ? absint( $existing_answers[ $option ] ) : ( $idx + 1 );
                                        ?>
                                        <div class="mts-cp-rank-item">
                                            <span class="mts-cp-rank-badge">#<span class="mts-cp-rank-num"><?php echo esc_html( $cur_rank ); ?></span></span>
                                            <span class="mts-cp-rank-label"><?php echo esc_html( $option ); ?></span>
                                            <select name="rank[<?php echo esc_attr( $option ); ?>]" class="mts-cp-rank-select" data-option="<?php echo esc_attr( $option ); ?>">
                                                <?php for ( $i = 1; $i <= count( $options ); $i++ ) : ?>
                                                    <option value="<?php echo $i; ?>" <?php selected( $cur_rank, $i ); ?>><?php echo $i; ?><?php echo 1 === $i ? 'st' : ( 2 === $i ? 'nd' : ( 3 === $i ? 'rd' : 'th' ) ); ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif ( 'maxdiff' === $type ) : ?>
                            <?php $maxdiff_lbls = $this->core->get_maxdiff_labels( $poll_id ); ?>
                            <div class="mts-cp-maxdiff-container">
                                <p class="mts-cp-subnote"><?php esc_html_e( 'Select ONE option as Most Important and ONE option as Least Important:', 'mts-complete-opinion-polls' ); ?></p>
                                <table class="mts-cp-maxdiff-table">
                                    <thead>
                                        <tr>
                                            <th class="mts-cp-col-most"><?php echo esc_html( $maxdiff_lbls['high'] ); ?></th>
                                            <th class="mts-cp-col-option"><?php esc_html_e( 'Options', 'mts-complete-opinion-polls' ); ?></th>
                                            <th class="mts-cp-col-least"><?php echo esc_html( $maxdiff_lbls['low'] ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $options as $option ) :
                                            $is_most  = isset( $existing_answers['most'] ) && $existing_answers['most'] === $option;
                                            $is_least = isset( $existing_answers['least'] ) && $existing_answers['least'] === $option;
                                            ?>
                                            <tr>
                                                <td class="mts-cp-col-most">
                                                    <label class="mts-cp-maxdiff-radio">
                                                        <input type="radio" name="maxdiff_most" value="<?php echo esc_attr( $option ); ?>" <?php checked( $is_most ); ?>>
                                                        <span class="mts-cp-radio-dot"></span>
                                                    </label>
                                                </td>
                                                <td class="mts-cp-col-option"><?php echo esc_html( $option ); ?></td>
                                                <td class="mts-cp-col-least">
                                                    <label class="mts-cp-maxdiff-radio">
                                                        <input type="radio" name="maxdiff_least" value="<?php echo esc_attr( $option ); ?>" <?php checked( $is_least ); ?>>
                                                        <span class="mts-cp-radio-dot"></span>
                                                    </label>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ( 'matrix' === $type ) : ?>
                            <?php
                            $matrix_rows = $this->core->get_matrix_rows( $poll_id );
                            $matrix_cols = $options;
                            ?>
                            <div class="mts-cp-matrix-container">
                                <p class="mts-cp-subnote"><?php esc_html_e( 'Evaluate each statement on the scale below:', 'mts-complete-opinion-polls' ); ?></p>
                                <table class="mts-cp-matrix-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Statements', 'mts-complete-opinion-polls' ); ?></th>
                                            <?php foreach ( $matrix_cols as $col ) : ?>
                                                <th><?php echo esc_html( $col ); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $matrix_rows as $row_idx => $stmt ) :
                                            $selected_val = isset( $existing_answers[ $stmt ] ) ? $existing_answers[ $stmt ] : '';
                                            ?>
                                            <tr>
                                                <td class="mts-cp-matrix-stmt"><?php echo esc_html( $stmt ); ?></td>
                                                <?php foreach ( $matrix_cols as $col ) : ?>
                                                    <td class="mts-cp-matrix-cell">
                                                        <label title="<?php echo esc_attr( $col ); ?>">
                                                            <input type="radio" name="matrix[<?php echo esc_attr( $stmt ); ?>]" value="<?php echo esc_attr( $col ); ?>" <?php checked( $selected_val, $col ); ?>>
                                                            <span class="mts-cp-radio-dot"></span>
                                                            <span class="mts-cp-matrix-mobile-col-lbl"><?php echo esc_html( $col ); ?></span>
                                                        </label>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ( 'slider' === $type ) : ?>
                            <?php
                            $slider_cfg = $this->core->get_slider_config( $poll_id );
                            $slider_val = is_numeric( $existing_answers ) ? floatval( $existing_answers ) : ( is_array( $existing_answers ) && isset( $existing_answers['value'] ) ? floatval( $existing_answers['value'] ) : round( ( $slider_cfg['min'] + $slider_cfg['max'] ) / 2 ) );
                            ?>
                            <div class="mts-cp-slider-container">
                                <div class="mts-cp-slider-badge">
                                    <span class="mts-cp-slider-val-num"><?php echo esc_html( $slider_val ); ?></span>
                                    <span class="mts-cp-slider-max-lbl">/ <?php echo esc_html( $slider_cfg['max'] ); ?></span>
                                </div>
                                <input type="range" class="mts-cp-slider-input" name="slider_value" min="<?php echo esc_attr( $slider_cfg['min'] ); ?>" max="<?php echo esc_attr( $slider_cfg['max'] ); ?>" step="<?php echo esc_attr( $slider_cfg['step'] ); ?>" value="<?php echo esc_attr( $slider_val ); ?>">
                                <div class="mts-cp-slider-labels">
                                    <span><?php echo esc_html( $slider_cfg['min_label'] ); ?> (<?php echo esc_html( $slider_cfg['min'] ); ?>)</span>
                                    <span><?php echo esc_html( $slider_cfg['max_label'] ); ?> (<?php echo esc_html( $slider_cfg['max'] ); ?>)</span>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="mts-cp-answer-choices">
                                <?php foreach ( $options as $option ) :
                                    $field_type = 'multiple' === $type ? 'checkbox' : 'radio';
                                    $field_name = 'multiple' === $type ? 'answer[]' : 'answer';
                                    ?>
                                    <label class="mts-cp-answer-choice">
                                        <input type="<?php echo esc_attr( $field_type ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $option ); ?>" <?php checked( in_array( $option, (array) $existing_answers, true ) ); ?>>
                                        <span><?php echo esc_html( $option ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if ( $allow_other ) : ?>
                                    <label class="mts-cp-answer-choice mts-cp-other-choice">
                                        <input type="<?php echo 'multiple' === $type ? 'checkbox' : 'radio'; ?>" name="<?php echo 'multiple' === $type ? 'answer[]' : 'answer'; ?>" value="__other__" <?php checked( in_array( '__other__', (array) $existing_answers, true ) ); ?>>
                                        <span><?php esc_html_e( 'Type your answer here', 'mts-complete-opinion-polls' ); ?></span>
                                    </label>
                                    <div class="mts-cp-other-field" <?php echo in_array( '__other__', (array) $existing_answers, true ) ? '' : 'hidden'; ?>>
                                        <label><span><?php esc_html_e( 'Type your answer', 'mts-complete-opinion-polls' ); ?></span><input type="text" name="other_text" maxlength="300" value="<?php echo esc_attr( $existing_other ); ?>" placeholder="<?php esc_attr_e( 'Enter your answer here', 'mts-complete-opinion-polls' ); ?>"></label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ( $allow_context ) : ?>
                    <section class="mts-cp-answer-step mts-cp-context-step">
                        <div class="mts-cp-step-number">3</div>
                        <div class="mts-cp-step-content">
                            <button type="button" class="mts-cp-context-toggle" aria-expanded="<?php echo $existing_context ? 'true' : 'false'; ?>">
                                <span>＋</span><?php esc_html_e( 'Add Context', 'mts-complete-opinion-polls' ); ?> <small><?php esc_html_e( '(optional)', 'mts-complete-opinion-polls' ); ?></small>
                            </button>
                            <div class="mts-cp-context-field" <?php echo $existing_context ? '' : 'hidden'; ?>>
                                <label class="screen-reader-text" for="mts-cp-context-<?php echo esc_attr( $poll_id ); ?>"><?php esc_html_e( 'Additional context', 'mts-complete-opinion-polls' ); ?></label>
                                <textarea id="mts-cp-context-<?php echo esc_attr( $poll_id ); ?>" name="context_text" rows="4" maxlength="2000" placeholder="<?php esc_attr_e( 'If you have important context to add, share it here.', 'mts-complete-opinion-polls' ); ?>"><?php echo esc_textarea( $existing_context ); ?></textarea>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="mts-cp-form-message" aria-live="polite"></div>
                <div class="mts-cp-submit-row">
                    <button type="submit" class="mts-cp-primary-button mts-cp-submit-vote"><?php echo $existing ? esc_html__( 'Update Answer', 'mts-complete-opinion-polls' ) : esc_html__( 'Submit Answer', 'mts-complete-opinion-polls' ); ?></button>
                    <span class="mts-cp-submit-note"><?php esc_html_e( 'Results become available immediately—there is no minimum vote requirement.', 'mts-complete-opinion-polls' ); ?></span>
                </div>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function ajax_submit_vote() {
        check_ajax_referer( 'mts_cp_frontend', 'nonce' );

        $poll_id = isset( $_POST['poll_id'] ) ? absint( $_POST['poll_id'] ) : 0;
        if ( ! $this->valid_poll( $poll_id ) ) {
            wp_send_json_error( array( 'message' => __( 'This poll could not be found.', 'mts-complete-opinion-polls' ) ), 404 );
        }
        if ( ! $this->core->poll_is_open( $poll_id ) ) {
            wp_send_json_error( array( 'message' => __( 'This poll is closed.', 'mts-complete-opinion-polls' ) ), 400 );
        }

        $existing     = $this->core->get_user_vote( $poll_id );
        $allow_change = $this->core->flag( $poll_id, '_mts_cp_allow_change', $this->core->settings()['allow_answer_change'] );
        if ( $existing && ! $allow_change ) {
            wp_send_json_error( array( 'message' => __( 'You have already answered this poll.', 'mts-complete-opinion-polls' ) ), 409 );
        }

        $type          = $this->core->get_poll_type( $poll_id );
        $options       = $this->core->get_poll_options( $poll_id );
        $allow_other   = $this->core->flag( $poll_id, '_mts_cp_allow_other', '1' );
        $allow_context = $this->core->flag( $poll_id, '_mts_cp_allow_context', '1' );

        $clean_answers = array();

        if ( 'rank' === $type ) {
            $raw_rank   = isset( $_POST['rank'] ) ? wp_unslash( $_POST['rank'] ) : array();
            $clean_rank = array();
            if ( is_array( $raw_rank ) ) {
                foreach ( $raw_rank as $opt => $val ) {
                    $opt = sanitize_text_field( (string) $opt );
                    if ( in_array( $opt, $options, true ) ) {
                        $clean_rank[ $opt ] = absint( $val );
                    }
                }
            }
            if ( count( $clean_rank ) < count( $options ) ) {
                wp_send_json_error( array( 'message' => __( 'Please rank all available options.', 'mts-complete-opinion-polls' ) ), 400 );
            }
            $clean_answers = $clean_rank;
        } elseif ( 'maxdiff' === $type ) {
            $most  = isset( $_POST['maxdiff_most'] ) ? sanitize_text_field( wp_unslash( $_POST['maxdiff_most'] ) ) : '';
            $least = isset( $_POST['maxdiff_least'] ) ? sanitize_text_field( wp_unslash( $_POST['maxdiff_least'] ) ) : '';

            if ( ! in_array( $most, $options, true ) || ! in_array( $least, $options, true ) ) {
                wp_send_json_error( array( 'message' => __( 'Please select both Most Important and Least Important options.', 'mts-complete-opinion-polls' ) ), 400 );
            }
            if ( $most === $least ) {
                wp_send_json_error( array( 'message' => __( 'Most Important and Least Important cannot be the same option.', 'mts-complete-opinion-polls' ) ), 400 );
            }
            $clean_answers = array(
                'most'  => $most,
                'least' => $least,
            );
        } elseif ( 'matrix' === $type ) {
            $raw_matrix   = isset( $_POST['matrix'] ) ? wp_unslash( $_POST['matrix'] ) : array();
            $matrix_rows  = $this->core->get_matrix_rows( $poll_id );
            $clean_matrix = array();

            if ( is_array( $raw_matrix ) ) {
                foreach ( $matrix_rows as $stmt ) {
                    if ( isset( $raw_matrix[ $stmt ] ) && in_array( sanitize_text_field( $raw_matrix[ $stmt ] ), $options, true ) ) {
                        $clean_matrix[ $stmt ] = sanitize_text_field( $raw_matrix[ $stmt ] );
                    }
                }
            }
            if ( count( $clean_matrix ) < count( $matrix_rows ) ) {
                wp_send_json_error( array( 'message' => __( 'Please answer all matrix statements.', 'mts-complete-opinion-polls' ) ), 400 );
            }
            $clean_answers = $clean_matrix;
        } elseif ( 'slider' === $type ) {
            $slider_cfg = $this->core->get_slider_config( $poll_id );
            $val        = isset( $_POST['slider_value'] ) ? floatval( $_POST['slider_value'] ) : $slider_cfg['min'];

            if ( $val < $slider_cfg['min'] || $val > $slider_cfg['max'] ) {
                wp_send_json_error( array( 'message' => __( 'Please enter a valid slider value.', 'mts-complete-opinion-polls' ) ), 400 );
            }
            $clean_answers = array( 'value' => $val );
        } else {
            $raw_answer = isset( $_POST['answer'] ) ? wp_unslash( $_POST['answer'] ) : array();
            if ( 'multiple' === $type ) {
                $answers = is_array( $raw_answer ) ? $raw_answer : array( $raw_answer );
            } else {
                $answers = is_array( $raw_answer ) ? array_slice( $raw_answer, 0, 1 ) : array( $raw_answer );
            }

            foreach ( $answers as $answer ) {
                $answer = sanitize_text_field( (string) $answer );
                if ( in_array( $answer, $options, true ) || ( '__other__' === $answer && $allow_other ) ) {
                    if ( ! in_array( $answer, $clean_answers, true ) ) {
                        $clean_answers[] = $answer;
                    }
                }
            }

            if ( empty( $clean_answers ) ) {
                wp_send_json_error( array( 'message' => __( 'Please select an answer.', 'mts-complete-opinion-polls' ) ), 400 );
            }
        }

        $other_text = isset( $_POST['other_text'] ) ? sanitize_text_field( wp_unslash( $_POST['other_text'] ) ) : '';
        if ( is_array( $clean_answers ) && in_array( '__other__', $clean_answers, true ) && '' === $other_text ) {
            wp_send_json_error( array( 'message' => __( 'Please type your “Other” answer.', 'mts-complete-opinion-polls' ) ), 400 );
        }
        if ( ! is_array( $clean_answers ) || ! in_array( '__other__', $clean_answers, true ) ) {
            $other_text = '';
        }

        $gender = isset( $_POST['gender'] ) ? sanitize_key( wp_unslash( $_POST['gender'] ) ) : 'skip';
        if ( ! in_array( $gender, array( 'male', 'female', 'skip' ), true ) ) {
            $gender = 'skip';
        }

        $context_text = $allow_context && isset( $_POST['context_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_text'] ) ) : '';
        if ( function_exists( 'mb_substr' ) ) {
            $other_text   = mb_substr( $other_text, 0, 300 );
            $context_text = mb_substr( $context_text, 0, 2000 );
        } else {
            $other_text   = substr( $other_text, 0, 300 );
            $context_text = substr( $context_text, 0, 2000 );
        }

        global $wpdb;
        $now       = current_time( 'mysql' );
        $voter_key = $this->core->get_voter_key();
        $user_id   = get_current_user_id();

        $data = array(
            'poll_id'      => $poll_id,
            'user_id'      => $user_id,
            'voter_key'    => $voter_key,
            'gender'       => 'skip' === $gender ? '' : $gender,
            'answer_json'  => wp_json_encode( $clean_answers, JSON_UNESCAPED_UNICODE ),
            'other_text'   => $other_text,
            'context_text' => $context_text,
            'updated_at'   => $existing ? $now : null,
        );

        if ( $existing ) {
            $success = $wpdb->update(
                $this->core->table,
                $data,
                array( 'id' => absint( $existing->id ) ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $data['created_at'] = $now;
            $success = $wpdb->insert(
                $this->core->table,
                $data,
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
        }

        if ( false === $success ) {
            wp_send_json_error( array( 'message' => __( 'Your answer could not be saved. Please try again.', 'mts-complete-opinion-polls' ) ), 500 );
        }

        $count = $this->core->refresh_vote_count( $poll_id );
        wp_send_json_success(
            array(
                'html'      => $this->render_results( $poll_id, true ),
                'voteCount' => $count,
                'message'   => $existing ? __( 'Your answer was updated.', 'mts-complete-opinion-polls' ) : __( 'Thank you. Your answer was submitted.', 'mts-complete-opinion-polls' ),
            )
        );
    }

    public function ajax_get_results() {
        check_ajax_referer( 'mts_cp_frontend', 'nonce' );

        $poll_id = isset( $_POST['poll_id'] ) ? absint( $_POST['poll_id'] ) : 0;
        if ( ! $this->valid_poll( $poll_id ) ) {
            wp_send_json_error( array( 'message' => __( 'This poll could not be found.', 'mts-complete-opinion-polls' ) ), 404 );
        }

        wp_send_json_success( array( 'html' => $this->render_results( $poll_id ) ) );
    }

    private function render_results( $poll_id, $just_voted = false ) {
        $results     = $this->core->get_results( $poll_id );
        $total_votes = absint( $results['total_votes'] );
        $type        = isset( $results['type'] ) ? $results['type'] : 'single';

        ob_start();
        ?>
        <div class="mts-cp-modal-panel mts-cp-results-panel">
            <?php if ( $just_voted ) : ?>
                <div class="mts-cp-results-success"><span>✓</span><strong><?php esc_html_e( 'Answer submitted successfully', 'mts-complete-opinion-polls' ); ?></strong></div>
            <?php endif; ?>
            <span class="mts-cp-modal-kicker"><?php esc_html_e( 'Live Poll Results', 'mts-complete-opinion-polls' ); ?></span>
            <h2 id="mts-cp-modal-title"><?php echo esc_html( get_the_title( $poll_id ) ); ?></h2>
            <div class="mts-cp-total-votes">
                <strong><?php echo esc_html( number_format_i18n( $total_votes ) ); ?></strong>
                <span><?php echo esc_html( _n( 'total vote', 'total votes', $total_votes, 'mts-complete-opinion-polls' ) ); ?></span>
            </div>

            <?php if ( 0 === $total_votes ) : ?>
                <div class="mts-cp-no-results">
                    <h3><?php esc_html_e( 'No votes yet', 'mts-complete-opinion-polls' ); ?></h3>
                    <p><?php esc_html_e( 'Results are available immediately, so the first submitted vote will appear here.', 'mts-complete-opinion-polls' ); ?></p>
                    <?php if ( $this->core->poll_is_open( $poll_id ) ) : ?><button type="button" class="mts-cp-primary-button mts-cp-answer-button" data-poll-id="<?php echo esc_attr( $poll_id ); ?>"><?php esc_html_e( 'Be the First to Answer', 'mts-complete-opinion-polls' ); ?></button><?php endif; ?>
                </div>
            <?php else : ?>
                <div class="mts-cp-chart-heading">
                    <strong><?php esc_html_e( 'Answer Breakdown', 'mts-complete-opinion-polls' ); ?></strong>
                    <span><?php esc_html_e( 'Live statistics based on submitted responses.', 'mts-complete-opinion-polls' ); ?></span>
                </div>

                <?php if ( 'rank' === $type && ! empty( $results['rank_data'] ) ) : ?>
                    <div class="mts-cp-results-chart mts-cp-results-rank">
                        <?php foreach ( $results['rank_data'] as $label => $info ) :
                            $visual_width = max( 8, 100 - ( ( $info['avg_rank'] - 1 ) * 25 ) );
                            ?>
                            <div class="mts-cp-result-item">
                                <div class="mts-cp-result-topline">
                                    <span class="mts-cp-result-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="mts-cp-result-stat">Avg Rank: <strong><?php echo esc_html( $info['avg_rank'] ); ?></strong> · Ranked #1 by <?php echo esc_html( $info['first_place_pct'] ); ?>%</span>
                                </div>
                                <div class="mts-cp-result-track"><span class="mts-cp-result-fill" style="width:<?php echo esc_attr( min( 100, $visual_width ) ); ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ( 'maxdiff' === $type && ! empty( $results['maxdiff_data'] ) ) : ?>
                    <div class="mts-cp-results-chart mts-cp-results-maxdiff">
                        <?php foreach ( $results['maxdiff_data'] as $label => $info ) : ?>
                            <div class="mts-cp-result-item">
                                <div class="mts-cp-result-topline">
                                    <span class="mts-cp-result-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="mts-cp-result-stat">Net Preference: <strong><?php echo esc_html( $info['net_score'] > 0 ? '+' . $info['net_score'] : $info['net_score'] ); ?>%</strong></span>
                                </div>
                                <div class="mts-cp-maxdiff-bar-split">
                                    <span class="mts-cp-bar-most" style="width:<?php echo esc_attr( $info['most_pct'] ); ?>%"><?php echo esc_html( $results['labels']['high'] ); ?> (<?php echo esc_html( $info['most_pct'] ); ?>%)</span>
                                    <span class="mts-cp-bar-least" style="width:<?php echo esc_attr( $info['least_pct'] ); ?>%"><?php echo esc_html( $results['labels']['low'] ); ?> (<?php echo esc_html( $info['least_pct'] ); ?>%)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ( 'matrix' === $type && ! empty( $results['matrix_counts'] ) ) : ?>
                    <div class="mts-cp-results-matrix">
                        <?php foreach ( $results['matrix_counts'] as $stmt => $cols ) : ?>
                            <div class="mts-cp-matrix-result-card">
                                <h4><?php echo esc_html( $stmt ); ?></h4>
                                <div class="mts-cp-matrix-result-bars">
                                    <?php foreach ( $cols as $col_name => $col_cnt ) :
                                        $col_pct = $total_votes > 0 ? round( ( $col_cnt / $total_votes ) * 100, 1 ) : 0;
                                        ?>
                                        <div class="mts-cp-result-item">
                                            <div class="mts-cp-result-topline">
                                                <span class="mts-cp-result-label"><?php echo esc_html( $col_name ); ?></span>
                                                <span class="mts-cp-result-stat"><strong><?php echo esc_html( $col_pct ); ?>%</strong> (<?php echo esc_html( $col_cnt ); ?>)</span>
                                            </div>
                                            <div class="mts-cp-result-track"><span class="mts-cp-result-fill" style="width:<?php echo esc_attr( $col_pct ); ?>%"></span></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ( 'slider' === $type ) : ?>
                    <div class="mts-cp-results-slider">
                        <div class="mts-cp-slider-hero">
                            <div class="mts-cp-slider-hero-score">
                                <strong><?php echo esc_html( $results['avg_val'] ); ?></strong>
                                <span>/ <?php echo esc_html( $results['config']['max'] ); ?></span>
                            </div>
                            <p class="mts-cp-slider-hero-label"><?php esc_html_e( 'Average Rating Score', 'mts-complete-opinion-polls' ); ?></p>
                        </div>
                        <div class="mts-cp-result-track" style="height: 28px; margin: 20px 0;">
                            <span class="mts-cp-result-fill" style="width:<?php echo esc_attr( $results['config']['max'] > 0 ? min( 100, round( ( $results['avg_val'] / $results['config']['max'] ) * 100 ) ) : 0 ); ?>%"></span>
                        </div>
                        <div class="mts-cp-slider-stats-grid">
                            <div><span>Lowest Score:</span> <strong><?php echo esc_html( $results['min_val'] ); ?></strong></div>
                            <div><span>Highest Score:</span> <strong><?php echo esc_html( $results['max_val'] ); ?></strong></div>
                            <div><span>Total Responses:</span> <strong><?php echo esc_html( $total_votes ); ?></strong></div>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="mts-cp-results-chart" role="img" aria-label="<?php esc_attr_e( 'Horizontal bar graph of poll results', 'mts-complete-opinion-polls' ); ?>">
                        <?php foreach ( $results['counts'] as $label => $count ) :
                            $count        = absint( $count );
                            $percentage   = $total_votes > 0 ? round( ( $count / $total_votes ) * 100, 1 ) : 0;
                            $visual_width = min( 100, $percentage );
                            ?>
                            <div class="mts-cp-result-item">
                                <div class="mts-cp-result-topline">
                                    <span class="mts-cp-result-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="mts-cp-result-stat"><strong><?php echo esc_html( $percentage ); ?>%</strong> · <?php echo esc_html( sprintf( _n( '%s vote', '%s votes', $count, 'mts-complete-opinion-polls' ), number_format_i18n( $count ) ) ); ?></span>
                                </div>
                                <div class="mts-cp-result-track"><span class="mts-cp-result-fill" style="width:<?php echo esc_attr( $visual_width ); ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="mts-cp-results-footnote"><?php esc_html_e( 'Results are shown without any minimum-response requirement. Public charts contain combined totals only.', 'mts-complete-opinion-polls' ); ?></p>
                <div class="mts-cp-results-actions">
                    <?php if ( $this->core->poll_is_open( $poll_id ) && ! $this->core->current_user_has_voted( $poll_id ) ) : ?><button type="button" class="mts-cp-primary-button mts-cp-answer-button" data-poll-id="<?php echo esc_attr( $poll_id ); ?>"><?php esc_html_e( 'Answer This Poll', 'mts-complete-opinion-polls' ); ?></button><?php endif; ?>
                    <button type="button" class="mts-cp-secondary-button" data-mts-cp-close><?php esc_html_e( 'Close Results', 'mts-complete-opinion-polls' ); ?></button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function my_answers_shortcode() {
        $this->enqueue_assets();

        global $wpdb;
        $voter_key = $this->core->get_voter_key();
        $user_id   = get_current_user_id();

        if ( $user_id > 0 ) {
            $poll_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT poll_id FROM {$this->core->table} WHERE user_id = %d OR voter_key = %s ORDER BY COALESCE(updated_at, created_at) DESC",
                    $user_id,
                    $voter_key
                )
            );
        } else {
            $poll_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT poll_id FROM {$this->core->table} WHERE voter_key = %s ORDER BY COALESCE(updated_at, created_at) DESC",
                    $voter_key
                )
            );
        }

        ob_start();
        ?>
        <section class="mts-cp-app mts-cp-my-answers" style="<?php echo esc_attr( $this->app_style() ); ?>">
            <header>
                <span class="mts-cp-eyebrow"><?php esc_html_e( 'Your Participation', 'mts-complete-opinion-polls' ); ?></span>
                <h2><?php esc_html_e( 'My Poll Answers', 'mts-complete-opinion-polls' ); ?></h2>
                <p><?php esc_html_e( 'Review polls you have answered and open their current live results.', 'mts-complete-opinion-polls' ); ?></p>
            </header>
            <?php if ( empty( $poll_ids ) ) : ?>
                <div class="mts-cp-empty"><h3><?php esc_html_e( 'You have not answered a poll yet.', 'mts-complete-opinion-polls' ); ?></h3><a class="mts-cp-primary-button" href="<?php echo esc_url( $this->core->page_url( 'polls' ) ); ?>"><?php esc_html_e( 'Browse Opinion Polls', 'mts-complete-opinion-polls' ); ?></a></div>
            <?php else : ?>
                <div class="mts-cp-my-answer-list">
                    <?php foreach ( $poll_ids as $poll_id ) : if ( $this->valid_poll( absint( $poll_id ) ) ) { echo $this->render_poll_row( absint( $poll_id ) ); } endforeach; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endif; ?>
            <?php echo $this->modal_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function single_poll_shortcode( $atts ) {
        $this->enqueue_assets();

        $atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'mts_single_opinion_poll' );
        $poll_id = absint( $atts['id'] );
        if ( ! $this->valid_poll( $poll_id ) ) {
            return '';
        }

        return '<section class="mts-cp-app mts-cp-single" style="' . esc_attr( $this->app_style() ) . '">' . $this->render_poll_row( $poll_id ) . $this->modal_markup() . '</section>';
    }

    private function valid_poll( $poll_id ) {
        return $poll_id > 0 && $this->core->post_type === get_post_type( $poll_id ) && 'publish' === get_post_status( $poll_id );
    }
}
