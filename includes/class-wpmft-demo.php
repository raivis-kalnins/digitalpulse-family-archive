<?php
/**
 * Optional, additive demonstration content and an isolated shared demo editor.
 *
 * Nothing is imported or made public on activation. The ordinary family password
 * and settings are never replaced by the demonstration credentials.
 *
 * @package WPMyFamilyTree
 */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Demo {

	const MANIFEST_OPTION = 'wpmft_demo_manifest';
	const STATE_OPTION = 'wpmft_demo_state';
	const BASELINE_OPTION = 'wpmft_demo_baseline';
	const RESET_HOOK = 'wpmft_demo_daily_reset';
	const WRITE_LOCK = 'wpmft_demo_write_lock';
	const ROLE = 'wpmft_demo_editor';
	const CAP = 'wpmft_demo_edit';
	private static $quarantining = false;
	private static $request_scope = false;
	private static $resetting = false;
	private static $mutating_post = 0;
	private static $reset_failed = false;

	/** Once a response uses demo credentials it stays constrained, even if mode changes mid-request. */
	public static function set_request_scope( $enabled = true ) { if ( $enabled ) { self::$request_scope = true; } }
	public static function scoped_request() { return self::$request_scope; }

	public static function init() {
		add_action( 'admin_post_wpmft_demo_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wpmft_demo_enable', array( __CLASS__, 'handle_enable' ) );
		add_action( 'admin_post_wpmft_demo_disable', array( __CLASS__, 'handle_disable' ) );
		add_action( 'admin_post_wpmft_demo_reset', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_post_wpmft_demo_baseline', array( __CLASS__, 'handle_baseline' ) );
		add_action( 'admin_post_wpmft_demo_save', array( __CLASS__, 'handle_save' ) );
		add_action( self::RESET_HOOK, array( __CLASS__, 'scheduled_reset' ) );
		add_action( 'init', array( __CLASS__, 'prepare_reset' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'guard_admin' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'editor_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_capability' ), 99, 4 );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 99, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 99, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'authenticate' ), 99, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 1, 3 );
		add_filter( 'xmlrpc_enabled', array( __CLASS__, 'guard_xmlrpc' ), 99 );
		add_filter( 'posts_where', array( __CLASS__, 'restrict_queries' ), 99, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords' ), 99, 2 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'allow_password_reset' ), 99, 2 );
		add_filter( 'wp_post_revisions_to_keep', array( __CLASS__, 'revision_count' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_page_revisions_to_keep', array( __CLASS__, 'revision_count' ), PHP_INT_MAX, 2 );
	}

	public static function manifest() {
		$value = get_option( self::MANIFEST_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function state() {
		$value = get_option( self::STATE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** Fresh lifecycle reads prevent a reset from reviving a concurrently disabled demo. */
	private static function fresh_option( $name, $fallback = array() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A lifecycle/lock decision must bypass the request option cache.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $name ) );
		if ( $wpdb->last_error ) { return false; }
		return null === $value ? $fallback : maybe_unserialize( $value );
	}

	/** Conditional state writes never overwrite an intervening disable or quarantine. */
	private static function replace_state( $before, $after ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap for this plugin's private state only.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize( $after ), self::STATE_OPTION, maybe_serialize( $before ) ) );
		wp_cache_delete( self::STATE_OPTION, 'options' );
		return 1 === $changed;
	}

	/** A unique option serializes resets, baseline capture and public editor saves. */
	private static function acquire_write_lock() {
		$old = self::fresh_option( self::WRITE_LOCK, '' );
		if ( false === $old ) { return false; }
		if ( is_string( $old ) && $old && (int) substr( $old, strrpos( $old, ':' ) + 1 ) < time() ) { self::release_write_lock( $old ); }
		$lock = wp_generate_uuid4() . ':' . ( time() + 10 * MINUTE_IN_SECONDS );
		if ( ! add_option( self::WRITE_LOCK, $lock, '', false ) ) { return false; }
		return $lock;
	}

	private static function release_write_lock( $lock ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only the exact lock owned by this operation, never a successor's lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", self::WRITE_LOCK, $lock ) );
		wp_cache_delete( self::WRITE_LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function owns_write_lock( $lock ) { return is_string( $lock ) && hash_equals( $lock, (string) self::fresh_option( self::WRITE_LOCK, '' ) ); }

	private static function reset_error() { return new WP_Error( 'demo_reset', __( 'The demo is resetting or its reset could not finish. Please try again shortly.', 'my-family-tree' ) ); }

	private static function revision_ids( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fresh list is needed to remove edits from this demo cycle only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_parent=%d", $id ) );
		return $wpdb->last_error ? false : array_map( 'absint', $ids );
	}

	/** Only fields exposed by the constrained editor are stored or restored. */
	private static function make_baseline( $original = false ) {
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $manifest ) || empty( $manifest['token'] ) || empty( $manifest['items']['marty'] ) ) { return self::reset_error(); }
		$people = self::people();
		$pages = self::pages( $manifest );
		$baseline = array( 'version' => 1, 'token' => $manifest['token'] ?? '', 'created' => time(), 'source' => $original ? 'bundled' : 'administrator', 'items' => array() );
		global $wpdb;
		foreach ( array_merge( $people, $pages ) as $key => $seed ) {
			$id = absint( $manifest['items'][$key] ?? 0 );
			$type = isset( $people[$key] ) ? 'post' : 'page';
			if ( ! $id || ! self::validate_owned_ids( array( $id => $type ) ) ) { return self::reset_error(); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capture the current authoritative demo fields, not a stale object.
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_title,post_content FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s", $id, $type ), ARRAY_A );
			$revisions = $original ? array() : self::revision_ids( $id );
			if ( ! is_array( $post ) || false === $revisions ) { return self::reset_error(); }
			$row = array( 'id' => $id, 'type' => $type, 'title' => $post['post_title'], 'story' => $post['post_content'], 'meta' => array(), 'revisions' => $revisions );
			if ( $original ) { $row['title'] = 'post' === $type ? $seed['name'] : $seed['title']; $row['story'] = 'post' === $type ? self::person_content( $seed ) : $seed['content']; }
			if ( 'post' === $type ) {
				wp_cache_delete( $id, 'post_meta' );
				foreach ( array( 'born' => 'born', '_wpmft_location' => 'location', '_wpmft_occupation' => 'occupation' ) as $meta => $field ) {
					$row['meta'][$meta] = $original ? $seed[$field] : ( metadata_exists( 'post', $id, $meta ) ? (string) get_post_meta( $id, $meta, true ) : null );
				}
			}
			$baseline['items'][$key] = $row;
		}
		return $baseline;
	}

	private static function valid_baseline( $baseline ) {
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $baseline ) || 1 !== ( $baseline['version'] ?? 0 ) || empty( $baseline['token'] ) || empty( $manifest['token'] ) || ! hash_equals( (string) $manifest['token'], (string) $baseline['token'] ) ) { return false; }
		$types = array_fill_keys( array_keys( self::people() ), 'post' ) + array( 'tree-page' => 'page', 'about-page' => 'page' );
		if ( ! isset( $baseline['items'] ) || ! is_array( $baseline['items'] ) || array_diff_key( $types, $baseline['items'] ) || array_diff_key( $baseline['items'], $types ) ) { return false; }
		$expected = array();
		foreach ( $types as $key => $type ) {
			$row = $baseline['items'][$key];
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['type'], $row['title'], $row['story'], $row['meta'], $row['revisions'] ) || absint( $row['id'] ) !== absint( $manifest['items'][$key] ?? 0 ) || $row['type'] !== $type || ! is_string( $row['title'] ) || ! is_string( $row['story'] ) || ! is_array( $row['meta'] ) || ! is_array( $row['revisions'] ) || isset( $expected[(int) $row['id']] ) ) { return false; }
			$allowed = 'post' === $type ? array( 'born', '_wpmft_location', '_wpmft_occupation' ) : array();
			if ( array_diff( array_keys( $row['meta'] ), $allowed ) || array_diff( $allowed, array_keys( $row['meta'] ) ) ) { return false; }
			foreach ( $row['meta'] as $value ) { if ( null !== $value && ! is_string( $value ) ) { return false; } }
			$expected[(int) $row['id']] = $type;
		}
		return self::validate_owned_ids( $expected );
	}

	/** Replacing a known baseline is an explicit administrator action while sharing is off. */
	public static function capture_baseline() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'my-family-tree' ) ); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$state = self::fresh_option( self::STATE_OPTION );
			if ( false === $state ) { return self::reset_error(); }
			if ( ! empty( $state['enabled'] ) ) { return new WP_Error( 'demo_enabled', __( 'Disable demo sharing before saving a new reset baseline.', 'my-family-tree' ) ); }
			$baseline = self::make_baseline();
			if ( is_wp_error( $baseline ) || ! self::valid_baseline( $baseline ) || ! self::owns_write_lock( $lock ) ) { return self::reset_error(); }
			update_option( self::BASELINE_OPTION, $baseline, false );
			return self::fresh_option( self::BASELINE_OPTION ) === $baseline ? true : self::reset_error();
		} finally { self::release_write_lock( $lock ); }
	}

	private static function same_demo_session( $before, $after ) {
		return is_array( $after ) && ! empty( $after['enabled'] ) && empty( $after['quarantined'] ) && ! empty( $before['generation'] ) && isset( $after['generation'] ) && hash_equals( (string) $before['generation'], (string) $after['generation'] );
	}

	private static function schedule_reset( $timestamp ) {
		if ( (int) wp_next_scheduled( self::RESET_HOOK ) === (int) $timestamp ) { return; }
		wp_clear_scheduled_hook( self::RESET_HOOK );
		wp_schedule_single_event( max( time() + 1, (int) $timestamp ), self::RESET_HOOK );
	}

	public static function prepare_reset() {
		$state = self::state();
		if ( ! empty( $state['enabled'] ) && empty( $state['quarantined'] ) ) { self::active(); }
	}

	public static function scheduled_reset() { self::reset_if_due(); }

	/** A late WP-Cron run cannot serve expired visitor edits: access also checks this deadline. */
	private static function reset_if_due( $force = false ) {
		if ( self::$reset_failed ) { return self::reset_error(); }
		$state = self::fresh_option( self::STATE_OPTION );
		if ( false === $state ) { return self::reset_error(); }
		if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) ) { wp_clear_scheduled_hook( self::RESET_HOOK ); return self::reset_error(); }
		$baseline = self::fresh_option( self::BASELINE_OPTION );
		if ( false === $baseline ) { return self::reset_error(); }
		$due = empty( $state['next_reset_at'] ) || empty( $state['reset_cycle'] ) || ! empty( $state['reset_pending'] ) || (int) $state['next_reset_at'] <= time();
		if ( ! $force && $baseline && ! $due ) { self::schedule_reset( $state['next_reset_at'] ); return true; }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		self::$resetting = true;
		try {
			$current = self::fresh_option( self::STATE_OPTION );
			if ( ! self::same_demo_session( $state, $current ) || self::real_family_count() ) { if ( ! empty( $current['enabled'] ) && self::real_family_count() ) { self::quarantine(); } return self::reset_error(); }
			$state = $current;
			$baseline = self::fresh_option( self::BASELINE_OPTION );
			if ( false === $baseline ) { return self::reset_error(); }
			if ( ! $baseline ) {
				// Upgrading an already-public 1.2.0 demo must not treat visitor edits as its original state.
				$baseline = self::make_baseline( true );
				if ( is_wp_error( $baseline ) ) { return self::reset_error(); }
				if ( ! self::owns_write_lock( $lock ) || ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) ) { return self::reset_error(); }
				update_option( self::BASELINE_OPTION, $baseline, false );
				if ( self::fresh_option( self::BASELINE_OPTION ) !== $baseline ) { return self::reset_error(); }
			}
			if ( ! self::valid_baseline( $baseline ) ) { self::quarantine(); return self::reset_error(); }
			if ( empty( $state['next_reset_at'] ) || empty( $state['reset_cycle'] ) ) {
				$updated = $state;
				$updated['next_reset_at'] = max( 1, (int) ( $state['enabled_at'] ?? time() ) ) + DAY_IN_SECONDS;
				$updated['reset_cycle'] = wp_generate_uuid4();
				if ( ! self::replace_state( $state, $updated ) ) { return self::reset_error(); }
				$state = $updated;
			}
			if ( ! $force && empty( $state['reset_pending'] ) && (int) $state['next_reset_at'] > time() ) { self::schedule_reset( $state['next_reset_at'] ); return true; }
			$pending = $state;
			$pending['reset_pending'] = 1;
			$pending['next_reset_at'] = time();
			$pending['reset_cycle'] = wp_generate_uuid4();
			if ( ! self::replace_state( $state, $pending ) ) { return self::reset_error(); }
			$state = $pending;
			foreach ( $baseline['items'] as $row ) {
				$current = self::fresh_option( self::STATE_OPTION );
				if ( ! self::same_demo_session( $state, $current ) || ! self::owns_write_lock( $lock ) || self::real_family_count() || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) ) { self::$reset_failed = true; return self::reset_error(); }
				self::$mutating_post = (int) $row['id'];
				$result = wp_update_post( wp_slash( array( 'ID' => $row['id'], 'post_title' => $row['title'], 'post_content' => $row['story'] ) ), true );
				self::$mutating_post = 0;
				if ( is_wp_error( $result ) || ! $result ) { self::$reset_failed = true; return self::reset_error(); }
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify authoritative stored fields before declaring a reset complete.
				$stored = $wpdb->get_row( $wpdb->prepare( "SELECT post_title,post_content FROM {$wpdb->posts} WHERE ID=%d", $row['id'] ), ARRAY_A );
				if ( ! is_array( $stored ) || $stored['post_title'] !== $row['title'] || $stored['post_content'] !== $row['story'] ) { self::$reset_failed = true; return self::reset_error(); }
				foreach ( $row['meta'] as $key => $value ) {
					if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) ) { self::$reset_failed = true; return self::reset_error(); }
					if ( null === $value ) { delete_post_meta( $row['id'], $key ); } else { update_post_meta( $row['id'], $key, wp_slash( $value ) ); }
					wp_cache_delete( $row['id'], 'post_meta' );
					if ( null === $value ? metadata_exists( 'post', $row['id'], $key ) : (string) get_post_meta( $row['id'], $key, true ) !== $value ) { self::$reset_failed = true; return self::reset_error(); }
				}
				$revisions = self::revision_ids( $row['id'] );
				if ( false === $revisions ) { self::$reset_failed = true; return self::reset_error(); }
				foreach ( array_diff( $revisions, array_map( 'absint', $row['revisions'] ) ) as $revision ) {
					if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $row['id'] => $row['type'] ) ) || ! wp_delete_post_revision( $revision ) ) { self::$reset_failed = true; return self::reset_error(); }
				}
			}
			$current = self::fresh_option( self::STATE_OPTION );
			if ( ! self::same_demo_session( $state, $current ) || ! self::owns_write_lock( $lock ) || self::real_family_count() || ! self::valid_baseline( $baseline ) ) { self::$reset_failed = true; return self::reset_error(); }
			$complete = $current;
			$complete['reset_pending'] = 0;
			$complete['last_reset_at'] = time();
			$complete['next_reset_at'] = time() + DAY_IN_SECONDS;
			if ( ! self::replace_state( $current, $complete ) ) { return self::reset_error(); }
			WPMFT_Data::reset_cache();
			self::schedule_reset( $complete['next_reset_at'] );
			return true;
		} finally {
			self::$mutating_post = 0;
			self::$resetting = false;
			self::release_write_lock( $lock );
		}
	}

	public static function reset_now() {
		return current_user_can( 'manage_options' ) ? self::reset_if_due( true ) : new WP_Error( 'permission', __( 'Access denied.', 'my-family-tree' ) );
	}

	/** Public saves/restores do not create a second retained copy of visitor edits. */
	public static function revision_count( $count, $post ) { return (int) $post->ID === self::$mutating_post ? 0 : $count; }

	/** A quarantined demonstration stays locked until an administrator disables it. */
	public static function guard_required() {
		$state = self::state();
		return ! empty( $state['enabled'] );
	}

	public static function is_demo_user( $id = 0 ) {
		$id = $id ? absint( $id ) : get_current_user_id();
		if ( ! $id ) { return false; }
		$user = get_userdata( $id );
		return $user && ( in_array( self::ROLE, (array) $user->roles, true ) || (string) get_user_meta( $id, '_wpmft_demo_account', true ) !== '' );
	}

	/** The manifest and marker must agree; a matching title or slug is never enough. */
	public static function is_demo_post( $id ) {
		$manifest = self::manifest();
		$ids = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		return ! empty( $manifest['token'] ) && in_array( absint( $id ), $ids, true ) && hash_equals( (string) $manifest['token'], (string) get_post_meta( $id, '_wpmft_demo', true ) );
	}

	/** These ownership checks intentionally ignore mode: callers capture their demo scope before work. */
	public static function allows_person( $id ) { return self::validate_owned_ids( array( absint( $id ) => 'post' ) ); }
	public static function allows_media( $id ) { return self::validate_owned_ids( array( absint( $id ) => 'attachment' ) ); }

	/** Validate the finished response, closing a concurrent real-record/media insertion window. */
	public static function validate_payload( $payload, $kind ) {
		if ( ! is_array( $payload ) ) { return false; }
		$expected = array();
		$collect = function ( $id, $type ) use ( &$expected ) {
			if ( ! ( is_int( $id ) || is_string( $id ) && ctype_digit( $id ) ) || (int) $id < 1 ) { return false; }
			$id = (int) $id;
			if ( isset( $expected[$id] ) && $expected[$id] !== $type ) { return false; }
			$expected[$id] = $type;
			return true;
		};
		$image = function ( $url ) use ( $collect ) {
			if ( '' === $url || null === $url ) { return true; }
			if ( ! is_string( $url ) ) { return false; }
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			if ( ! is_string( $query ) ) { return false; }
			parse_str( $query, $parts );
			return isset( $parts['action'], $parts['id'] ) && 'wpmft_media' === $parts['action'] && $collect( $parts['id'], 'attachment' );
		};
		$person = function ( $row ) use ( $collect, $image ) {
			return is_array( $row ) && isset( $row['id'] ) && $collect( $row['id'], 'post' ) && $image( $row['photo'] ?? '' );
		};
		if ( 'tree' === $kind ) {
			if ( ! isset( $payload['nodes'], $payload['edges'] ) || ! is_array( $payload['nodes'] ) || ! is_array( $payload['edges'] ) ) { return false; }
			if ( ! empty( $payload['root'] ) && ! $collect( $payload['root'], 'post' ) ) { return false; }
			foreach ( $payload['nodes'] ?? array() as $row ) { if ( ! $person( $row ) ) { return false; } }
			foreach ( $payload['edges'] ?? array() as $edge ) { if ( ! is_array( $edge ) || ! $collect( $edge['from'] ?? 0, 'post' ) || ! $collect( $edge['to'] ?? 0, 'post' ) ) { return false; } }
		} elseif ( 'search' === $kind ) {
			if ( ! isset( $payload['people'] ) || ! is_array( $payload['people'] ) ) { return false; }
			foreach ( $payload['people'] ?? array() as $row ) { if ( ! $person( $row ) ) { return false; } }
		} elseif ( 'person' === $kind ) {
			if ( ! $person( $payload ) ) { return false; }
			if ( isset( $payload['relations'] ) && ! is_array( $payload['relations'] ) || isset( $payload['gallery'] ) && ! is_array( $payload['gallery'] ) ) { return false; }
			foreach ( $payload['relations'] ?? array() as $rows ) { if ( ! is_array( $rows ) ) { return false; } foreach ( $rows as $row ) { if ( ! $person( $row ) ) { return false; } } }
			foreach ( $payload['gallery'] ?? array() as $row ) { if ( ! is_array( $row ) || ! $collect( $row['id'] ?? 0, 'attachment' ) || ! $image( $row['url'] ?? '' ) || ! $image( $row['thumb'] ?? '' ) ) { return false; } }
		} else { return false; }
		return self::validate_owned_ids( $expected );
	}

	/** Batch ownership verification bypasses post-meta caches and fails closed on database errors. */
	private static function validate_owned_ids( $expected ) {
		if ( ! $expected ) { return true; }
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $manifest ) ) { return false; }
		$owned = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		if ( empty( $manifest['token'] ) || array_diff( array_keys( $expected ), $owned ) || isset( $expected[0] ) ) { return false; }
		global $wpdb;
		$ids = implode( ',', array_map( 'absint', array_keys( $expected ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The dynamic list consists exclusively of validated positive integers.
		$sql = $wpdb->prepare( "SELECT DISTINCT p.ID,p.post_type FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} d ON d.post_id=p.ID WHERE p.ID IN ({$ids}) AND d.meta_key='_wpmft_demo' AND d.meta_value=%s", $manifest['token'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A fresh final response authorization check.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) !== count( $expected ) ) { return false; }
		foreach ( $rows as $row ) { if ( ! isset( $expected[(int) $row['ID']] ) || $expected[(int) $row['ID']] !== $row['post_type'] ) { return false; } }
		return true;
	}

	/** Fresh SQL includes unpublished and trashed family records, not just the cached graph. */
	public static function real_family_count() {
		global $wpdb;
		$manifest = self::fresh_option( self::MANIFEST_OPTION );
		if ( ! is_array( $manifest ) ) { return PHP_INT_MAX; }
		$ids = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		$categories = WPMFT_Data::categories() ?: array( 0 );
		$slots = implode( ',', array_fill( 0, count( $categories ), '%d' ) );
		$owned = $ids ? ' AND p.ID IN (' . implode( ',', $ids ) . ')' : ' AND 1=0';
		// The interpolated fragments contain only fixed %d placeholders and validated integer IDs.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='post' AND p.post_status<>'auto-draft' AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id=p.ID AND f.meta_key='_wpmft_person' AND f.meta_value='1') OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='category' AND tt.term_id IN ({$slots}))) AND NOT (EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id=p.ID AND d.meta_key='_wpmft_demo' AND d.meta_value=%s){$owned})", array_merge( $categories, array( (string) ( $manifest['token'] ?? '' ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- An access decision must not use the performance cache.
		$count = $wpdb->get_var( $sql );
		// Null or malformed results mean the isolation check failed, never an empty real tree.
		return is_scalar( $count ) && preg_match( '/^[0-9]+$/D', (string) $count ) ? (int) $count : PHP_INT_MAX;
	}

	public static function active() {
		$state = self::state();
		if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) ) { return false; }
		$manifest = self::manifest();
		$root = absint( $manifest['items']['marty'] ?? 0 );
		if ( ! $root || ! self::is_demo_post( $root ) || 'publish' !== get_post_status( $root ) || self::real_family_count() ) {
			self::quarantine();
			return false;
		}
		return self::$resetting || ! is_wp_error( self::reset_if_due() );
	}

	private static function quarantine() {
		if ( self::$quarantining ) { return; }
		self::$quarantining = true;
		try {
			$state = self::fresh_option( self::STATE_OPTION );
			if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) ) { return; }
			$updated = $state;
			$updated['quarantined'] = 1;
			$updated['generation'] = wp_generate_password( 32, false, false );
			if ( ! self::replace_state( $state, $updated ) ) { return; }
			wp_clear_scheduled_hook( self::RESET_HOOK );
			self::revoke_account( $updated, false );
		} finally { self::$quarantining = false; }
	}

	/** Separate cookie, generation and namespace prevent demo sessions becoming family sessions. */
	public static function front_authorized() {
		if ( ! self::active() ) { return false; }
		$state = self::state();
		if ( self::is_demo_user() && get_current_user_id() === absint( $state['user_id'] ?? 0 ) && current_user_can( self::CAP ) ) { return true; }
		$token = isset( $_COOKIE['wpmft_demo_session'] ) && is_string( $_COOKIE['wpmft_demo_session'] ) ? wp_unslash( $_COOKIE['wpmft_demo_session'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return false; }
		$session = get_transient( 'wpmft_demo_session_' . hash( 'sha256', $token ) );
		return is_array( $session ) && isset( $session['generation'], $session['expires'] ) && $session['expires'] > time() && hash_equals( (string) $state['generation'], (string) $session['generation'] );
	}

	public static function unlock( $password, $remember = false ) {
		if ( ! self::active() ) { return new WP_Error( 'demo_locked', __( 'Demo sharing is unavailable. Please contact the site administrator.', 'my-family-tree' ) ); }
		if ( ! WPMFT_Security::rate_limit( 'demo_unlock', 10, 15 * MINUTE_IN_SECONDS ) ) { return new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again in 15 minutes.', 'my-family-tree' ) ); }
		if ( ! is_string( $password ) || ! hash_equals( 'demo', $password ) ) { return new WP_Error( 'password', __( 'The demo password is incorrect.', 'my-family-tree' ) ); }
		self::logout();
		$token = bin2hex( random_bytes( 32 ) );
		$ttl = $remember ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
		$state = self::state();
		set_transient( 'wpmft_demo_session_' . hash( 'sha256', $token ), array( 'generation' => $state['generation'], 'expires' => time() + $ttl ), $ttl );
		WPMFT_Security::cookie( 'wpmft_demo_session', $token, $remember ? time() + $ttl : 0 );
		return true;
	}

	public static function logout() {
		$token = isset( $_COOKIE['wpmft_demo_session'] ) && is_string( $_COOKIE['wpmft_demo_session'] ) ? wp_unslash( $_COOKIE['wpmft_demo_session'] ) : '';
		if ( preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { delete_transient( 'wpmft_demo_session_' . hash( 'sha256', $token ) ); }
		if ( $token ) { WPMFT_Security::cookie( 'wpmft_demo_session', '', time() - HOUR_IN_SECONDS ); }
	}

	public static function public_config() {
		$active = self::active();
		$manifest = self::manifest();
		return array(
			'active' => $active, 'guarded' => self::guard_required(),
			'label' => $active ? __( 'Cinema family demo', 'my-family-tree' ) : '',
			'notice' => $active ? __( 'Fictional crossover characters and invented relationships. Visitor edits are public and reset every 24 hours.', 'my-family-tree' ) : '',
			'password' => $active ? 'demo' : '',
			'rootId' => $active ? absint( $manifest['items']['marty'] ?? 0 ) : 0,
			'treeUrl' => $active && ! empty( $manifest['items']['tree-page'] ) ? get_permalink( $manifest['items']['tree-page'] ) : '',
		);
	}

	/** Administrator-only additive import. Existing records, pages and settings are left intact. */
	public static function import() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Only an administrator can import demo content.', 'my-family-tree' ) ); }
		$lock = get_option( 'wpmft_demo_import_lock', 0 );
		if ( $lock && (int) $lock < time() - 10 * MINUTE_IN_SECONDS ) { delete_option( 'wpmft_demo_import_lock' ); }
		if ( ! add_option( 'wpmft_demo_import_lock', time(), '', false ) ) { return new WP_Error( 'busy', __( 'Another demo import is running. Please try again shortly.', 'my-family-tree' ) ); }
		$write_lock = self::acquire_write_lock();
		if ( ! $write_lock ) { delete_option( 'wpmft_demo_import_lock' ); return self::reset_error(); }
		try {
			$manifest = self::manifest();
			if ( empty( $manifest['token'] ) ) { $manifest = array( 'token' => wp_generate_uuid4(), 'items' => array(), 'created' => time() ); }
			update_option( self::MANIFEST_OPTION, $manifest, false );
			$people = self::people();
			$created = array();
			foreach ( $people as $key => $person ) {
				$existing = self::existing_item( $manifest, $key );
				if ( $existing ) {
					if ( ! self::is_demo_post( $existing ) ) { return new WP_Error( 'demo_changed', __( 'A previous demo record was repurposed. The importer stopped without changing it; restore its demo marker or use a separate demo site.', 'my-family-tree' ) ); }
					if ( ! get_post_meta( $existing, '_wpmft_demo_seeded', true ) ) { $created[$key] = $existing; }
					continue;
				}
				$content = self::person_content( $person );
				$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $person['name'], 'post_content' => $content, 'post_excerpt' => $person['bio'], 'comment_status' => 'closed', 'ping_status' => 'closed', 'meta_input' => array( '_wpmft_person' => '1', '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key ) ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				$manifest['items'][$key] = (int) $id;
				$created[$key] = (int) $id;
				update_option( self::MANIFEST_OPTION, $manifest, false );
				foreach ( array( 'gender' => $person['gender'], 'born' => $person['born'], '_wpmft_location' => $person['location'], '_wpmft_occupation' => $person['occupation'], '_wpmft_website' => 'https://example.com/', '_wpmft_social_instagram' => 'https://www.instagram.com/', '_wpmft_social_youtube' => 'https://www.youtube.com/' ) as $meta => $value ) { update_post_meta( $id, $meta, $value ); }
				if ( function_exists( 'pll_set_post_language' ) && in_array( 'en', (array) pll_languages_list(), true ) ) { pll_set_post_language( $id, 'en' ); }
			}
			// Only newly inserted people receive relationships. A later import preserves demo edits too.
			foreach ( $created as $key => $id ) {
				$person = $people[$key];
				foreach ( array( 'father', 'mother', 'spouse' ) as $relation ) { if ( ! empty( $person[$relation] ) ) { update_post_meta( $id, $relation, $manifest['items'][$person[$relation]] ); } }
				if ( ! empty( $person['spouse'] ) ) { update_post_meta( $id, '_wpmft_partner_status', array( $manifest['items'][$person['spouse']] => 'together' ) ); }
				$portrait = self::import_image( $manifest, 'portrait-' . $key, $key . '.png', $id, $person['name'] . ' — original demo monogram' );
				if ( is_wp_error( $portrait ) ) { return $portrait; }
				set_post_thumbnail( $id, $portrait );
			}
			$gallery = array();
			for ( $i = 1; $i <= 3; $i++ ) {
				$image = self::import_image( $manifest, 'album-' . $i, 'album-' . $i . '.png', $manifest['items']['marty'], 'Original fictional family album illustration ' . $i );
				if ( is_wp_error( $image ) ) { return $image; }
				$gallery[] = $image;
			}
			foreach ( $created as $id ) { update_post_meta( $id, '_wpmft_gallery', $gallery ); do_action( 'wpmft_person_saved', $id ); update_post_meta( $id, '_wpmft_demo_seeded', 1 ); }
			$pages = self::pages( $manifest );
			foreach ( $pages as $key => $page ) {
				if ( self::existing_item( $manifest, $key ) ) { continue; }
				$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_content' => $page['content'], 'comment_status' => 'closed', 'ping_status' => 'closed', 'meta_input' => array( '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key ) ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				$manifest['items'][$key] = (int) $id;
				update_option( self::MANIFEST_OPTION, $manifest, false );
				if ( function_exists( 'pll_set_post_language' ) && in_array( 'en', (array) pll_languages_list(), true ) ) { pll_set_post_language( $id, 'en' ); }
			}
			WPMFT_Data::reset_cache();
			return array( 'created_people' => count( $created ), 'people' => count( $people ), 'page_id' => $manifest['items']['tree-page'] );
		} finally { self::release_write_lock( $write_lock ); delete_option( 'wpmft_demo_import_lock' ); }
	}

	private static function existing_item( &$manifest, $key ) {
		$id = absint( $manifest['items'][$key] ?? 0 );
		if ( $id && get_post( $id ) ) {
			// A removed marker means the administrator repurposed the record: do not modify it.
			return $id;
		}
		return 0;
	}

	private static function import_image( &$manifest, $key, $filename, $parent, $alt ) {
		$existing = self::existing_item( $manifest, $key );
		if ( $existing ) { return $existing; }
		$file = WPMFT_DIR . 'assets/demo/' . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A bundled file from a fixed allowlist, never a remote or user-supplied path.
		$bytes = file_get_contents( $file );
		if ( false === $bytes ) { return new WP_Error( 'demo_image', __( 'A bundled demo illustration could not be read.', 'my-family-tree' ) ); }
		$upload = wp_upload_bits( 'wpmft-demo-' . $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) { return new WP_Error( 'demo_image', $upload['error'] ); }
		$id = wp_insert_attachment( array( 'post_title' => $alt, 'post_excerpt' => __( 'Original illustration for the fictional cinema family demo.', 'my-family-tree' ), 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_parent' => absint( $parent ), 'meta_input' => array( '_wpmft_demo' => $manifest['token'], '_wpmft_demo_key' => $key, '_wp_attachment_image_alt' => $alt ) ), $upload['file'], $parent, true );
		if ( is_wp_error( $id ) ) { wp_delete_file( $upload['file'] ); return $id; }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $upload['file'] );
		if ( is_array( $metadata ) ) { wp_update_attachment_metadata( $id, $metadata ); }
		$manifest['items'][$key] = (int) $id;
		update_option( self::MANIFEST_OPTION, $manifest, false );
		return (int) $id;
	}

	public static function enable() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Only an administrator can enable demo sharing.', 'my-family-tree' ) ); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$before_state = self::fresh_option( self::STATE_OPTION );
			if ( false === $before_state ) { return self::reset_error(); }
			if ( is_multisite() ) { return new WP_Error( 'multisite', __( 'Shared demo accounts are available on single-site installations only. Demo content can still be imported on this network.', 'my-family-tree' ) ); }
			$manifest = self::manifest();
			if ( empty( $manifest['items']['marty'] ) || ! self::is_demo_post( $manifest['items']['marty'] ) || 'publish' !== get_post_status( $manifest['items']['marty'] ) ) { return new WP_Error( 'demo_missing', __( 'Import the demo first and keep its starting person published.', 'my-family-tree' ) ); }
			if ( self::real_family_count() ) { return new WP_Error( 'real_content', __( 'Demo sharing is blocked because real family records exist, including unpublished or trashed records. Use a separate demo site.', 'my-family-tree' ) ); }
			if ( self::guard_required() ) { return new WP_Error( 'already_enabled', __( 'Disable the existing demo session before enabling it again.', 'my-family-tree' ) ); }
			if ( username_exists( 'demo' ) ) { return new WP_Error( 'username_exists', __( 'The username demo already exists. No existing account has been changed. Use a separate demo site or rename that account yourself.', 'my-family-tree' ) ); }
			$baseline = self::fresh_option( self::BASELINE_OPTION );
			if ( false === $baseline ) { return self::reset_error(); }
			if ( ! $baseline ) {
				$baseline = self::make_baseline();
				if ( is_wp_error( $baseline ) ) { return $baseline; }
				if ( ! self::owns_write_lock( $lock ) ) { return self::reset_error(); }
				update_option( self::BASELINE_OPTION, $baseline, false );
				if ( self::fresh_option( self::BASELINE_OPTION ) !== $baseline ) { return self::reset_error(); }
			}
			if ( ! self::valid_baseline( $baseline ) ) { return new WP_Error( 'demo_baseline', __( 'The saved demo baseline no longer matches the marked samples. Review the demo content and save a new baseline before sharing.', 'my-family-tree' ) ); }
			if ( ! self::owns_write_lock( $lock ) || self::fresh_option( self::STATE_OPTION ) !== $before_state ) { return self::reset_error(); }
			// No standard Editor capabilities: this public account can use only our constrained editor.
			remove_role( self::ROLE );
			add_role( self::ROLE, __( 'Family Tree Demo Editor', 'my-family-tree' ), array( 'read' => true, self::CAP => true ) );
			$id = wp_insert_user( array( 'user_login' => 'demo', 'user_pass' => 'Demo@demo123Editor', 'user_email' => '', 'display_name' => 'Cinema Demo Editor', 'role' => self::ROLE, 'show_admin_bar_front' => 'false' ) );
			if ( is_wp_error( $id ) ) { return $id; }
			$account_token = wp_generate_uuid4();
			update_user_meta( $id, '_wpmft_demo_account', $account_token );
			$new_state = array( 'enabled' => 1, 'quarantined' => 0, 'user_id' => (int) $id, 'account_token' => $account_token, 'generation' => wp_generate_password( 32, false, false ), 'owner_id' => get_current_user_id(), 'enabled_at' => time(), 'reset_cycle' => wp_generate_uuid4(), 'next_reset_at' => time() + DAY_IN_SECONDS, 'reset_pending' => 0, 'reset_version' => 1 );
			if ( ! self::owns_write_lock( $lock ) || self::fresh_option( self::STATE_OPTION ) !== $before_state || ! ( $before_state ? self::replace_state( $before_state, $new_state ) : add_option( self::STATE_OPTION, $new_state, '', false ) ) ) { self::revoke_account( $new_state, true ); return self::reset_error(); }
			self::$reset_failed = false;
			self::schedule_reset( time() + DAY_IN_SECONDS );
			return true;
		} finally { self::release_write_lock( $lock ); }
	}

	private static function revoke_account( $state, $delete ) {
		$id = absint( $state['user_id'] ?? 0 );
		$marker = $id ? (string) get_user_meta( $id, '_wpmft_demo_account', true ) : '';
		if ( ! $id || ! $marker || empty( $state['account_token'] ) || ! hash_equals( (string) $state['account_token'], $marker ) ) { return; }
		WP_Session_Tokens::get_instance( $id )->destroy_all();
		wp_set_password( wp_generate_password( 48, true, true ), $id );
		$user = get_userdata( $id );
		if ( $user ) { $user->set_role( '' ); }
		if ( $delete && ! is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$owner = absint( $state['owner_id'] ?? 0 );
			if ( $owner && $owner !== $id && get_userdata( $owner ) ) { wp_delete_user( $id, $owner ); }
		}
	}

	public static function disable() {
		$state = self::state();
		update_option( self::STATE_OPTION, array( 'enabled' => 0, 'generation' => wp_generate_password( 32, false, false ) ), false );
		wp_clear_scheduled_hook( self::RESET_HOOK );
		self::revoke_account( $state, true );
		remove_role( self::ROLE );
		self::logout();
		return true;
	}

	/** Deactivation revokes the public account; it never deletes demo posts or family data. */
	public static function deactivate() { self::disable(); }

	private static function require_admin( $action ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'A POST request is required.', 'my-family-tree' ), '', array( 'response' => 405 ) ); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
		if ( empty( $_POST['wpmft_demo_confirm'] ) || '1' !== $_POST['wpmft_demo_confirm'] ) { wp_die( esc_html__( 'Read and confirm the demo notice before continuing.', 'my-family-tree' ), '', array( 'response' => 400 ) ); }
	}

	private static function finish( $result, $message ) {
		set_transient( 'wpmft_demo_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=wpmft#wpmft-demo' ) );
		exit;
	}

	public static function handle_import() { self::require_admin( 'wpmft_demo_import' ); self::finish( self::import(), __( 'Demo content is ready. Existing content and settings were preserved. Sharing remains off until you explicitly enable it.', 'my-family-tree' ) ); }
	public static function handle_enable() { self::require_admin( 'wpmft_demo_enable' ); self::finish( self::enable(), __( 'Demo sharing is active. The shared editor can edit demo content only.', 'my-family-tree' ) ); }
	public static function handle_disable() { self::require_admin( 'wpmft_demo_disable' ); self::finish( self::disable(), __( 'Demo sharing is off. Its sessions and managed account have been revoked. Demo content is retained.', 'my-family-tree' ) ); }
	public static function handle_reset() { self::require_admin( 'wpmft_demo_reset' ); self::finish( self::reset_now(), __( 'Demo visitor edits have been reset. The next reset is due in 24 hours.', 'my-family-tree' ) ); }
	public static function handle_baseline() { self::require_admin( 'wpmft_demo_baseline' ); self::finish( self::capture_baseline(), __( 'The current demo titles, stories, dates, locations and occupations are saved as the reset baseline.', 'my-family-tree' ) ); }

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$notice = get_transient( 'wpmft_demo_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && isset( $notice['message'] ) ) {
			delete_transient( 'wpmft_demo_notice_' . get_current_user_id() );
			echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		if ( ! empty( self::state()['quarantined'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Family Tree demo sharing was locked because its content is no longer isolated. Shared access has been revoked. Disable demo mode in Family Tree settings before configuring normal family access.', 'my-family-tree' ) . '</p></div>'; }
	}

	/** Buttons can live in the settings form; each targets its own external action form. */
	public static function render_panel() {
		$manifest = self::manifest();
		$active = self::active();
		$guarded = self::guard_required();
		?>
		<h2><?php esc_html_e( 'Demo content & sharing', 'my-family-tree' ); ?></h2>
		<p><?php esc_html_e( 'Add eight fictional cinema characters, original gallery illustrations and two English pages. The importer adds marked sample content and preserves existing records, pages and settings. Repeating it keeps edits to existing demo items.', 'my-family-tree' ); ?></p>
		<p><?php esc_html_e( 'All family connections and dates are invented. Character names are illustrative references; no movie stills, celebrity photographs or official character social accounts are included.', 'my-family-tree' ); ?></p>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-import" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'I understand that demo people will be added alongside existing content. This does not enable public demo access.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-import" class="button button-secondary"><?php esc_html_e( 'Import demo content', 'my-family-tree' ); ?></button></p>
		<?php if ( ! empty( $manifest['items']['tree-page'] ) && get_post( $manifest['items']['tree-page'] ) ) : ?>
		<p><a class="button" href="<?php echo esc_url( get_permalink( $manifest['items']['tree-page'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open demo tree page', 'my-family-tree' ); ?></a></p>
		<?php endif; ?>
		<hr>
		<h3><?php esc_html_e( 'Optional shared demo mode', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'Use this on a separate demonstration site containing only demo family records. A restricted Family Tree Demo Editor can change marked sample names, dates and biographies, but cannot manage settings, other content, users, plugins or uploads. Contact sending is disabled during demo mode. Visitor edits are public and reset every 24 hours.', 'my-family-tree' ); ?></p>
		<?php if ( $active ) : ?>
		<p><strong><?php esc_html_e( 'Demo mode is active.', 'my-family-tree' ); ?></strong></p>
		<dl><dt><?php esc_html_e( 'WordPress demo login', 'my-family-tree' ); ?></dt><dd><code>demo</code></dd><dt><?php esc_html_e( 'WordPress demo password', 'my-family-tree' ); ?></dt><dd><code>Demo@demo123Editor</code></dd><dt><?php esc_html_e( 'Front-end demo password', 'my-family-tree' ); ?></dt><dd><code>demo</code></dd></dl>
		<?php elseif ( $guarded ) : ?>
		<p><strong><?php esc_html_e( 'Demo sharing is locked. Its isolation check failed; shared sessions have been revoked.', 'my-family-tree' ); ?></strong></p>
		<?php else : ?>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-enable" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'I understand that this creates a shared public demo account with a published password, on an isolated demo tree only.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-enable" class="button"><?php esc_html_e( 'Enable shared demo mode', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<?php if ( $guarded ) : ?>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-disable" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Revoke the shared account and demo sessions, keep demo content, and restore the normal Privacy settings.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-disable" class="button"><?php esc_html_e( 'Disable demo mode', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<hr>
		<h3><?php esc_html_e( 'Automatic demo reset', 'my-family-tree' ); ?></h3>
		<p><?php esc_html_e( 'The plugin restores demo titles, stories, birth dates, locations and occupations to a saved starting state every 24 hours. Settings, relationships, media and unrelated content are kept. No backup plugin is required.', 'my-family-tree' ); ?></p>
		<p class="description"><?php esc_html_e( 'WordPress runs scheduled tasks when the site receives a request. An overdue reset also runs before the next demo visit or save. An editor opened before a reset must be reloaded before saving.', 'my-family-tree' ); ?></p>
		<?php if ( $active ) : $reset_state = self::state(); ?>
		<p><strong><?php esc_html_e( 'Next reset:', 'my-family-tree' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $reset_state['next_reset_at'] ) ); ?></p>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-reset" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Restore the saved demo starting state now and discard current visitor edits.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-reset" class="button"><?php esc_html_e( 'Reset demo now', 'my-family-tree' ); ?></button></p>
		<?php elseif ( ! $guarded && ! empty( $manifest['items']['marty'] ) ) : ?>
		<p><?php esc_html_e( 'The first activation saves the current demo as its starting state. That baseline is retained when sharing is disabled and enabled again. To deliberately change it, finish your demo edits while sharing is off, then save a new baseline below.', 'my-family-tree' ); ?></p>
		<label class="wpmft-check"><input type="checkbox" form="wpmft-demo-baseline" name="wpmft_demo_confirm" value="1" required> <span><?php esc_html_e( 'Use the current demo titles, stories, dates, locations and occupations for future resets, replacing the previous baseline.', 'my-family-tree' ); ?></span></label>
		<p><button type="submit" form="wpmft-demo-baseline" class="button"><?php esc_html_e( 'Save current demo as reset baseline', 'my-family-tree' ); ?></button></p>
		<?php endif; ?>
		<?php
	}

	public static function render_forms() {
		foreach ( array( 'import', 'enable', 'disable', 'reset', 'baseline' ) as $action ) {
			echo '<form id="wpmft-demo-' . esc_attr( $action ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="wpmft_demo_' . esc_attr( $action ) . '">';
			wp_nonce_field( 'wpmft_demo_' . $action );
			echo '</form>';
		}
	}

	public static function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_wpmft', 'toplevel_page_wpmft-demo-editor' ), true ) ) { return; }
		wp_enqueue_script( 'wpmft-demo-admin', wpmft_asset_url( 'demo-admin.js' ), array(), wpmft_asset_version(), true );
		wp_enqueue_style( 'wpmft-demo-admin', wpmft_asset_url( 'demo-admin.css' ), array(), wpmft_asset_version() );
		wp_localize_script( 'wpmft-demo-admin', 'WPMFT_DEMO_ADMIN', array(
			'import' => __( 'Add fictional demo people, gallery illustrations and English pages? Existing content will be kept. Importing does not enable shared demo access.', 'my-family-tree' ),
			'enable' => __( 'Enable a public shared demo login? Anyone given its published credentials can edit the marked sample stories. This requires an isolated demo tree.', 'my-family-tree' ),
			'disable' => __( 'Revoke the shared account and all demo sessions? Demo content will be kept and the normal Privacy settings will apply again.', 'my-family-tree' ),
			'reset' => __( 'Discard current visitor edits and restore the saved demo starting state? Other site content and settings will be kept.', 'my-family-tree' ),
			'baseline' => __( 'Replace the saved demo starting state with the current sample content? Future daily resets will restore these values.', 'my-family-tree' ),
		) );
	}

	/** No primitive WordPress editing capability is granted to the shared account. */
	public static function map_capability( $caps, $cap, $user_id, $args ) {
		if ( ! self::is_demo_user( $user_id ) ) { return $caps; }
		if ( in_array( $cap, array( 'edit_post', 'read_post', 'delete_post', 'edit_page', 'read_page', 'delete_page' ), true ) ) {
			$id = absint( $args[0] ?? 0 );
			return self::active() && self::is_demo_post( $id ) && in_array( $cap, array( 'edit_post', 'read_post', 'edit_page', 'read_page' ), true ) && ( 'read_post' === $cap || 'read_page' === $cap || in_array( get_post_type( $id ), array( 'post', 'page' ), true ) ) ? array( self::CAP ) : array( 'do_not_allow' );
		}
		if ( in_array( $cap, array( 'edit_post_meta', 'add_post_meta', 'delete_post_meta', 'edit_user', 'delete_user', 'promote_user', 'remove_user', 'create_users', 'edit_users' ), true ) ) { return array( 'do_not_allow' ); }
		return $caps;
	}

	public static function edit_link( $link, $id, $context ) {
		return self::is_demo_user() && self::active() && self::is_demo_post( $id ) ? add_query_arg( array( 'page' => 'wpmft-demo-editor', 'person' => absint( $id ) ), admin_url( 'admin.php' ) ) : $link;
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		return $user instanceof WP_User && self::is_demo_user( $user->ID ) ? admin_url( 'admin.php?page=wpmft-demo-editor' ) : $redirect;
	}

	public static function authenticate( $user, $password ) {
		if ( $user instanceof WP_User && self::is_demo_user( $user->ID ) && ! self::active() ) { return new WP_Error( 'demo_disabled', __( 'Demo sharing is not active.', 'my-family-tree' ) ); }
		return $user;
	}

	public static function application_passwords( $available, $user ) { return $user instanceof WP_User && self::is_demo_user( $user->ID ) ? false : $available; }
	public static function allow_password_reset( $allow, $user_id ) { return self::is_demo_user( $user_id ) ? false : $allow; }
	public static function guard_xmlrpc( $enabled ) { return self::guard_required() ? false : $enabled; }
	public static function guard_rest( $result, $server, $request ) { return self::is_demo_user() ? new WP_Error( 'demo_rest', __( 'Use the restricted demo editor for sample content.', 'my-family-tree' ), array( 'status' => 403 ) ) : $result; }

	public static function restrict_queries( $where, $query ) {
		if ( ! self::is_demo_user() ) { return $where; }
		global $wpdb;
		$manifest = self::manifest();
		$ids = array_map( 'absint', array_values( $manifest['items'] ?? array() ) );
		return $where . ( self::active() && $ids ? " AND {$wpdb->posts}.ID IN (" . implode( ',', $ids ) . ')' : ' AND 1=0' );
	}

	public static function guard_admin() {
		if ( ! self::is_demo_user() ) { return; }
		WPMFT_Security::no_cache();
		if ( ! self::active() || ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Demo sharing is not active.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( wp_doing_ajax() ) {
			if ( in_array( $action, array( 'wpmft_api', 'wpmft_media' ), true ) ) { return; }
			wp_send_json_error( array( 'message' => __( 'This action is not available to the demo editor.', 'my-family-tree' ) ), 403 );
		}
		if ( 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'wpmft_demo_save' === $action ) { return; }
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'wpmft-demo-editor' === $page ) { return; }
		wp_safe_redirect( admin_url( 'admin.php?page=wpmft-demo-editor' ) );
		exit;
	}

	public static function editor_menu() {
		if ( ! self::is_demo_user() ) { return; }
		global $menu;
		$menu = array();
		add_menu_page( __( 'Family Tree Demo', 'my-family-tree' ), __( 'Family Tree Demo', 'my-family-tree' ), self::CAP, 'wpmft-demo-editor', array( __CLASS__, 'editor_page' ), 'dashicons-networking', 2 );
	}

	public static function editor_page() {
		if ( ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { return; }
		$manifest = self::manifest();
		$id = isset( $_GET['person'] ) && is_scalar( $_GET['person'] ) ? absint( $_GET['person'] ) : 0;
		$post = $id && self::is_demo_post( $id ) ? get_post( $id ) : null;
		echo '<div class="wrap wpmft-demo-editor"><h1>' . esc_html__( 'Cinema Family Demo Editor', 'my-family-tree' ) . '</h1><p>' . esc_html__( 'You can edit marked demonstration stories only. Visitor edits are public and reset every 24 hours. Do not enter personal or confidential information.', 'my-family-tree' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( get_permalink( $manifest['items']['tree-page'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open demo tree', 'my-family-tree' ) . '</a> <a class="button" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'my-family-tree' ) . '</a></p>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Demo story saved.', 'my-family-tree' ) . '</p></div>'; }
		if ( $post && in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="wpmft_demo_save"><input type="hidden" name="person" value="' . absint( $id ) . '">';
			wp_nonce_field( 'wpmft_demo_save_' . $id );
			echo '<input type="hidden" name="wpmft_demo_reset_cycle" value="' . esc_attr( self::state()['reset_cycle'] ?? '' ) . '">';
			echo '<table class="form-table"><tr><th><label for="wpmft-demo-title">' . esc_html__( 'Name / title', 'my-family-tree' ) . '</label></th><td><input class="large-text" id="wpmft-demo-title" name="demo_title" value="' . esc_attr( $post->post_title ) . '" maxlength="160" required></td></tr>';
			if ( 'post' === $post->post_type ) {
				foreach ( array( 'born' => __( 'Birth date (YYYY, YYYY-MM or YYYY-MM-DD)', 'my-family-tree' ), '_wpmft_location' => __( 'Location', 'my-family-tree' ), '_wpmft_occupation' => __( 'Occupation', 'my-family-tree' ) ) as $key => $label ) {
					echo '<tr><th><label for="demo-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="demo-' . esc_attr( $key ) . '" name="demo_meta[' . esc_attr( $key ) . ']" value="' . esc_attr( get_post_meta( $id, $key, true ) ) . '" maxlength="160"></td></tr>';
				}
			}
			echo '<tr><th><label for="wpmft-demo-story">' . esc_html__( 'Story (basic HTML allowed)', 'my-family-tree' ) . '</label></th><td><textarea class="large-text" rows="16" id="wpmft-demo-story" name="demo_story" maxlength="12000">' . esc_textarea( $post->post_content ) . '</textarea></td></tr></table>';
			submit_button( __( 'Save demo story', 'my-family-tree' ) );
			echo '</form>';
		}
		echo '<h2>' . esc_html__( 'Sample people & pages', 'my-family-tree' ) . '</h2><ul>';
		foreach ( $manifest['items'] ?? array() as $item ) {
			$sample = get_post( $item );
			if ( ! $sample || ! self::is_demo_post( $item ) || ! in_array( $sample->post_type, array( 'post', 'page' ), true ) ) { continue; }
			echo '<li><a href="' . esc_url( add_query_arg( array( 'page' => 'wpmft-demo-editor', 'person' => absint( $item ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $sample->post_title ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	public static function handle_save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Access denied.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		$id = isset( $_POST['person'] ) && is_scalar( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		check_admin_referer( 'wpmft_demo_save_' . $id );
		if ( ! self::is_demo_post( $id ) || ! in_array( get_post_type( $id ), array( 'post', 'page' ), true ) ) { wp_die( esc_html__( 'This is not editable demo content.', 'my-family-tree' ), '', array( 'response' => 403 ) ); }
		$title = isset( $_POST['demo_title'] ) && is_string( $_POST['demo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['demo_title'] ) ) : '';
		$story = isset( $_POST['demo_story'] ) && is_string( $_POST['demo_story'] ) ? wp_unslash( $_POST['demo_story'] ) : '';
		if ( '' === $title || strlen( $title ) > 640 || strlen( $story ) > 48000 ) { wp_die( esc_html__( 'Enter a title and a story within the displayed length limits.', 'my-family-tree' ), '', array( 'response' => 400 ) ); }
		// Shortcodes and dynamic blocks are deliberately unavailable to shared demo editors.
		$story = preg_replace( '/<!--.*?-->/s', '', $story );
		$story = preg_replace( '/\[[^\]]*\]/', '', $story );
		$story = wp_kses_post( $story );
		$manifest = self::manifest();
		if ( $id === absint( $manifest['items']['tree-page'] ?? 0 ) ) { $story = '[wp_my_family_tree root="' . absint( $manifest['items']['marty'] ?? 0 ) . '"]'; }
		$meta = isset( $_POST['demo_meta'] ) && is_array( $_POST['demo_meta'] ) ? wp_unslash( $_POST['demo_meta'] ) : array();
		$cycle = isset( $_POST['wpmft_demo_reset_cycle'] ) && is_string( $_POST['wpmft_demo_reset_cycle'] ) ? sanitize_text_field( wp_unslash( $_POST['wpmft_demo_reset_cycle'] ) ) : '';
		$result = self::save_changes( $id, $title, $story, $meta, $cycle );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmft-demo-editor', 'person' => $id, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The nonce and input sanitization are checked by handle_save before this locked write. */
	private static function save_changes( $id, $title, $story, $meta, $cycle ) {
		if ( ! self::is_demo_user() || ! self::active() || ! current_user_can( self::CAP ) ) { return self::reset_error(); }
		$lock = self::acquire_write_lock();
		if ( ! $lock ) { return self::reset_error(); }
		try {
			$state = self::fresh_option( self::STATE_OPTION );
			$type = get_post_type( $id );
			if ( empty( $state['enabled'] ) || ! empty( $state['quarantined'] ) || ! empty( $state['reset_pending'] ) || (int) ( $state['next_reset_at'] ?? 0 ) <= time() || ! self::owns_write_lock( $lock ) || ! in_array( $type, array( 'post', 'page' ), true ) || self::real_family_count() || ! self::validate_owned_ids( array( $id => $type ) ) ) { return self::reset_error(); }
			if ( ! is_string( $cycle ) || empty( $state['reset_cycle'] ) || ! hash_equals( (string) $state['reset_cycle'], $cycle ) ) { return new WP_Error( 'demo_stale', __( 'The demo has reset since this editor was opened. Reload the editor before saving again.', 'my-family-tree' ) ); }
			self::$mutating_post = (int) $id;
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $title, 'post_content' => $story ) ), true );
			self::$mutating_post = 0;
			if ( is_wp_error( $result ) || ! $result ) { return self::reset_error(); }
			if ( 'post' === $type ) {
				foreach ( array( 'born', '_wpmft_location', '_wpmft_occupation' ) as $key ) {
					if ( ! isset( $meta[$key] ) || ! is_string( $meta[$key] ) || strlen( $meta[$key] ) > 640 ) { continue; }
					if ( ! self::same_demo_session( $state, self::fresh_option( self::STATE_OPTION ) ) || ! self::owns_write_lock( $lock ) || ! self::validate_owned_ids( array( $id => $type ) ) ) { return self::reset_error(); }
					$value = sanitize_text_field( $meta[$key] );
					if ( 'born' === $key && ! self::valid_date( $value ) ) { continue; }
					update_post_meta( $id, $key, wp_slash( $value ) );
				}
			}
			WPMFT_Data::reset_cache();
			return true;
		} finally { self::$mutating_post = 0; self::release_write_lock( $lock ); }
	}

	private static function valid_date( $value ) {
		if ( '' === $value ) { return true; }
		if ( ! preg_match( '/^([0-9]{4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?$/D', $value, $parts ) ) { return false; }
		return (int) $parts[1] > 0 && checkdate( isset( $parts[2] ) ? (int) $parts[2] : 1, isset( $parts[3] ) ? (int) $parts[3] : 1, (int) $parts[1] );
	}

	private static function person_content( $person ) {
		return '<p><strong>Fictional cinema crossover demo.</strong> Names are inspired by familiar screen characters. Dates, places and all family links here are invented to demonstrate the plugin, not biographies or film canon.</p><p>' . esc_html( $person['bio'] ) . '</p><h2>A little more about me</h2><p>' . esc_html( $person['story'] ) . '</p><p>Explore the tree, open a relative and swipe through the original sample illustrations in the gallery.</p>';
	}

	private static function pages( $manifest ) {
		return array(
			'tree-page' => array( 'title' => 'Cinema Family — Interactive Demo', 'content' => '[wp_my_family_tree root="' . absint( $manifest['items']['marty'] ?? 0 ) . '"]' ),
			'about-page' => array( 'title' => 'Meet the Cinema Family', 'content' => '<!-- wp:heading --><h2 class="wp-block-heading">Every character has a story</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Welcome to an imaginary family reunion inspired by beloved American and European screen characters. From time-travel adventures to Parisian daydreams, this friendly sample shows how biographies, relationships and photographs come together.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><strong>This is fictional demonstration content.</strong> Every date and family link is invented. The original monograms and landscape illustrations are supplied with this plugin; no movie stills, celebrity photographs or private family details are included.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Try the experience</h2><!-- /wp:heading --><!-- wp:list --><ul class="wp-block-list"><li>Choose a person and explore their relatives.</li><li>Open a biography and swipe through the sample gallery.</li><li>Use fullscreen to focus on the tree, then export a keepsake.</li></ul><!-- /wp:list --><!-- wp:paragraph --><p>Sample social buttons open the social networks themselves and do not claim to be character accounts.</p><!-- /wp:paragraph -->' ),
		);
	}

	private static function people() {
		return array(
			'george' => array( 'name' => 'George McFly', 'gender' => 'm', 'born' => '1940-04-01', 'location' => 'Hill Valley — fictional', 'occupation' => 'Story collector', 'spouse' => 'lorraine', 'bio' => 'A gentle daydreamer who turns ordinary family moments into extraordinary stories.', 'story' => 'George keeps the family notebook, asks thoughtful questions and believes every generation deserves to have its adventures remembered.' ),
			'lorraine' => array( 'name' => 'Lorraine Baines McFly', 'gender' => 'f', 'born' => '1941-05-12', 'location' => 'Hill Valley — fictional', 'occupation' => 'Community organiser', 'spouse' => 'george', 'bio' => 'The lively organiser of movie nights, long lunches and unexpected family reunions.', 'story' => 'Lorraine brings everyone around the table. Her favourite tradition is asking each guest to share one small memory worth keeping.' ),
			'marty' => array( 'name' => 'Marty McFly', 'gender' => 'm', 'born' => '1968-06-09', 'location' => 'Hill Valley — fictional', 'occupation' => 'Musician and adventurer', 'father' => 'george', 'mother' => 'lorraine', 'spouse' => 'jennifer', 'bio' => 'A guitar-loving explorer with a soft spot for family stories and improbable adventures.', 'story' => 'In this imaginary crossover family, Marty connects generations through music, curiosity and a carefully labelled box of old keepsakes.' ),
			'jennifer' => array( 'name' => 'Jennifer Parker', 'gender' => 'f', 'born' => '1969-02-14', 'location' => 'Hill Valley — fictional', 'occupation' => 'Travel journal editor', 'spouse' => 'marty', 'bio' => 'A thoughtful traveller who makes room in every itinerary for the people who matter.', 'story' => 'Jennifer collects postcards, sketches memorable places and encourages the family to notice the little things on every journey.' ),
			'amelie' => array( 'name' => 'Amélie Poulain', 'gender' => 'f', 'born' => '1990-08-23', 'location' => 'Paris — fictional demo address', 'occupation' => 'Collector of everyday wonders', 'father' => 'marty', 'mother' => 'jennifer', 'spouse' => 'nino', 'bio' => 'A Parisian daydreamer who finds wonder in quiet streets, warm cafés and small acts of kindness.', 'story' => 'Amélie turns a family album into a treasure hunt. Every photograph comes with a clue, a kind memory and a reason to call an old friend.' ),
			'nino' => array( 'name' => 'Nino Quincampoix', 'gender' => 'm', 'born' => '1989-03-18', 'location' => 'Paris — fictional demo address', 'occupation' => 'Picture-book maker', 'spouse' => 'amelie', 'bio' => 'An inventive collector of pictures, patterns and stories waiting to be rediscovered.', 'story' => 'Nino assembles the family scrapbooks. He leaves a blank page at the end of every album because the next chapter is still being written.' ),
			'paddington' => array( 'name' => 'Paddington Brown', 'gender' => 'm', 'born' => '1994-11-07', 'location' => 'London — fictional demo address', 'occupation' => 'Neighbourhood kindness ambassador', 'father' => 'marty', 'mother' => 'jennifer', 'bio' => 'A good-hearted guest who arrives with a small suitcase, a big smile and marmalade for everyone.', 'story' => 'Paddington reminds this imaginary family that belonging starts with a friendly welcome. His gatherings are famous for handwritten invitations and excellent sandwiches.' ),
			'matilda' => array( 'name' => 'Matilda Wormwood', 'gender' => 'f', 'born' => '2012-09-15', 'location' => 'A very bookish village — fictional', 'occupation' => 'Young reader and storyteller', 'father' => 'nino', 'mother' => 'amelie', 'bio' => 'A curious young reader who knows that even the smallest person can have a remarkable story.', 'story' => 'Matilda is writing a family encyclopedia, one question at a time. Her favourite chapter is the one about how kindness can travel across generations.' ),
		);
	}
}
