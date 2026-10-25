<?php
/** Small, bounded, same-origin AJAX requests for the React application. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_API {
	public static function init() {
		add_action( 'wp_ajax_wpmft_api', array( __CLASS__, 'dispatch' ) );
		add_action( 'wp_ajax_nopriv_wpmft_api', array( __CLASS__, 'dispatch' ) );
	}
	private static function error( $error, $status = 400 ) {
		// Even an early CSRF/spam rejection must leave no uploaded photo behind.
		if ( ! empty( $_FILES ) ) { WPMFT_Contact_Upload::cleanup(); }
		wp_send_json_error( array( 'message' => $error->get_error_message(), 'code' => $error->get_error_code() ), $status );
	}
	/** A captured demo scope remains restricted even if an administrator changes mode mid-request. */
	private static function send_family_data( $payload, $kind ) {
		if ( WPMFT_Demo::scoped_request() && ( ! WPMFT_Demo::active() || ! WPMFT_Demo::validate_payload( $payload, $kind ) ) ) {
			self::error( new WP_Error( 'unauthorized', __( 'Your family session has ended. Please unlock the tree again.', 'my-family-tree' ) ), 401 );
		}
		wp_send_json_success( $payload );
	}
	private static function value( $key, $default = '' ) {
		// Raw scalar input is unslashed here and sanitized by the operation-specific caller.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- CSRF is checked centrally before protected operations.
		return isset( $_POST[$key] ) && is_scalar( $_POST[$key] ) ? wp_unslash( $_POST[$key] ) : $default;
	}
	public static function bootstrap( $lang = '' ) {
		$s = wpmft_settings(); $authorized = WPMFT_Security::authorized();
		$demo = WPMFT_Demo::public_config();
		$result = array(
			'authorized' => $authorized, 'requiresPassword' => WPMFT_Security::protected_mode(),
			'configured' => WPMFT_Demo::guard_required() ? WPMFT_Demo::active() : ! empty( $s['password_hash'] ), 'csrf' => WPMFT_Security::csrf(),
			'rememberDays' => max( 1, min( 30, (int) $s['remember_days'] ) ), 'rootId' => ! empty( $demo['active'] ) ? (int) $demo['rootId'] : (int) $s['root_id'],
			'settings' => wpmft_public_settings(),
			'hcaptcha' => array( 'siteKey' => $s['hcaptcha_enabled'] ? $s['hcaptcha_site_key'] : '', 'login' => (bool) ( $s['hcaptcha_enabled'] && $s['hcaptcha_login'] ), 'contact' => (bool) ( $s['hcaptcha_enabled'] && $s['hcaptcha_contact'] ) ),
		);
		if ( $authorized ) {
			$result['celebrations'] = WPMFT_Celebrations::public_payload( wpmft_language( $lang ) );
			$index = WPMFT_Data::index(); $families = array();
			if ( WPMFT_Demo::scoped_request() && ! WPMFT_Demo::validate_payload( array( 'people' => array_map( function ( $id ) { return array( 'id' => (int) $id ); }, array_keys( $index ) ) ), 'search' ) ) { $result['authorized'] = false; return $result; }
			foreach ( $index as $id => $record ) { if ( $record['children'] ) { $families[$id] = true; } }
			$result['stats'] = array( 'people' => count( $index ), 'families' => count( $families ) );
		}
		return $result;
	}
	public static function dispatch() {
		WPMFT_Demo::set_request_scope( WPMFT_Demo::guard_required() && ! wpmft_staff() );
		WPMFT_Security::no_cache();
		if ( ! wpmft_settings()['enabled'] ) { self::error( new WP_Error( 'disabled', __( 'The family tree is currently unavailable.', 'my-family-tree' ) ), 503 ); }
		$op = sanitize_key( self::value( 'op' ) ); $lang = wpmft_language( self::value( 'lang' ) );
		$locale = wpmft_locale( $lang );
		switch_to_locale( $locale );
		unload_textdomain( 'my-family-tree', true );
		if ( $lang === 'lv' ) { load_textdomain( 'my-family-tree', WPMFT_DIR . 'languages/my-family-tree-lv.mo' ); }
		WPMFT_Languages::set_request_language( $lang );
		if ( $op === 'bootstrap' ) {
			if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) { self::error( new WP_Error( 'method', __( 'A POST request is required.', 'my-family-tree' ) ), 405 ); }
			wp_send_json_success( self::bootstrap( $lang ) );
		}
		$check = WPMFT_Security::check_request();
		if ( is_wp_error( $check ) ) { self::error( $check, 403 ); }
		if ( $op === 'unlock' ) {
			if ( ! WPMFT_Security::rate_limit( 'unlock_request', 15, 15 * MINUTE_IN_SECONDS ) ) { self::error( new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again in 15 minutes.', 'my-family-tree' ) ), 429 ); }
			if ( strlen( self::value( 'password' ) ) > 4096 ) { self::error( new WP_Error( 'password', __( 'The password is too long.', 'my-family-tree' ) ) ); }
			$check = WPMFT_Security::captcha( 'login' ); if ( is_wp_error( $check ) ) { self::error( $check ); }
			$check = WPMFT_Security::unlock( self::value( 'password' ), self::value( 'remember' ) === '1' ); if ( is_wp_error( $check ) ) { self::error( $check, $check->get_error_code() === 'rate_limit' ? 429 : 403 ); }
			wp_send_json_success( self::bootstrap( $lang ) );
		}
		if ( $op === 'logout' ) { WPMFT_Security::logout(); wp_send_json_success( self::bootstrap( $lang ) ); }
		if ( $op === 'contact' ) { self::contact(); }
		if ( ! WPMFT_Security::authorized() ) { self::error( new WP_Error( 'unauthorized', __( 'Your family session has ended. Please unlock the tree again.', 'my-family-tree' ) ), 401 ); }
		if ( ! WPMFT_Security::rate_limit( 'browse', 600, MINUTE_IN_SECONDS ) ) { self::error( new WP_Error( 'rate_limit', __( 'Please wait a moment before trying again.', 'my-family-tree' ) ), 429 ); }
		if ( $op === 'tree' ) { self::send_family_data( WPMFT_Data::tree( absint( self::value( 'root', wpmft_settings()['root_id'] ) ), absint( self::value( 'depth', 2 ) ), $lang ), 'tree' ); }
		if ( $op === 'person' ) {
			$person = WPMFT_Data::person( absint( self::value( 'id' ) ), $lang );
			if ( is_wp_error( $person ) ) { self::error( $person, 404 ); }
			self::send_family_data( $person, 'person' );
		}
		if ( $op === 'search' ) { self::send_family_data( WPMFT_Data::search( substr( sanitize_text_field( self::value( 'q' ) ), 0, 200 ), absint( self::value( 'page', 1 ) ), $lang ), 'search' ); }
		if ( $op === 'calendar' ) {
			$year = max( 2000, min( 2100, absint( self::value( 'year', wp_date( 'Y' ) ) ) ) );
			$month = max( 1, min( 12, absint( self::value( 'month', wp_date( 'n' ) ) ) ) );
			self::send_family_data( WPMFT_Celebrations::public_payload( $lang, $year, $month ), 'calendar' );
		}
		self::error( new WP_Error( 'operation', __( 'This action is not available.', 'my-family-tree' ) ), 404 );
	}
	private static function contact() {
		$s = wpmft_settings();
		if ( WPMFT_Demo::guard_required() || ! $s['contact_enabled'] || ! is_email( $s['contact_email'] ) ) { self::error( new WP_Error( 'contact_disabled', __( 'The contact form is not available.', 'my-family-tree' ) ), 403 ); }
		if ( ! WPMFT_Security::rate_limit( 'contact', 5, HOUR_IN_SECONDS ) ) { self::error( new WP_Error( 'rate_limit', __( 'Too many messages. Please try again later.', 'my-family-tree' ) ), 429 ); }
		$started = (int) self::value( 'started' );
		if ( self::value( 'website' ) !== '' || $started > time() - 3 || $started < time() - DAY_IN_SECONDS ) { self::error( new WP_Error( 'spam', __( 'Please wait a few seconds and submit the form again.', 'my-family-tree' ) ) ); }
		$name = sanitize_text_field( self::value( 'name' ) ); $email = sanitize_email( self::value( 'email' ) ); $message = sanitize_textarea_field( self::value( 'message' ) );
		if ( ! $name || strlen( $name ) > 160 || ! is_email( $email ) || strlen( $email ) > 254 || strlen( $message ) < 10 || strlen( $message ) > 10000 ) { self::error( new WP_Error( 'fields', __( 'Enter your full name, a valid email address, and a message of 10–10,000 characters.', 'my-family-tree' ) ) ); }
		$check = WPMFT_Security::captcha( 'contact' ); if ( is_wp_error( $check ) ) { self::error( $check ); }
		$subject = __( 'Family tree contact', 'my-family-tree' ) . ' — ' . sanitize_text_field( $s['title'] );
		$body = __( 'Full name', 'my-family-tree' ) . ': ' . $name . "\n" . __( 'Email', 'my-family-tree' ) . ': ' . $email . "\n\n" . $message;
		$sent = false; $attachments = array();
		try {
			$attachments = WPMFT_Contact_Upload::receive();
			if ( ! is_wp_error( $attachments ) ) { $sent = wp_mail( $s['contact_email'], $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email ), $attachments ); }
		} catch ( Throwable $error ) {
			// Do not expose paths, SMTP credentials or image-library exceptions in an AJAX response.
			$sent = false;
		} finally {
			WPMFT_Contact_Upload::cleanup();
		}
		// wp_send_json_* exits; it must run after the finally block has removed the temporary upload.
		if ( is_wp_error( $attachments ) ) { self::error( $attachments ); }
		if ( ! $sent ) { self::error( new WP_Error( 'mail_failed', __( 'The message could not be sent. Please try again later or use the administrator contact details.', 'my-family-tree' ) ), 503 ); }
		wp_send_json_success( array( 'message' => __( 'Thank you. Your message has been sent to the family administrator.', 'my-family-tree' ) ) );
	}
}
