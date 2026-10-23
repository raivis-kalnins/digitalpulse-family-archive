<?php
/**
 * Plugin Name: WP My Family Tree
 * Plugin URI: https://github.com/raivis-kalnins/wp-my-family-tree
 * Description: A private, interactive family tree with touch galleries, multilingual labels, demo tools, a block and shortcode. Reuses WP Family Tree posts.
 * Version: 1.2.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Raivis Kalnins — DigitalPulse
 * Author URI: https://digitalpulse.click/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-my-family-tree
 * Domain Path: /languages
 *
 * @package WPMyFamilyTree
 */
defined( 'ABSPATH' ) || exit;
define( 'WPMFT_VERSION', '1.2.2' );
define( 'WPMFT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMFT_URL', plugin_dir_url( __FILE__ ) );

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
}
register_activation_hook( __FILE__, 'wpmft_activate' );
register_deactivation_hook( __FILE__, array( 'WPMFT_Demo', 'deactivate' ) );

add_action( 'init', function () {
	load_plugin_textdomain( 'wp-my-family-tree', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	wp_register_script( 'wpmft-app', wpmft_asset_url( 'app.js' ), array( 'wp-element' ), wpmft_asset_version(), true );
	wp_register_style( 'wpmft-app', wpmft_asset_url( 'app.css' ), array(), wpmft_asset_version() );
	register_block_type( WPMFT_DIR, array( 'render_callback' => 'wpmft_block' ) );
	wp_set_script_translations( 'wp-my-family-tree-tree-editor-script', 'wp-my-family-tree', WPMFT_DIR . 'languages' );
	foreach ( array( 'wp_my_family_tree', 'family-tree', 'family-members' ) as $tag ) { add_shortcode( $tag, 'wpmft_shortcode' ); }
	if ( function_exists( 'pll_register_string' ) ) {
		$s = wpmft_settings();
		pll_register_string( 'wpmft-title', $s['title'], 'WP My Family Tree' );
		pll_register_string( 'wpmft-subtitle', $s['subtitle'], 'WP My Family Tree' );
	}
}, 20 );

function wpmft_block( $attributes ) { return wpmft_shortcode( array( 'root' => $attributes['rootId'] ?? 0 ) ); }

function wpmft_shortcode( $attributes = array(), $content = null, $tag = 'wp_my_family_tree' ) {
	$s = wpmft_settings();
	if ( ! $s['enabled'] ) { return ''; }
	$attributes = shortcode_atts( array( 'root' => 0, 'view' => $tag === 'family-members' ? 'list' : 'tree' ), $attributes, 'wp_my_family_tree' );
	$root = absint( $attributes['root'] );
	if ( ! $root && ! empty( $attributes['root'] ) ) {
		global $wpdb;
		$root = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_title=%s LIMIT 1", $attributes['root'] ) );
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
	return '<div class="wpmft-app" data-view="' . ( $attributes['view'] === 'list' ? 'list' : 'tree' ) . '" data-root="' . esc_attr( $root ) . '"><div class="wpmft-boot"><h1>' . esc_html( $title ) . '</h1><p role="status">' . esc_html__( 'Loading the family tree…', 'wp-my-family-tree' ) . '</p><noscript>' . esc_html__( 'Please enable JavaScript to explore this interactive family tree.', 'wp-my-family-tree' ) . '</noscript></div></div>';
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
