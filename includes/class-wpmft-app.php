<?php
/** Home-screen icons and a public, data-free web-app manifest. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_App {
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'manifest' ), -10 );
		add_action( 'wp', array( __CLASS__, 'prepare' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
	}

	private static function active() {
		return ! empty( wpmft_settings()['enabled'] ) && wpmft_is_tree_page();
	}

	public static function prepare() {
		// Change the icon only on the tree page; other theme pages keep their site icon.
		if ( self::active() && ! empty( wpmft_settings()['app_icons'] ) ) { remove_action( 'wp_head', 'wp_site_icon', 99 ); }
	}

	public static function assets() {
		if ( self::active() && ! empty( wpmft_settings()['app_install'] ) ) {
			// Capture beforeinstallprompt before the React footer mounts. No service worker or offline data cache.
			wp_enqueue_script( 'wpmft-install', wpmft_asset_url( 'install.js' ), array(), wpmft_asset_version(), false );
		}
	}

	public static function head() {
		if ( ! self::active() ) { return; }
		$s = wpmft_settings();
		if ( ! empty( $s['app_icons'] ) ) {
			$icons = WPMFT_URL . 'assets/icons/';
			echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( $icons . 'oak.svg' ) . '">' . "\n";
			echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( $icons . 'favicon-32.png' ) . '">' . "\n";
			echo '<link rel="icon" type="image/png" sizes="16x16" href="' . esc_url( $icons . 'favicon-16.png' ) . '">' . "\n";
			echo '<link rel="shortcut icon" href="' . esc_url( $icons . 'favicon.ico' ) . '">' . "\n";
			echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( WPMFT_URL . 'touch.png' ) . '">' . "\n";
		}
		if ( ! empty( $s['app_install'] ) ) {
			$lang = wpmft_language();
			$url = add_query_arg( array( 'wpmft_manifest' => '1', 'wpmft_app_lang' => $lang, 'wpmft_app_page' => get_queried_object_id() ), home_url( '/' ) );
			$name = wpmft_translated_setting( $s, 'title', $lang );
			echo '<link rel="manifest" href="' . esc_url( $url ) . '">' . "\n";
			echo '<meta name="theme-color" content="' . esc_attr( sanitize_hex_color( $s['accent'] ) ?: '#315a42' ) . '">' . "\n";
			echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
			echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
			echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
			echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( wp_strip_all_tags( $name ) ) . '">' . "\n";
		}
	}

	/** Public metadata only. Never include a person ID, viewing token, password, or contact details. */
	public static function payload( $lang, $page_id = 0 ) {
		$s = wpmft_settings();
		$lang = wpmft_language( $lang );
		$start = wpmft_tree_url( $lang );
		$page = $page_id ? get_post( $page_id ) : null;
		if ( $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status &&
			( (int) $s['page_id'] === (int) $page->ID || has_shortcode( $page->post_content, 'wp_my_family_tree' ) || has_shortcode( $page->post_content, 'family-tree' ) || has_shortcode( $page->post_content, 'family-members' ) || has_block( 'wp-my-family-tree/tree', $page ) ) ) {
			$translated = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $page->ID, $lang ) : 0;
			$start = get_permalink( $translated && 'publish' === get_post_status( $translated ) ? $translated : $page->ID );
		}
		if ( ! wpmft_polylang_languages() ) { $start = add_query_arg( 'wpmft_lang', $lang, $start ); }
		$home = wp_parse_url( home_url( '/' ) );
		$parts = wp_parse_url( $start );
		// The plugin's privacy session and language integration use one origin.
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || $parts['host'] !== $home['host'] ||
			( $parts['scheme'] ?? '' ) !== $home['scheme'] || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) { $start = home_url( '/' ); }
		$scope = trailingslashit( $home['path'] ?? '/' );
		$icons = WPMFT_URL . 'assets/icons/';
		$labels = WPMFT_Languages::labels( $lang );
		return array(
			'id' => $scope . '?wpmft-app=1',
			'name' => wp_strip_all_tags( wpmft_translated_setting( $s, 'title', $lang ) ),
			'short_name' => $labels['tree'] ?? 'Family Tree',
			'description' => $labels['subtitle'] ?? 'Your family stories, generations and photographs.',
			'lang' => str_replace( '_', '-', wpmft_locale( $lang ) ),
			'start_url' => esc_url_raw( $start ), 'scope' => $scope,
			'display' => 'standalone', 'display_override' => array( 'standalone', 'minimal-ui' ),
			'background_color' => sanitize_hex_color( $s['background'] ) ?: '#f5f4ed',
			'theme_color' => sanitize_hex_color( $s['accent'] ) ?: '#315a42',
			'prefer_related_applications' => false,
			'icons' => array(
				array( 'src' => $icons . 'app-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => $icons . 'app-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => $icons . 'app-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		);
	}

	public static function manifest() {
		if ( ! isset( $_GET['wpmft_manifest'] ) || ! is_string( $_GET['wpmft_manifest'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['wpmft_manifest'] ) ) ) { return; }
		$s = wpmft_settings();
		WPMFT_Security::no_cache();
		if ( empty( $s['enabled'] ) || empty( $s['app_install'] ) ) { status_header( 404 ); exit; }
		$lang = isset( $_GET['wpmft_app_lang'] ) && is_string( $_GET['wpmft_app_lang'] ) ? sanitize_key( wp_unslash( $_GET['wpmft_app_lang'] ) ) : '';
		$page = isset( $_GET['wpmft_app_page'] ) && is_scalar( $_GET['wpmft_app_page'] ) ? absint( $_GET['wpmft_app_page'] ) : 0;
		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( self::payload( $lang, $page ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
		exit;
	}
}
