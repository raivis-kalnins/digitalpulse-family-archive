<?php
/** Small, bounded, same-origin AJAX requests for the React application. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_API {
	public static function init() {
		add_action( 'wp_ajax_wpmft_api', array( __CLASS__, 'dispatch' ) );
		add_action( 'wp_ajax_nopriv_wpmft_api', array( __CLASS__, 'dispatch' ) );
	}
	private static function error( $error, $status = 400 ) {
		wp_send_json_error( array( 'message' => $error->get_error_message(), 'code' => $error->get_error_code() ), $status );
	}
	private static function value( $key, $default = '' ) { return isset( $_POST[$key] ) && is_scalar( $_POST[$key] ) ? wp_unslash( $_POST[$key] ) : $default; }
	public static function bootstrap() {
		$s = wpmft_settings(); $authorized = WPMFT_Security::authorized();
		$result = array(
			'authorized' => $authorized, 'requiresPassword' => (bool) $s['password_protection'],
			'configured' => ! empty( $s['password_hash'] ), 'csrf' => WPMFT_Security::csrf(),
			'rememberDays' => max( 1, min( 30, (int) $s['remember_days'] ) ), 'rootId' => (int) $s['root_id'],
			'settings' => wpmft_public_settings(),
			'hcaptcha' => array( 'siteKey' => $s['hcaptcha_enabled'] ? $s['hcaptcha_site_key'] : '', 'login' => (bool) ( $s['hcaptcha_enabled'] && $s['hcaptcha_login'] ), 'contact' => (bool) ( $s['hcaptcha_enabled'] && $s['hcaptcha_contact'] ) ),
		);
		if ( $authorized ) {
			$index = WPMFT_Data::index(); $families = array();
			foreach ( $index as $id => $record ) { if ( $record['children'] ) { $families[$id] = true; } }
			$result['stats'] = array( 'people' => count( $index ), 'families' => count( $families ) );
		}
		return $result;
	}
	public static function dispatch() {
		WPMFT_Security::no_cache();
		if ( ! wpmft_settings()['enabled'] ) { self::error( new WP_Error( 'disabled', __( 'The family tree is currently unavailable.', 'wp-my-family-tree' ) ), 503 ); }
		$op = sanitize_key( self::value( 'op' ) ); $lang = wpmft_language( self::value( 'lang' ) );
		$locale = wpmft_locale( $lang );
		switch_to_locale( $locale );
		unload_textdomain( 'wp-my-family-tree', true );
		if ( $lang === 'lv' ) { load_textdomain( 'wp-my-family-tree', WPMFT_DIR . 'languages/wp-my-family-tree-lv.mo' ); }
		if ( $op === 'bootstrap' ) {
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { self::error( new WP_Error( 'method', __( 'A POST request is required.', 'wp-my-family-tree' ) ), 405 ); }
			wp_send_json_success( self::bootstrap() );
		}
		$check = WPMFT_Security::check_request();
		if ( is_wp_error( $check ) ) { self::error( $check, 403 ); }
		if ( $op === 'unlock' ) {
			if ( ! WPMFT_Security::rate_limit( 'unlock_request', 15, 15 * MINUTE_IN_SECONDS ) ) { self::error( new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again in 15 minutes.', 'wp-my-family-tree' ) ), 429 ); }
			if ( strlen( self::value( 'password' ) ) > 4096 ) { self::error( new WP_Error( 'password', __( 'The password is too long.', 'wp-my-family-tree' ) ) ); }
			$check = WPMFT_Security::captcha( 'login' ); if ( is_wp_error( $check ) ) { self::error( $check ); }
			$check = WPMFT_Security::unlock( self::value( 'password' ), self::value( 'remember' ) === '1' ); if ( is_wp_error( $check ) ) { self::error( $check, $check->get_error_code() === 'rate_limit' ? 429 : 403 ); }
			wp_send_json_success( self::bootstrap() );
		}
		if ( $op === 'logout' ) { WPMFT_Security::logout(); wp_send_json_success( self::bootstrap() ); }
		if ( $op === 'contact' ) { self::contact(); }
		if ( ! WPMFT_Security::authorized() ) { self::error( new WP_Error( 'unauthorized', __( 'Your family session has ended. Please unlock the tree again.', 'wp-my-family-tree' ) ), 401 ); }
		if ( ! WPMFT_Security::rate_limit( 'browse', 600, MINUTE_IN_SECONDS ) ) { self::error( new WP_Error( 'rate_limit', __( 'Please wait a moment before trying again.', 'wp-my-family-tree' ) ), 429 ); }
		if ( $op === 'tree' ) { wp_send_json_success( WPMFT_Data::tree( absint( self::value( 'root', wpmft_settings()['root_id'] ) ), absint( self::value( 'depth', 2 ) ), $lang ) ); }
		if ( $op === 'person' ) {
			$person = WPMFT_Data::person( absint( self::value( 'id' ) ), $lang );
			if ( is_wp_error( $person ) ) { self::error( $person, 404 ); }
			wp_send_json_success( $person );
		}
		if ( $op === 'search' ) { wp_send_json_success( WPMFT_Data::search( substr( sanitize_text_field( self::value( 'q' ) ), 0, 200 ), absint( self::value( 'page', 1 ) ), $lang ) ); }
		self::error( new WP_Error( 'operation', __( 'This action is not available.', 'wp-my-family-tree' ) ), 404 );
	}
	private static function contact() {
		$s = wpmft_settings();
		if ( ! $s['contact_enabled'] || ! is_email( $s['contact_email'] ) ) { self::error( new WP_Error( 'contact_disabled', __( 'The contact form is not available.', 'wp-my-family-tree' ) ), 403 ); }
		if ( ! WPMFT_Security::rate_limit( 'contact', 5, HOUR_IN_SECONDS ) ) { self::error( new WP_Error( 'rate_limit', __( 'Too many messages. Please try again later.', 'wp-my-family-tree' ) ), 429 ); }
		$started = (int) self::value( 'started' );
		if ( self::value( 'website' ) !== '' || $started > time() - 3 || $started < time() - DAY_IN_SECONDS ) { self::error( new WP_Error( 'spam', __( 'Please wait a few seconds and submit the form again.', 'wp-my-family-tree' ) ) ); }
		$name = sanitize_text_field( self::value( 'name' ) ); $email = sanitize_email( self::value( 'email' ) ); $message = sanitize_textarea_field( self::value( 'message' ) );
		if ( ! $name || strlen( $name ) > 160 || ! is_email( $email ) || strlen( $email ) > 254 || strlen( $message ) < 10 || strlen( $message ) > 10000 ) { self::error( new WP_Error( 'fields', __( 'Enter your name, a valid email address, and a message of 10–10,000 characters.', 'wp-my-family-tree' ) ) ); }
		$check = WPMFT_Security::captcha( 'contact' ); if ( is_wp_error( $check ) ) { self::error( $check ); }
		$subject = __( 'Family tree contact', 'wp-my-family-tree' ) . ' — ' . sanitize_text_field( $s['title'] );
		$body = "Name: {$name}\nEmail: {$email}\n\n{$message}";
		$sent = wp_mail( $s['contact_email'], $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email ) );
		if ( ! $sent ) { self::error( new WP_Error( 'mail_failed', __( 'The message could not be sent. Please try again later or use the administrator contact details.', 'wp-my-family-tree' ) ), 503 ); }
		wp_send_json_success( array( 'message' => __( 'Thank you. Your message has been sent to the family administrator.', 'wp-my-family-tree' ) ) );
	}
}
