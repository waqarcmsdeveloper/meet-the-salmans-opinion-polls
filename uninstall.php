<?php
/**
 * Uninstall cleanup for Meet the Salmans – Complete Opinion Polls.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = (array) get_option( 'mts_cp_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) || '1' !== (string) $settings['delete_on_uninstall'] ) {
    return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mts_cp_votes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$poll_ids = get_posts(
    array(
        'post_type'      => 'mts_cp_poll',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )
);
foreach ( $poll_ids as $poll_id ) {
    wp_delete_post( $poll_id, true );
}

$terms = get_terms(
    array(
        'taxonomy'   => 'mts_cp_category',
        'hide_empty' => false,
    )
);
if ( ! is_wp_error( $terms ) ) {
    foreach ( $terms as $term ) {
        wp_delete_term( $term->term_id, 'mts_cp_category' );
    }
}

$page_ids = (array) get_option( 'mts_cp_page_ids', array() );
$expected = array(
    'register' => '[mts_poll_register]',
    'login'    => '[mts_poll_login]',
    'polls'    => '[mts_opinion_polls]',
    'account'  => '[mts_my_poll_answers]',
);
foreach ( $page_ids as $key => $page_id ) {
    $page = get_post( absint( $page_id ) );
    if ( $page instanceof WP_Post && isset( $expected[ $key ] ) && trim( (string) $page->post_content ) === $expected[ $key ] ) {
        wp_delete_post( $page->ID, true );
    }
}

delete_option( 'mts_cp_settings' );
delete_option( 'mts_cp_page_ids' );
delete_option( 'mts_cp_sample_polls_created' );
delete_option( 'mts_cp_activation_notice_dismissed' );
