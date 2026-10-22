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
		'contact_upload_enabled' => 1, 'contact_upload_max_mb' => 10,
		'contact_upload_formats' => array( 'jpg', 'jpeg', 'png', 'webp' ),
		'password_protection' => 1, 'password_hash' => '', 'session_version' => '', 'remember_days' => 30,
		'hide_living_dates' => 0, 'private_media' => 1, 'language' => 'en', 'language_switch' => 1,
		'person_block_editor' => 0, 'app_install' => 1, 'app_icons' => 1,
		'accent' => '#315a42', 'background' => '#f5f4ed', 'tree_height' => 760,
		'allow_export' => 1, 'show_credit' => 0, 'hcaptcha_enabled' => 0,
		'hcaptcha_site_key' => '', 'hcaptcha_secret' => '', 'hcaptcha_login' => 0, 'hcaptcha_contact' => 1,
	);
	$s = get_option( 'wpmft_settings', array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), $defaults );
}

/** The interface supports English and Latvian; an unconfigured Polylang keeps the local switch. */
function wpmft_polylang_languages() {
	if ( ! function_exists( 'pll_languages_list' ) ) { return array(); }
	return array_values( array_intersect( array( 'en', 'lv' ), (array) pll_languages_list( array( 'hide_empty' => 0, 'fields' => 'slug' ) ) ) );
}

function wpmft_language( $requested = '' ) {
	$languages = wpmft_polylang_languages();
	// AJAX explicitly supplies the page language; it must be a supported, configured language.
	if ( in_array( $requested, array( 'en', 'lv' ), true ) && ( ! $languages || in_array( $requested, $languages, true ) ) ) { return $requested; }
	if ( $languages ) {
		$lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
		if ( in_array( $lang, $languages, true ) ) { return $lang; }
		$lang = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : '';
		return in_array( $lang, $languages, true ) ? $lang : $languages[0];
	}
	// Legacy query switching applies only when Polylang does not manage the site language.
	if ( isset( $_GET['wpmft_lang'] ) && in_array( $_GET['wpmft_lang'], array( 'en', 'lv' ), true ) ) { return sanitize_key( $_GET['wpmft_lang'] ); }
	return wpmft_settings()['language'] === 'lv' ? 'lv' : 'en';
}

function wpmft_locale( $lang ) {
	if ( function_exists( 'pll_languages_list' ) ) {
		$slugs = (array) pll_languages_list( array( 'hide_empty' => 0, 'fields' => 'slug' ) );
		$locales = (array) pll_languages_list( array( 'hide_empty' => 0, 'fields' => 'locale' ) );
		$position = array_search( $lang, $slugs, true );
		if ( false !== $position && ! empty( $locales[$position] ) ) { return $locales[$position]; }
	}
	return $lang === 'lv' ? 'lv' : 'en_US';
}

/** Use Polylang's translated URL for this page, preserving the normal theme language and locale. */
function wpmft_language_config() {
	$current = wpmft_language(); $languages = wpmft_polylang_languages();
	$result = array( 'provider' => $languages ? 'polylang' : 'local', 'current' => $current, 'languages' => array() );
	if ( ! $languages ) { return $result; }
	$links = array();
	if ( ! is_admin() && function_exists( 'pll_the_languages' ) ) {
		foreach ( (array) pll_the_languages( array( 'raw' => 1, 'echo' => 0, 'hide_if_empty' => 0, 'hide_if_no_translation' => 0 ) ) as $link ) {
			if ( is_array( $link ) && isset( $link['slug'] ) ) { $links[$link['slug']] = $link; }
		}
	}
	foreach ( $languages as $slug ) {
		$link = $links[$slug] ?? array();
		$url = ! empty( $link['url'] ) && empty( $link['no_translation'] ) ? $link['url'] : wpmft_tree_url( $slug );
		$result['languages'][] = array(
			'slug' => $slug, 'name' => ! empty( $link['name'] ) ? $link['name'] : ( $slug === 'lv' ? 'Latviešu' : 'English' ),
			'locale' => wpmft_locale( $slug ),
			'url' => esc_url_raw( $url ), 'current' => $slug === $current,
		);
	}
	return $result;
}

/** Explicit Latvian settings remain the fallback until an administrator adds a Polylang string translation. */
function wpmft_translated_setting( $settings, $key, $lang ) {
	$source = $settings[$key];
	$fallback = $lang === 'lv' && isset( $settings[$key . '_lv'] ) ? $settings[$key . '_lv'] : $source;
	if ( function_exists( 'pll_translate_string' ) && in_array( $lang, wpmft_polylang_languages(), true ) ) {
		$translation = pll_translate_string( $source, $lang );
		if ( is_string( $translation ) && $translation !== '' && $translation !== $source ) { return $translation; }
	}
	return $fallback;
}

function wpmft_public_settings() {
	$s = wpmft_settings();
	return array(
		'title' => wpmft_translated_setting( $s, 'title', 'en' ), 'titleLv' => wpmft_translated_setting( $s, 'title', 'lv' ),
		'subtitle' => wpmft_translated_setting( $s, 'subtitle', 'en' ), 'subtitleLv' => wpmft_translated_setting( $s, 'subtitle', 'lv' ),
		'contactName' => $s['contact_name'], 'contactEmail' => $s['show_email'] ? $s['contact_email'] : '',
		'contactEnabled' => (bool) $s['contact_enabled'], 'accent' => $s['accent'], 'background' => $s['background'],
		'contactUpload' => WPMFT_Contact_Upload::config(), 'appInstall' => (bool) $s['app_install'],
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
	if ( $s['page_id'] && function_exists( 'pll_get_post_translations' ) && in_array( (int) $p->ID, array_map( 'intval', (array) pll_get_post_translations( $s['page_id'] ) ), true ) ) { return true; }
	return has_shortcode( $p->post_content, 'wp_my_family_tree' ) || has_shortcode( $p->post_content, 'family-tree' ) || has_shortcode( $p->post_content, 'family-members' ) || has_block( 'wp-my-family-tree/tree', $p );
}

function wpmft_tree_url( $lang = '' ) {
	$s = wpmft_settings();
	$lang = wpmft_language( $lang );
	if ( wpmft_polylang_languages() ) {
		$page = $s['page_id'] && function_exists( 'pll_get_post' ) ? pll_get_post( $s['page_id'], $lang ) : 0;
		if ( $page && 'publish' === get_post_status( $page ) ) { return get_permalink( $page ); }
		if ( function_exists( 'pll_home_url' ) ) { $url = pll_home_url( $lang ); if ( $url ) { return $url; } }
	}
	if ( $s['page_id'] && 'publish' === get_post_status( $s['page_id'] ) ) { return get_permalink( $s['page_id'] ); }
	return home_url( '/' );
}
