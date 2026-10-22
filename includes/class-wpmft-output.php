<?php
/** Standalone document and static-asset optimisation. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Output {
	private static $buffer_level = 0;

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'prepare' ), 99 );
	}

	public static function prepare() {
		$s = wpmft_settings();
		if ( empty( $s['enabled'] ) || empty( $s['standalone'] ) || ! wpmft_is_tree_page() || empty( $s['lean_standalone'] ) ) { return; }
		// Optional because theme presets can be used in existing biography blocks.
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		remove_action( 'wp_head', 'wp_print_font_faces', 50 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'lean_styles' ), 100 );
		add_action( 'wp_print_styles', array( __CLASS__, 'lean_styles' ), 100 );
	}

	public static function lean_styles() {
		$styles = wp_styles();
		$theme_paths = array_filter( array( wp_parse_url( get_template_directory_uri(), PHP_URL_PATH ), wp_parse_url( get_stylesheet_directory_uri(), PHP_URL_PATH ) ) );
		foreach ( $styles->queue as $handle ) {
			$src = isset( $styles->registered[$handle] ) ? $styles->registered[$handle]->src : '';
			$path = is_string( $src ) ? wp_parse_url( $src, PHP_URL_PATH ) : '';
			$theme_asset = false;
			foreach ( $theme_paths as $theme_path ) {
				if ( $path && 0 === strpos( $path, trailingslashit( $theme_path ) ) ) { $theme_asset = true; break; }
			}
			if ( $theme_asset || in_array( $handle, array( 'global-styles', 'classic-theme-styles', 'wp-block-template-skip-link' ), true ) ) { wp_dequeue_style( $handle ); }
		}
	}

	/** Called inside our actual template, after WordPress has resolved block-theme hooks. */
	public static function begin_document() {
		remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );
		if ( empty( wpmft_settings()['minify_html'] ) || self::$buffer_level ) { return; }
		ob_start( array( __CLASS__, 'compact_html' ) );
		self::$buffer_level = ob_get_level();
	}

	public static function end_document() {
		if ( self::$buffer_level && ob_get_level() === self::$buffer_level ) { ob_end_flush(); }
		self::$buffer_level = 0;
	}

	/** Preserve raw-text elements, inline spacing and comments; never rewrite inline JS or CSS. */
	public static function compact_html( $html ) {
		if ( strlen( $html ) > 2 * 1024 * 1024 || ! preg_match( '~\A(?:\xEF\xBB\xBF)?[ \t\r\n\f]*(?:<!DOCTYPE\b[^>]*>[ \t\r\n\f]*)?<html\b~i', $html ) ) { return $html; }
		$prefix = 'wpmft-preserved-' . hash( 'sha256', $html ) . '-';
		while ( false !== strpos( $html, $prefix ) ) { $prefix .= 'x'; }
		$protected = array();
		$nested = false;
		// Mask complete tags too: angle brackets and spaces inside quoted attributes are data.
		$pattern = '~<(script|style|pre|textarea|code|samp|kbd|svg|math|xmp|listing|noscript)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>[\s\S]*?(?:</\1\s*>|\z)|<!--[\s\S]*?(?:-->|\z)|</?[a-z][a-z0-9:-]*\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>|<!DOCTYPE\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>~i';
		$masked = preg_replace_callback( $pattern, function ( $match ) use ( &$protected, &$nested, $prefix ) {
			// Nested elements and legacy double-escaped script data need a complete HTML parser.
			$name = ! empty( $match[1] ) ? strtolower( $match[1] ) : '';
			$may_nest = in_array( $name, array( 'pre', 'code', 'samp', 'kbd', 'svg', 'math', 'noscript' ), true );
			$legacy_script = 'script' === $name && false !== strpos( $match[0], '<!--' );
			if ( ( $may_nest || $legacy_script ) && preg_match_all( '~<' . preg_quote( $name, '~' ) . '\b~i', $match[0] ) > 1 ) { $nested = true; }
			$tag = $prefix . count( $protected );
			$key = '<' . $tag . '></' . $tag . '>';
			$protected[$key] = $match[0];
			return $key;
		}, $html );
		if ( null === $masked || $nested ) { return $html; }
		// Keep a separator between inline elements: collapsing it to nothing joins words.
		$boundary = '~(</' . preg_quote( $prefix, '~' ) . '\d+>)[ \t\r\n\f]+(?=<' . preg_quote( $prefix, '~' ) . '\d+>)~';
		$compact = preg_replace( $boundary, '$1 ', $masked );
		return null === $compact ? $html : strtr( trim( $compact ), $protected );
	}
}
