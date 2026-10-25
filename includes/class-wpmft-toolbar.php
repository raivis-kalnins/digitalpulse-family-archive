<?php
/**
 * Administrator toolbar controls for this plugin's performance cache.
 *
 * @package WPMyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

final class WPMFT_Toolbar {
	const ACTION = 'wpmft_toolbar_clear_cache';

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'menu' ), 90 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 100 );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'clear_cache' ) );
	}

	public static function menu( $admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$settings_url = admin_url( 'admin.php?page=wpmft#wpmft-performance' );
		$admin_bar->add_node( array(
			'id' => 'wpmft-cache',
			'title' => '<span class="ab-icon wpmft-cache-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Family Tree cache', 'my-family-tree' ) . '</span>',
			// A useful fallback when JavaScript is unavailable; JavaScript supplies the POST action.
			'href' => $settings_url,
			'meta' => array( 'title' => __( 'Clear Family Tree cache', 'my-family-tree' ) ),
		) );
		$admin_bar->add_node( array(
			'id' => 'wpmft-cache-settings',
			'parent' => 'wpmft-cache',
			'title' => __( 'Performance & cache settings', 'my-family-tree' ),
			'href' => $settings_url,
		) );
	}

	public static function assets() {
		if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) { return; }
		wp_enqueue_style( 'wpmft-toolbar', wpmft_asset_url( 'toolbar.css' ), array( 'admin-bar' ), wpmft_asset_version() );
		wp_enqueue_script( 'wpmft-toolbar', wpmft_asset_url( 'toolbar.js' ), array(), wpmft_asset_version(), true );
		wp_localize_script( 'wpmft-toolbar', 'WPMFT_TOOLBAR', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action' => self::ACTION,
			'nonce' => wp_create_nonce( self::ACTION ),
			'labels' => array(
				'idle' => __( 'Family Tree cache', 'my-family-tree' ),
				'clear' => __( 'Clear Family Tree cache', 'my-family-tree' ),
				'busy' => __( 'Clearing cache…', 'my-family-tree' ),
				'done' => __( 'Cache cleared', 'my-family-tree' ),
				'failed' => __( 'The cache could not be cleared. Please reload and try again.', 'my-family-tree' ),
			),
		) );
	}

	public static function clear_cache() {
		nocache_headers();
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'A POST request is required.', 'my-family-tree' ) ), 405 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to clear this cache.', 'my-family-tree' ) ), 403 );
		}
		if ( ! isset( $_REQUEST['nonce'] ) || ! is_string( $_REQUEST['nonce'] ) || ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload and try again.', 'my-family-tree' ) ), 403 );
		}
		WPMFT_Cache::purge();
		wp_send_json_success( array(
			'message' => __( 'Family Tree cache cleared. The relationship index rebuilds on the next tree request.', 'my-family-tree' ),
			'clearedAt' => (int) get_option( 'wpmft_cache_cleared_at', 0 ),
		) );
	}
}
