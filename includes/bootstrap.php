<?php
/**
 * Shared plugin initialization after the duplicate-copy guard.
 *
 * @package MyFamilyTree
 */

defined( 'ABSPATH' ) || exit;

require_once WPMFT_DIR . 'includes/functions.php';
require_once WPMFT_DIR . 'includes/class-wpmft-languages.php';
require_once WPMFT_DIR . 'includes/class-wpmft-data.php';
require_once WPMFT_DIR . 'includes/class-wpmft-security.php';
require_once WPMFT_DIR . 'includes/class-wpmft-media.php';
require_once WPMFT_DIR . 'includes/class-wpmft-contact-upload.php';
require_once WPMFT_DIR . 'includes/class-wpmft-api.php';
require_once WPMFT_DIR . 'includes/class-wpmft-admin.php';
require_once WPMFT_DIR . 'includes/class-wpmft-app.php';
require_once WPMFT_DIR . 'includes/class-wpmft-cache.php';
require_once WPMFT_DIR . 'includes/class-wpmft-toolbar.php';
require_once WPMFT_DIR . 'includes/class-wpmft-compatibility.php';
require_once WPMFT_DIR . 'includes/class-wpmft-demo.php';
require_once WPMFT_DIR . 'includes/class-wpmft-performance.php';
require_once WPMFT_DIR . 'includes/class-wpmft-output.php';
require_once WPMFT_DIR . 'includes/class-wpmft-celebrations.php';

function wpmft_activate() {
	$s = wpmft_settings();
	if ( ! $s['category_id'] ) {
		$legacy = get_option( 'family_tree_category_key', 'Family' );
		$cat = get_term_by( 'name', $legacy, 'category' );
		if ( ! $cat ) { $cat = get_term_by( 'slug', sanitize_title( $legacy ), 'category' ); }
		if ( ! $cat ) {
			$created = wp_insert_term( 'Family', 'category', array( 'slug' => 'family' ) );
			$s['category_id'] = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
		} else { $s['category_id'] = (int) $cat->term_id; }
	}
	if ( ! $s['session_version'] ) { $s['session_version'] = wp_generate_password( 32, false ); }
	update_option( 'wpmft_settings', $s, false );
	WPMFT_Celebrations::ensure_schedule();
}
register_activation_hook( WPMFT_FILE, 'wpmft_activate' );
register_deactivation_hook( WPMFT_FILE, array( 'WPMFT_Demo', 'deactivate' ) );
register_deactivation_hook( WPMFT_FILE, array( 'WPMFT_Celebrations', 'deactivate' ) );

add_action( 'init', function () {
	wp_register_script( 'wpmft-app', wpmft_asset_url( 'app.js' ), array( 'wp-element' ), wpmft_asset_version(), true );
	wp_register_style( 'wpmft-app', wpmft_asset_url( 'app.css' ), array(), wpmft_asset_version() );
	register_block_type( WPMFT_DIR, array( 'render_callback' => 'wpmft_block' ) );
	wp_set_script_translations( 'wp-my-family-tree-tree-editor-script', 'digitalpulse-family-archive', WPMFT_DIR . 'languages' );
	foreach ( array( 'wp_my_family_tree', 'family-tree', 'family-members' ) as $tag ) { add_shortcode( $tag, 'wpmft_shortcode' ); }
	if ( function_exists( 'pll_register_string' ) ) {
		$s = wpmft_settings();
		pll_register_string( 'wpmft-title', $s['title'], 'DigitalPulse Family Archive' );
		pll_register_string( 'wpmft-subtitle', $s['subtitle'], 'DigitalPulse Family Archive' );
	}
}, 20 );

function wpmft_block( $attributes ) { return wpmft_shortcode( array( 'root' => $attributes['rootId'] ?? 0 ) ); }

function wpmft_shortcode( $attributes = array(), $content = null, $tag = 'wp_my_family_tree' ) {
	$s = wpmft_settings();
	if ( ! $s['enabled'] ) { return ''; }
	$attributes = shortcode_atts( array( 'root' => 0, 'view' => $tag === 'family-members' ? 'list' : 'tree' ), $attributes, 'wp_my_family_tree' );
	$root = absint( $attributes['root'] );
	if ( ! $root && ! empty( $attributes['root'] ) ) {
		$title = sanitize_text_field( (string) $attributes['root'] );
		$matches = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'title' => $title, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'wpmft_internal' => true, 'suppress_filters' => false ) );
		$root = $matches ? absint( $matches[0] ) : 0;
	}
	$root = $root ?: $s['root_id'];
	wp_enqueue_script( 'wpmft-app' );
	wp_enqueue_style( 'wpmft-app' );
	$language = wpmft_language(); $public_settings = wpmft_public_settings();
	wp_localize_script( 'wpmft-app', 'WPMFT_CONFIG', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'rootId' => $root,
		'language' => $language, 'i18n' => wpmft_language_config(), 'settings' => $public_settings,
		'translations' => WPMFT_Languages::dictionaries( 'frontend', wpmft_polylang_languages() ),
	) );
	$title = $public_settings['translations'][$language]['title'] ?? $public_settings['title'];
	return '<div class="wpmft-app" data-view="' . ( $attributes['view'] === 'list' ? 'list' : 'tree' ) . '" data-root="' . esc_attr( $root ) . '"><div class="wpmft-boot"><h1>' . esc_html( $title ) . '</h1><p role="status">' . esc_html__( 'Loading the family tree…', 'digitalpulse-family-archive' ) . '</p><noscript>' . esc_html__( 'Please enable JavaScript to explore this interactive family tree.', 'digitalpulse-family-archive' ) . '</noscript></div></div>';
}

add_filter( 'template_include', function ( $template ) {
	$s = wpmft_settings();
	if ( $s['enabled'] && $s['standalone'] && wpmft_is_tree_page() ) { return WPMFT_DIR . 'templates/standalone.php'; }
	return $template;
}, 99 );
add_action( 'wp_enqueue_scripts', function () {
	if ( wpmft_is_tree_page() ) { wp_enqueue_style( 'wpmft-app' ); wp_enqueue_script( 'wpmft-app' ); }
} );

WPMFT_Languages::init();
WPMFT_Demo::init();
WPMFT_Toolbar::init();
WPMFT_Compatibility::init();
WPMFT_Security::init();
WPMFT_Media::init();
WPMFT_API::init();
WPMFT_Admin::init();
WPMFT_App::init();
WPMFT_Cache::init();
WPMFT_Performance::init();
WPMFT_Output::init();
WPMFT_Celebrations::init();
