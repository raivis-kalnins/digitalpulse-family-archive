<?php
/** Birthday, family calendar and Latvian name-day support. @package MyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Celebrations {
	const CRON = 'wpmft_daily_celebrations';
	const NAMEDAY_CACHE = 'wpmft_lv_namedays_v2';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 40 );
	}

	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			$zone = wp_timezone();
			$now = new DateTimeImmutable( 'now', $zone );
			$next = $now->setTime( 8, 0, 0 );
			if ( $next <= $now ) { $next = $next->modify( '+1 day' ); }
			wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON );
			$timestamp = wp_next_scheduled( self::CRON );
		}
	}

	private static function full_date( $value ) {
		return is_string( $value ) && preg_match( '/^\d{4}-(\d{2})-(\d{2})$/D', $value, $m ) && checkdate( (int) $m[1], (int) $m[2], (int) substr( $value, 0, 4 ) );
	}

	private static function first_name( $name ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) { return ''; }
		$parts = preg_split( '/\s+/u', $name );
		return isset( $parts[0] ) ? trim( $parts[0], " \t\n\r\0\x0B()[]{}.," ) : '';
	}

	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	/** Minimal offline fallback; the cached MIT dataset supplies the complete traditional list when available. */
	private static function fallback_namedays() {
		return array(
			'03-12' => array( 'Aija', 'Aiva', 'Aivis' ), '03-16' => array( 'Guntis', 'Guntars', 'Guntris' ),
			'04-07' => array( 'Zina', 'Zinaīda', 'Helmuts' ), '05-18' => array( 'Inese', 'Inesis', 'Ēriks' ),
			'05-19' => array( 'Lita', 'Sibilla', 'Teika' ), '05-29' => array( 'Maksis', 'Maksims', 'Raivis', 'Raivo' ),
			'06-07' => array( 'Gaida', 'Arnis', 'Arno' ), '06-17' => array( 'Artūrs', 'Artis' ),
			'07-14' => array( 'Oskars', 'Ritvars', 'Anvars' ), '07-24' => array( 'Kristīne', 'Kristīna', 'Krista', 'Kristiāna', 'Kristiāns' ),
			'07-25' => array( 'Jēkabs', 'Žaklīna' ), '08-26' => array( 'Natālija', 'Tālija', 'Broņislavs', 'Broņislava' ),
			'09-29' => array( 'Miķelis', 'Mikus', 'Miks', 'Mihails' ), '10-13' => array( 'Irma', 'Mirga' ),
			'10-29' => array( 'Laimonis', 'Elvijs', 'Elva', 'Elvis', 'Laimis' ), '11-10' => array( 'Mārtiņš', 'Mārcis', 'Markuss', 'Marks' ),
			'12-17' => array( 'Hilda', 'Teiksma' ), '12-19' => array( 'Lelde', 'Sarmis' ),
			'02-27' => array( 'Līvija', 'Līva', 'Andra' ),
			// Traditional-calendar additions effective from 1 January 2026.
			'01-23' => array( 'Grēta' ), '02-04' => array( 'Dominika' ), '06-19' => array( 'Mīla' ), '07-23' => array( 'Madlēna' ),
			'09-23' => array( 'Vanesa' ), '11-08' => array( 'Aleksa' ), '01-11' => array( 'Francisks' ), '06-28' => array( 'Vestards' ), '08-02' => array( 'Eliass' ),
		);
	}

	private static function merge_namedays( $base, $extra ) {
		foreach ( $extra as $date => $names ) {
			if ( ! preg_match( '/^\d{2}-\d{2}$/D', $date ) || ! is_array( $names ) ) { continue; }
			if ( ! isset( $base[$date] ) || ! is_array( $base[$date] ) ) { $base[$date] = array(); }
			$base[$date] = array_values( array_unique( array_merge( array_filter( $base[$date], 'is_string' ), array_filter( $names, 'is_string' ) ) ) );
		}
		return $base;
	}

	public static function namedays() {
		$s = wpmft_settings();
		if ( empty( $s['name_days_lv'] ) ) { return array(); }
		$stored = get_option( 'wpmft_lv_namedays_data', array() );
		if ( is_array( $stored ) && count( $stored ) >= 300 ) { return self::merge_namedays( $stored, self::fallback_namedays() ); }
		$legacy = get_transient( 'wpmft_lv_namedays_v1' );
		if ( is_array( $legacy ) && count( $legacy ) >= 300 ) {
			update_option( 'wpmft_lv_namedays_data', $legacy, false );
			return self::merge_namedays( $legacy, self::fallback_namedays() );
		}
		$cached = get_transient( self::NAMEDAY_CACHE );
		if ( is_array( $cached ) && $cached ) { return self::merge_namedays( $cached, self::fallback_namedays() ); }
		$data = array();
		$file = WPMFT_DIR . 'data/latvian-namedays.json';
		if ( is_readable( $file ) ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled read-only plugin data, never a URL.
			$decoded = is_string( $contents ) ? json_decode( $contents, true ) : null;
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $date => $names ) {
					if ( preg_match( '/^\d{2}-\d{2}$/D', (string) $date ) && is_array( $names ) ) {
						$data[$date] = array_values( array_filter( array_map( 'sanitize_text_field', $names ) ) );
					}
				}
			}
		}
		if ( $data ) { set_transient( self::NAMEDAY_CACHE, $data, 30 * DAY_IN_SECONDS ); }
		return self::merge_namedays( $data ?: self::fallback_namedays(), self::fallback_namedays() );
	}

	private static function display_post( $id, $lang ) {
		$display = WPMFT_Data::display_id( $id, $lang );
		$post = get_post( $display );
		return $post instanceof WP_Post ? $post : get_post( $id );
	}

	public static function birthday_events( $year, $month, $lang ) {
		$events = array();
		foreach ( array_keys( WPMFT_Data::index() ) as $id ) {
			$born = (string) get_post_meta( $id, 'born', true );
			$died = trim( (string) get_post_meta( $id, 'died', true ) );
			if ( $died || ! self::full_date( $born ) ) { continue; }
			$parts = array_map( 'intval', explode( '-', $born ) );
			if ( $parts[1] !== (int) $month ) { continue; }
			$post = self::display_post( $id, $lang );
			if ( ! $post instanceof WP_Post ) { continue; }
			$events[] = array(
				'type' => 'birthday', 'id' => (int) $id, 'day' => $parts[2], 'month' => $parts[1], 'year' => $parts[0],
				'name' => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
			);
		}
		usort( $events, function ( $a, $b ) { return $a['day'] - $b['day'] ?: strnatcasecmp( $a['name'], $b['name'] ); } );
		return $events;
	}

	public static function family_namedays( $year, $month, $lang ) {
		if ( 'lv' !== $lang || empty( wpmft_settings()['name_days_lv'] ) ) { return array(); }
		$calendar = self::namedays(); $events = array();
		foreach ( array_keys( WPMFT_Data::index() ) as $id ) {
			if ( trim( (string) get_post_meta( $id, 'died', true ) ) ) { continue; }
			$post = self::display_post( $id, $lang );
			if ( ! $post instanceof WP_Post ) { continue; }
			$name = self::first_name( $post->post_title );
			$manual = trim( (string) get_post_meta( $id, '_wpmft_name_day', true ) );
			$dates = array();
			if ( preg_match( '/^(\d{2})-(\d{2})$/D', $manual, $m ) && (int) $m[1] === (int) $month ) { $dates[] = $manual; }
			if ( ! $manual && $name ) {
				$needle = self::lower( $name );
				foreach ( $calendar as $date => $names ) {
					if ( (int) substr( $date, 0, 2 ) !== (int) $month ) { continue; }
					foreach ( $names as $candidate ) { if ( self::lower( $candidate ) === $needle ) { $dates[] = $date; break; } }
				}
			}
			foreach ( array_unique( $dates ) as $date ) {
				$events[] = array( 'type' => 'nameday', 'id' => (int) $id, 'day' => (int) substr( $date, 3, 2 ), 'month' => (int) substr( $date, 0, 2 ), 'name' => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ), 'firstName' => $name );
			}
		}
		usort( $events, function ( $a, $b ) { return $a['day'] - $b['day'] ?: strnatcasecmp( $a['name'], $b['name'] ); } );
		return $events;
	}

	public static function public_payload( $lang, $year = 0, $month = 0 ) {
		$s = wpmft_settings();
		if ( empty( $s['celebrations_enabled'] ) ) { return array( 'enabled' => false ); }
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$year = $year >= 2000 && $year <= 2100 ? $year : (int) $now->format( 'Y' );
		$month = $month >= 1 && $month <= 12 ? $month : (int) $now->format( 'n' );
		$birthdays = self::birthday_events( $year, $month, $lang );
		$namedays = self::family_namedays( $year, $month, $lang );
		$today = (int) $now->format( 'j' );
		$payload = array(
			'enabled' => true, 'year' => $year, 'month' => $month,
			'birthdays' => $birthdays, 'namedays' => $namedays,
			'todayBirthdays' => ( (int) $now->format( 'Y' ) === $year && (int) $now->format( 'n' ) === $month ) ? array_values( array_filter( $birthdays, function ( $event ) use ( $today ) { return (int) $event['day'] === $today; } ) ) : array(),
			'todayNamedays' => ( (int) $now->format( 'Y' ) === $year && (int) $now->format( 'n' ) === $month ) ? array_values( array_filter( $namedays, function ( $event ) use ( $today ) { return (int) $event['day'] === $today; } ) ) : array(),
		);
		if ( 'lv' === $lang && ! empty( $s['name_days_lv'] ) ) {
			$calendar = self::namedays();
			$key = sprintf( '%02d-%02d', $month, $today );
			$payload['todayCalendarNames'] = ( (int) $now->format( 'n' ) === $month ) ? ( $calendar[$key] ?? array() ) : array();
			$payload['calendarNameDays'] = array();
			foreach ( $calendar as $date => $names ) {
				if ( (int) substr( $date, 0, 2 ) === $month && $names ) { $payload['calendarNameDays'][] = array( 'day' => (int) substr( $date, 3, 2 ), 'names' => array_values( $names ) ); }
			}
		}
		return $payload;
	}

	private static function person_email( $id ) {
		$email = sanitize_email( get_post_meta( $id, '_wpmft_email', true ) );
		if ( is_email( $email ) ) { return $email; }
		$canonical = WPMFT_Data::canonical( $id );
		$email = sanitize_email( get_post_meta( $canonical, '_wpmft_email', true ) );
		return is_email( $email ) ? $email : '';
	}

	public static function daily() {
		$s = wpmft_settings();
		if ( empty( $s['celebrations_enabled'] ) || empty( $s['birthday_email_enabled'] ) || WPMFT_Demo::guard_required() ) { return; }
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$year = (int) $now->format( 'Y' ); $month = (int) $now->format( 'n' ); $day = (int) $now->format( 'j' );
		foreach ( self::birthday_events( $year, $month, wpmft_settings()['language'] ) as $event ) {
			if ( (int) $event['day'] !== $day ) { continue; }
			$id = (int) $event['id']; $email = self::person_email( $id );
			if ( ! $email || (int) get_post_meta( $id, '_wpmft_birthday_email_year', true ) === $year ) { continue; }
			$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( WPMFT_Data::display_id( $id, 'lv' ), 'slug' ) : wpmft_settings()['language'];
			$lang = 'lv' === $lang ? 'lv' : 'en';
			$name = $event['name']; $first = self::first_name( $name ); $url = add_query_arg( 'person', $id, wpmft_tree_url( $lang ) );
			if ( 'lv' === $lang ) {
				$subject = '🎂 Daudz laimes dzimšanas dienā, ' . $first . '!';
				$body = "Daudz laimes dzimšanas dienā, {$first}!\n\nLai šī diena ir silta, priecīga un pilna skaistu mirkļu kopā ar savējiem. 🌿\n\nAtver dzimtas koku: {$url}\n";
			} else {
				$subject = '🎂 Happy Birthday, ' . $first . '!';
				$body = "Happy Birthday, {$first}!\n\nWishing you a warm, joyful day filled with lovely moments and family memories. 🌿\n\nVisit the family tree: {$url}\n";
			}
			if ( wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) { update_post_meta( $id, '_wpmft_birthday_email_year', $year ); }
		}
	}
}
