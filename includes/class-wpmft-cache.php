<?php
/** Server-only relationship indexes; never cache access decisions or person responses. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Cache {
	const GENERATION_OPTION = 'wpmft_cache_generation';
	const OBJECT_GROUP = 'wpmft_performance';
	private static $dirty = array();
	private static $build_generation = null;

	public static function init() {
		add_action( 'clean_post_cache', array( __CLASS__, 'post_changed' ), 10, 2 );
		add_action( 'save_post_post', array( __CLASS__, 'post_changed' ), 100, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_changed' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'post_changed' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 4 );
		}
		add_action( 'set_object_terms', array( __CLASS__, 'object_terms_changed' ), 10, 4 );
		add_action( 'deleted_term_relationships', array( __CLASS__, 'relationships_changed' ), 10, 3 );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'term_changed' ), 10, 3 );
		}
		add_action( 'pll_save_post', array( __CLASS__, 'post_changed' ), 100, 2 );
		add_action( 'pll_save_term', array( __CLASS__, 'polylang_term_changed' ), 100, 2 );
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'option_changed' ) );
		}
		add_action( 'switch_blog', array( __CLASS__, 'switch_blog' ) );
		add_action( 'shutdown', array( __CLASS__, 'finish_mutations' ), PHP_INT_MAX );
	}

	private static function enabled() { return ! empty( wpmft_settings()['performance_cache'] ); }
	private static function ttl() { return max( 1, min( 60, absint( wpmft_settings()['cache_ttl'] ?? 15 ) ) ); }
	private static function dirty() { return ! empty( self::$dirty[get_current_blog_id()] ); }

	private static function requested_backend() {
		$backend = wpmft_settings()['cache_backend'] ?? 'auto';
		return in_array( $backend, array( 'auto', 'transient', 'object' ), true ) ? $backend : 'auto';
	}

	/** Object caching needs a persistent cache supplied by the host or another plugin. */
	private static function backend() {
		return 'transient' !== self::requested_backend() && wp_using_ext_object_cache() ? 'object' : 'transient';
	}

	private static function read_record( $key ) {
		return 'object' === self::backend() ? wp_cache_get( $key, self::OBJECT_GROUP ) : get_transient( $key );
	}

	private static function store_record( $key, $record, $ttl ) {
		return 'object' === self::backend() ? wp_cache_set( $key, $record, self::OBJECT_GROUP, $ttl ) : set_transient( $key, $record, $ttl );
	}

	/** Read the tiny generation directly so another worker's invalidation is not hidden by a local option cache. */
	private static function generation() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache generation must be fresh across workers.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", self::GENERATION_OPTION ) );
		if ( is_string( $value ) && '' !== $value ) { return $value; }
		add_option( self::GENERATION_OPTION, wp_generate_uuid4(), '', false );
		// A competing request may have created the option first. Read its actual value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", self::GENERATION_OPTION ) );
	}

	private static function key( $generation ) {
		return 'wpmft_graph_' . md5( get_current_blog_id() . '|' . WPMFT_VERSION . '|' . (int) function_exists( 'pll_get_post_translations' ) . '|' . $generation );
	}

	private static function rotate_generation() {
		$old = self::generation();
		// Only this performance namespace is deleted. Access/rate-limit transients are unrelated.
		if ( '' !== $old ) {
			// Delete both locations, including after switching the selected backend.
			delete_transient( self::key( $old ) );
			wp_cache_delete( self::key( $old ), self::OBJECT_GROUP );
		}
		update_option( self::GENERATION_OPTION, wp_generate_uuid4(), false );
		self::$build_generation = null;
	}

	/** Clear this site's plugin performance cache; old in-flight builders keep an obsolete key. */
	public static function clear() {
		self::invalidate();
	}

	/** Shared manual purge for the settings page and the authenticated toolbar action. */
	public static function purge() {
		self::clear();
		update_option( 'wpmft_asset_revision', str_replace( '-', '', wp_generate_uuid4() ), false );
		update_option( 'wpmft_cache_cleared_at', time(), false );
	}

	private static function invalidate() {
		WPMFT_Data::reset_cache();
		self::$build_generation = null;
		if ( self::dirty() ) { return; }
		self::$dirty[get_current_blog_id()] = true;
		self::rotate_generation();
	}

	/** A second rotation rejects indexes built by other requests halfway through a multi-field save. */
	public static function finish_mutations() {
		foreach ( array_keys( self::$dirty ) as $blog_id ) {
			$switched = get_current_blog_id() !== (int) $blog_id;
			if ( $switched ) { switch_to_blog( $blog_id ); }
			self::rotate_generation();
			if ( $switched ) { restore_current_blog(); }
		}
		self::$dirty = array();
		WPMFT_Data::reset_cache();
	}

	public static function switch_blog() {
		self::$build_generation = null;
		WPMFT_Data::reset_cache();
	}

	public static function post_changed( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( $post && 'post' === $post->post_type ) { self::invalidate(); }
	}

	public static function meta_changed( $meta_id, $post_id, $key, $value = null ) {
		if ( in_array( $key, array( 'father', 'mother', 'spouse', '_wpmft_spouses', '_wpmft_partner_status', '_wpmft_person' ), true ) || 0 === strpos( (string) $key, '_pll_' ) ) {
			self::post_changed( $post_id );
		}
	}

	private static function relevant_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, array( 'category', 'language', 'term_language', 'post_translations', 'term_translations' ), true );
	}
	public static function object_terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( self::relevant_taxonomy( $taxonomy ) ) { self::invalidate(); }
	}
	public static function relationships_changed( $object_id, $tt_ids, $taxonomy ) {
		if ( self::relevant_taxonomy( $taxonomy ) ) { self::invalidate(); }
	}
	public static function term_changed( $term_id, $tt_id, $taxonomy ) {
		if ( self::relevant_taxonomy( $taxonomy ) ) { self::invalidate(); }
	}
	public static function polylang_term_changed( $term_id, $taxonomy ) {
		if ( self::relevant_taxonomy( $taxonomy ) ) { self::invalidate(); }
	}
	public static function option_changed( $name ) {
		if ( in_array( $name, array( 'wpmft_settings', 'family_tree_category_key', 'polylang', 'active_plugins' ), true ) ) { self::invalidate(); }
	}

	/** Cache shape is deliberately limited to numeric IDs, graph edges and known relationship enums. */
	private static function valid_graph( $graph ) {
		if ( ! is_array( $graph ) || array_diff( array_keys( $graph ), array( 'index', 'canonical', 'partners' ) ) ) { return false; }
		foreach ( array( 'index', 'canonical', 'partners' ) as $field ) { if ( ! isset( $graph[$field] ) || ! is_array( $graph[$field] ) ) { return false; } }
		foreach ( $graph['index'] as $id => $relations ) {
			if ( ! is_int( $id ) || $id < 1 || ! is_array( $relations ) || count( $relations ) !== 4 ) { return false; }
			foreach ( array( 'parents', 'spouses', 'children', 'siblings' ) as $field ) {
				if ( ! isset( $relations[$field] ) || ! is_array( $relations[$field] ) ) { return false; }
				foreach ( $relations[$field] as $related ) { if ( ! is_int( $related ) || ! isset( $graph['index'][$related] ) ) { return false; } }
			}
		}
		foreach ( $graph['canonical'] as $id => $canonical ) {
			if ( ! is_int( $id ) || $id < 1 || ! is_int( $canonical ) || ! isset( $graph['index'][$canonical] ) ) { return false; }
		}
		foreach ( $graph['partners'] as $id => $partners ) {
			if ( ! isset( $graph['index'][$id] ) || ! is_array( $partners ) ) { return false; }
			foreach ( $partners as $partner => $status ) {
				if ( ! isset( $graph['index'][$partner] ) || ! in_array( $status, array( 'legacy', 'co_parent', 'together', 'former', 'separated', 'divorced' ), true ) ) { return false; }
			}
		}
		return true;
	}

	private static function record( $generation ) {
		if ( '' === $generation ) { return false; }
		$record = self::read_record( self::key( $generation ) );
		if ( ! is_array( $record ) || 1 !== ( $record['schema'] ?? 0 ) || $generation !== ( $record['generation'] ?? '' ) || ! is_int( $record['built_at'] ?? null ) || ! is_int( $record['expires_at'] ?? null ) || $record['expires_at'] <= time() || ! self::valid_graph( $record['graph'] ?? null ) ) { return false; }
		return $record;
	}

	public static function read_graph() {
		self::$build_generation = null;
		if ( ! self::enabled() || self::dirty() ) { return false; }
		self::$build_generation = self::generation();
		$record = self::record( self::$build_generation );
		return $record ? $record['graph'] : false;
	}

	public static function write_graph( $graph ) {
		$generation = self::$build_generation;
		if ( ! self::enabled() || self::dirty() || ! $generation || ! self::valid_graph( $graph ) || $generation !== self::generation() ) { return false; }
		$ttl = self::ttl() * MINUTE_IN_SECONDS;
		return self::store_record( self::key( $generation ), array( 'schema' => 1, 'generation' => $generation, 'built_at' => time(), 'expires_at' => time() + $ttl, 'graph' => $graph ), $ttl );
	}

	public static function status() {
		$enabled = self::enabled();
		$record = $enabled && ! self::dirty() ? self::record( self::generation() ) : false;
		return array(
			'enabled' => $enabled,
			'ttl' => self::ttl(),
			'warm' => (bool) $record,
			'built_at' => $record ? $record['built_at'] : 0,
			'backend' => self::backend(),
			'requested_backend' => self::requested_backend(),
			'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
			'backend_fallback' => 'object' === self::requested_backend() && ! wp_using_ext_object_cache(),
		);
	}
}
