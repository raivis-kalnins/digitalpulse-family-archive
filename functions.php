<?php
/** Shared settings and rendering utilities. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

function wpmft_settings() {
	$defaults = array(
		'enabled' => 1, 'page_id' => 0, 'category_id' => 0, 'root_id' => 0, 'standalone' => 1,
		'title' => 'Our family tree', 'title_lv' => 'Mūsu dzimtas koks',
		'subtitle' => 'Every leaf has a story. Every branch brings us together.',
		'subtitle_lv' => 'Katrai lapai savs stāsts. Katrs zars mūs vieno.',
		'contact_name' => '', 'contact_email' => get_option( 'admin_email' ), 'show_email' => 0, 'contact_enabled' => 1,
		'password_protection' => 1, 'password_hash' => '', 'session_version' => '', 'remember_days' => 30,
		'hide_living_dates' => 0, 'private_media' => 1, 'language' => 'en', 'language_switch' => 1,
		'accent' => '#315a42', 'background' => '#f5f4ed', 'tree_height' => 760,
		'allow_export' => 1, 'show_credit' => 0, 'hcaptcha_enabled' => 0,
		'hcaptcha_site_key' => '', 'hcaptcha_secret' => '', 'hcaptcha_login' => 0, 'hcaptcha_contact' => 1,
	);
	$s = get_option( 'wpmft_settings', array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), $defaults );
}

function wpmft_language( $requested = '' ) {
	if ( in_array( $requested, array( 'en', 'lv' ), true ) ) { return $requested; }
	if ( isset( $_GET['wpmft_lang'] ) && in_array( $_GET['wpmft_lang'], array( 'en', 'lv' ), true ) ) { return sanitize_key( $_GET['wpmft_lang'] ); }
	if ( function_exists( 'pll_current_language' ) ) {
		$lang = pll_current_language( 'slug' );
		if ( in_array( $lang, array( 'en', 'lv' ), true ) ) { return $lang; }
	}
	return wpmft_settings()['language'] === 'lv' ? 'lv' : 'en';
}

function wpmft_public_settings() {
	$s = wpmft_settings();
	return array(
		'title' => $s['title'], 'titleLv' => $s['title_lv'], 'subtitle' => $s['subtitle'], 'subtitleLv' => $s['subtitle_lv'],
		'contactName' => $s['contact_name'], 'contactEmail' => $s['show_email'] ? $s['contact_email'] : '',
		'contactEnabled' => (bool) $s['contact_enabled'], 'accent' => $s['accent'], 'background' => $s['background'],
		'showCredit' => (bool) $s['show_credit'], 'allowExport' => (bool) $s['allow_export'],
		'treeHeight' => (int) $s['tree_height'], 'defaultView' => 'tree', 'languageSwitch' => (bool) $s['language_switch'],
	);
}

function wpmft_is_person( $id ) { return WPMFT_Data::is_person( (int) $id ); }
function wpmft_staff() { return current_user_can( 'edit_others_posts' ); }
function wpmft_is_tree_page() {
	if ( ! is_singular( 'page' ) ) { return false; }
	$p = get_queried_object();
	if ( ! $p instanceof WP_Post ) { return false; }
	$s = wpmft_settings();
	if ( $s['page_id'] && (int) $p->ID === (int) $s['page_id'] ) { return true; }
	return has_shortcode( $p->post_content, 'wp_my_family_tree' ) || has_shortcode( $p->post_content, 'family-tree' ) || has_shortcode( $p->post_content, 'family-members' ) || has_block( 'wp-my-family-tree/tree', $p );
}

function wpmft_tree_url() {
	$s = wpmft_settings();
	if ( $s['page_id'] && 'publish' === get_post_status( $s['page_id'] ) ) { return get_permalink( $s['page_id'] ); }
	return home_url( '/' );
}
