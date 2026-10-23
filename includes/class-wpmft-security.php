<?php
/** Visitor sessions, request validation, and protection of WordPress data routes. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Security {
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'protect_page' ), 0 );
		add_filter( 'posts_where', array( __CLASS__, 'exclude_private_posts' ), 30, 2 );
		add_filter( 'comments_clauses', array( __CLASS__, 'exclude_private_comments' ), 30, 2 );
		add_filter( 'comment_feed_where', array( __CLASS__, 'exclude_private_comment_feed' ), 30 );
		add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), 30, 2 );
		add_filter( 'pings_open', array( __CLASS__, 'comments_open' ), 30, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'protect_rest' ), 5, 3 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_filter( 'wpseo_sitemap_exclude_post_type', array( __CLASS__, 'exclude_sitemap_type' ), 10, 2 );
		add_filter( 'rank_math/sitemap/exclude_post_type', array( __CLASS__, 'exclude_sitemap_type' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'guard_content' ), -100 );
		add_filter( 'the_excerpt', array( __CLASS__, 'guard_content' ), -100 );
		add_filter( 'the_title', array( __CLASS__, 'guard_title' ), -100, 2 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'guard_oembed' ), 10, 2 );
		add_filter( 'xmlrpc_enabled', array( __CLASS__, 'xmlrpc' ) );
	}
	public static function protected_mode() { return WPMFT_Demo::guard_required() || ! empty( wpmft_settings()['password_protection'] ); }
	public static function no_cache() {
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEDB', 'DONOTCACHEOBJECT' ) as $constant ) { if ( ! defined( $constant ) ) { define( $constant, true ); } }
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0' );
			header( 'Pragma: no-cache' ); header( 'Expires: 0' );
			header( 'Vary: Cookie', false ); header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: same-origin' ); header( 'Cross-Origin-Resource-Policy: same-origin' );
			if ( self::protected_mode() ) { header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); }
		}
	}
	public static function cookie( $name, $value, $expires ) {
		setcookie( $name, $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		if ( $value === '' ) { unset( $_COOKIE[$name] ); } else { $_COOKIE[$name] = $value; }
	}
	public static function device() {
		$device = isset( $_COOKIE['wpmft_device'] ) && is_string( $_COOKIE['wpmft_device'] ) ? (string) wp_unslash( $_COOKIE['wpmft_device'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $device ) ) {
			$device = bin2hex( random_bytes( 32 ) ); self::cookie( 'wpmft_device', $device, time() + 30 * DAY_IN_SECONDS );
		}
		return $device;
	}
	public static function csrf() { return hash_hmac( 'sha256', 'wpmft-csrf|' . self::device(), wp_salt( 'nonce' ) ); }
	public static function check_request() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return new WP_Error( 'method', __( 'A POST request is required.', 'wp-my-family-tree' ) ); }
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : '';
		$origins = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$parts = wp_parse_url( $url );
			$origins[] = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		if ( $origin && ! in_array( $origin, $origins, true ) ) { return new WP_Error( 'origin', __( 'The request origin could not be verified.', 'wp-my-family-tree' ) ); }
		$token = isset( $_POST['csrf'] ) && is_string( $_POST['csrf'] ) ? (string) wp_unslash( $_POST['csrf'] ) : '';
		if ( strlen( $token ) !== 64 || ! hash_equals( self::csrf(), $token ) ) { return new WP_Error( 'csrf', __( 'Please refresh the page and try again.', 'wp-my-family-tree' ) ); }
		return true;
	}
	public static function authorized() {
		if ( WPMFT_Demo::is_demo_user() && ! WPMFT_Demo::active() ) { return false; }
		if ( wpmft_staff() ) { return true; }
		if ( WPMFT_Demo::guard_required() ) { return WPMFT_Demo::front_authorized(); }
		if ( ! self::protected_mode() ) { return true; }
		$token = isset( $_COOKIE['wpmft_session'] ) && is_string( $_COOKIE['wpmft_session'] ) ? (string) wp_unslash( $_COOKIE['wpmft_session'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return false; }
		$session = get_transient( 'wpmft_session_' . hash( 'sha256', $token ) );
		$s = wpmft_settings();
		return is_array( $session ) && isset( $session['version'], $session['expires'] ) && $session['expires'] > time() && hash_equals( (string) $s['session_version'], (string) $session['version'] ) && ! empty( $s['password_hash'] );
	}
	public static function rate_limit( $scope, $limit, $window ) {
		// REMOTE_ADDR is used deliberately: arbitrary forwarded headers must not bypass throttling.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'wpmft_rate_' . hash_hmac( 'sha256', $scope . '|' . $ip, wp_salt( 'auth' ) );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || $state['until'] <= time() ) { $state = array( 'count' => 0, 'until' => time() + $window ); }
		if ( $state['count'] >= $limit ) { return false; }
		$state['count']++;
		set_transient( $key, $state, max( 1, $state['until'] - time() ) );
		return true;
	}
	public static function unlock( $password, $remember ) {
		if ( ! self::rate_limit( 'unlock', 10, 15 * MINUTE_IN_SECONDS ) ) { return new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again in 15 minutes.', 'wp-my-family-tree' ) ); }
		if ( WPMFT_Demo::guard_required() ) { return WPMFT_Demo::unlock( $password, $remember ); }
		$s = wpmft_settings();
		if ( ! $s['password_hash'] || ! wp_check_password( $password, $s['password_hash'] ) ) { return new WP_Error( 'password', __( 'The password is incorrect or family access has not been configured.', 'wp-my-family-tree' ) ); }
		self::logout();
		$token = bin2hex( random_bytes( 32 ) );
		$ttl = $remember ? max( 1, min( 30, (int) $s['remember_days'] ) ) * DAY_IN_SECONDS : 12 * HOUR_IN_SECONDS;
		set_transient( 'wpmft_session_' . hash( 'sha256', $token ), array( 'version' => $s['session_version'], 'expires' => time() + $ttl ), $ttl );
		self::cookie( 'wpmft_session', $token, $remember ? time() + $ttl : 0 );
		return true;
	}
	public static function logout() {
		WPMFT_Demo::logout();
		$token = isset( $_COOKIE['wpmft_session'] ) && is_string( $_COOKIE['wpmft_session'] ) ? (string) wp_unslash( $_COOKIE['wpmft_session'] ) : '';
		if ( preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { delete_transient( 'wpmft_session_' . hash( 'sha256', $token ) ); }
		self::cookie( 'wpmft_session', '', time() - HOUR_IN_SECONDS );
	}
	public static function captcha( $scope ) {
		$s = wpmft_settings();
		if ( ! $s['hcaptcha_enabled'] || empty( $s['hcaptcha_' . $scope] ) ) { return true; }
		if ( ! $s['hcaptcha_site_key'] || ! $s['hcaptcha_secret'] ) { return new WP_Error( 'captcha_config', __( 'The security challenge is not configured. Please contact the site administrator.', 'wp-my-family-tree' ) ); }
		$token = isset( $_POST['h-captcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ) ) : '';
		if ( ! $token || strlen( $token ) > 8192 ) { return new WP_Error( 'captcha', __( 'Please complete the security challenge.', 'wp-my-family-tree' ) ); }
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$response = wp_remote_post( 'https://api.hcaptcha.com/siteverify', array( 'timeout' => 15, 'body' => array( 'secret' => $s['hcaptcha_secret'], 'sitekey' => $s['hcaptcha_site_key'], 'response' => $token, 'remoteip' => $ip ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'captcha_unavailable', __( 'The security challenge could not be verified. Please try again.', 'wp-my-family-tree' ) ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['success'] ) ) { return new WP_Error( 'captcha', __( 'The security challenge expired or failed. Please try again.', 'wp-my-family-tree' ) ); }
		return true;
	}
	public static function protect_page() {
		if ( is_singular( 'post' ) && wpmft_is_person( get_queried_object_id() ) ) {
			self::no_cache();
			wp_safe_redirect( add_query_arg( 'person', get_queried_object_id(), wpmft_tree_url() ), 302 ); exit;
		}
		if ( is_attachment() && WPMFT_Media::is_private( get_queried_object_id() ) && self::protected_mode() && ! wpmft_staff() ) {
			self::no_cache(); wp_safe_redirect( wpmft_tree_url(), 302 ); exit;
		}
		if ( wpmft_is_tree_page() ) { self::no_cache(); }
	}
	public static function exclude_private_posts( $where, $query ) {
		if ( ! self::protected_mode() || wpmft_staff() || $query->get( 'wpmft_internal' ) || ( is_admin() && ! wp_doing_ajax() ) ) { return $where; }
		global $wpdb;
		// Single person requests are allowed to reach our early redirect; content remains guarded.
		if ( $query->is_main_query() && $query->is_singular() && ! $query->is_feed() && ! defined( 'REST_REQUEST' ) ) { return $where; }
		$person = self::private_ids();
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) { $person = array_values( array_filter( $person, function ( $id ) { return ! current_user_can( 'edit_post', $id ); } ) ); }
		if ( $person ) { $where .= " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', $person ) . ')'; }
		return $where;
	}
	private static function private_ids() {
		static $ids;
		if ( null === $ids ) { $ids = array_values( array_unique( array_merge( WPMFT_Data::ids( false ), WPMFT_Media::private_ids() ) ) ); }
		return $ids;
	}
	public static function exclude_private_comments( $clauses, $query ) {
		if ( ! self::protected_mode() || wpmft_staff() ) { return $clauses; }
		global $wpdb;
		$ids = self::private_ids();
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) { $ids = array_values( array_filter( $ids, function ( $id ) { return ! current_user_can( 'edit_post', $id ); } ) ); }
		if ( $ids ) { $clauses['where'] .= " AND {$wpdb->comments}.comment_post_ID NOT IN (" . implode( ',', $ids ) . ')'; }
		return $clauses;
	}
	public static function comments_open( $open, $id ) {
		return self::protected_mode() && ! current_user_can( 'edit_post', $id ) && ( wpmft_is_person( $id ) || WPMFT_Media::is_private( $id ) ) ? false : $open;
	}
	public static function exclude_private_comment_feed( $where ) {
		if ( ! self::protected_mode() || wpmft_staff() ) { return $where; }
		global $wpdb;
		$ids = self::private_ids();
		if ( $ids ) { $where .= " AND {$wpdb->comments}.comment_post_ID NOT IN (" . implode( ',', $ids ) . ')'; }
		return $where;
	}
	public static function protect_rest( $result, $server, $request ) {
		if ( ! self::protected_mode() || wpmft_staff() ) { return $result; }
		$route = $request->get_route();
		if ( preg_match( '#^/wp/v2/(?:posts|media)/(\d+)(?:/|$)#', $route, $m ) && in_array( (int) $m[1], self::private_ids(), true ) && ! current_user_can( 'edit_post', (int) $m[1] ) ) {
			self::no_cache(); return new WP_Error( 'rest_not_found', __( 'Content not found.', 'wp-my-family-tree' ), array( 'status' => 404 ) );
		}
		if ( preg_match( '#^/wp/v2/comments/(\d+)(?:/|$)#', $route, $m ) ) {
			$comment = get_comment( (int) $m[1] );
			if ( $comment && in_array( (int) $comment->comment_post_ID, self::private_ids(), true ) && ! current_user_can( 'edit_post', $comment->comment_post_ID ) ) { self::no_cache(); return new WP_Error( 'rest_not_found', __( 'Content not found.', 'wp-my-family-tree' ), array( 'status' => 404 ) ); }
		}
		if ( strpos( $route, '/oembed/' ) !== false ) {
			$url = $request->get_param( 'url' ); $id = $url ? url_to_postid( $url ) : 0;
			if ( $id && in_array( (int) $id, self::private_ids(), true ) ) { return new WP_Error( 'rest_not_found', __( 'Content not found.', 'wp-my-family-tree' ), array( 'status' => 404 ) ); }
		}
		return $result;
	}
	public static function robots( $robots ) {
		if ( self::protected_mode() && ( wpmft_is_tree_page() || is_singular( 'post' ) && wpmft_is_person( get_queried_object_id() ) || is_attachment() && WPMFT_Media::is_private( get_queried_object_id() ) ) ) {
			$robots['noindex'] = true; $robots['nofollow'] = true; $robots['noarchive'] = true; unset( $robots['index'], $robots['follow'] );
		}
		return $robots;
	}
	public static function sitemap_args( $args, $type ) {
		if ( self::protected_mode() ) { $args['post__not_in'] = array_unique( array_merge( $args['post__not_in'] ?? array(), self::private_ids() ) ); }
		return $args;
	}
	public static function exclude_sitemap_type( $excluded, $type ) { return self::protected_mode() && in_array( $type, array( 'post', 'attachment' ), true ) ? true : $excluded; }
	public static function guard_content( $content ) {
		if ( self::protected_mode() && ! wpmft_staff() && wpmft_is_person( get_the_ID() ) && ! current_user_can( 'edit_post', get_the_ID() ) ) { return ''; }
		return $content;
	}
	public static function guard_title( $title, $id = 0 ) {
		if ( self::protected_mode() && ! wpmft_staff() && $id && ! current_user_can( 'edit_post', $id ) && ( wpmft_is_person( $id ) || WPMFT_Media::is_private( $id ) ) ) { return __( 'Private family record', 'wp-my-family-tree' ); }
		return $title;
	}
	public static function guard_oembed( $data, $post ) { return self::protected_mode() && ( wpmft_is_person( $post->ID ) || WPMFT_Media::is_private( $post->ID ) ) ? array() : $data; }
	public static function xmlrpc( $enabled ) { return self::protected_mode() ? false : $enabled; }
}
