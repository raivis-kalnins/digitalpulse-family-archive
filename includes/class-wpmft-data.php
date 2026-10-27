<?php
/** Read the original WordPress posts and relationships without duplicating them. @package WPMyFamilyTree */
defined( 'ABSPATH' ) || exit;

final class WPMFT_Data {
	private static $index = null;
	private static $canonical = array();
	private static $partners = array();

	/** Drop only request-local relationship data after edits or a multisite context switch. */
	public static function reset_cache() {
		self::$index = null;
		self::$canonical = array();
		self::$partners = array();
	}

	/** Read explicit declarations only; shared children never imply a current romantic relationship. */
	public static function partner_declarations( $id ) {
		$stored = get_post_meta( $id, '_wpmft_partner_status', true ); $result = array();
		foreach ( is_array( $stored ) ? $stored : array() as $person => $status ) {
			if ( ( is_int( $person ) || is_string( $person ) && ctype_digit( $person ) ) && (int) $person > 0 && (int) $person !== (int) $id && in_array( $status, array( 'together', 'divorced', 'separated', 'former' ), true ) ) {
				$result[(int) $person] = $status;
			}
		}
		return $result;
	}

	/** Resolve disagreements conservatively and deterministically, regardless of record order. */
	public static function resolve_partner_status( $first, $second ) {
		$rank = array( '' => 0, 'co_parent' => 1, 'legacy' => 2, 'together' => 3, 'former' => 4, 'separated' => 5, 'divorced' => 6 );
		return ( $rank[$second] ?? 0 ) > ( $rank[$first] ?? 0 ) ? $second : $first;
	}

	public static function former_status( $status ) { return in_array( $status, array( 'divorced', 'separated', 'former' ), true ); }

	private static function connect_partners( $a, $b, $status ) {
		if ( $a === $b || ! isset( self::$index[$a], self::$index[$b] ) ) { return; }
		$status = self::resolve_partner_status( self::$partners[$a][$b] ?? '', $status );
		self::$partners[$a][$b] = $status; self::$partners[$b][$a] = $status;
	}

	public static function partner_statuses( $id ) { self::index(); return self::$partners[self::$canonical[$id] ?? $id] ?? array(); }
	public static function partner_status( $a, $b ) {
		self::index(); $a = self::$canonical[$a] ?? $a; $b = self::$canonical[$b] ?? $b;
		return self::$partners[$a][$b] ?? '';
	}

	public static function categories() {
		$s = wpmft_settings();
		$id = absint( $s['category_id'] );
		if ( ! $id ) {
			$t = get_term_by( 'name', get_option( 'family_tree_category_key', 'Family' ), 'category' );
			$id = $t ? (int) $t->term_id : 0;
		}
		$ids = $id ? array( $id ) : array();
		if ( $id && function_exists( 'pll_get_term_translations' ) ) { $ids = array_merge( $ids, array_values( pll_get_term_translations( $id ) ) ); }
		foreach ( $ids as $cat ) {
			$children = get_term_children( $cat, 'category' );
			if ( ! is_wp_error( $children ) ) { $ids = array_merge( $ids, $children ); }
		}
		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	public static function is_person( $id ) {
		if ( ! $id || 'post' !== get_post_type( $id ) ) { return false; }
		if ( get_post_meta( $id, '_wpmft_person', true ) ) { return true; }
		$cats = self::categories();
		return $cats && has_category( $cats, $id );
	}

	public static function ids( $published = true ) {
		$statuses = $published ? array( 'publish' ) : array( 'publish', 'private', 'draft', 'pending', 'future' );
		$base = array(
			'post_type' => 'post', 'post_status' => $statuses, 'posts_per_page' => -1, 'fields' => 'ids',
			'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true, 'suppress_filters' => false,
		);
		$ids = array();
		$categories = self::categories();
		if ( $categories ) {
			$ids = get_posts( $base + array( 'category__in' => $categories, 'wpmft_internal' => true, 'lang' => '' ) );
		}
		$marked = get_posts( $base + array( 'meta_key' => '_wpmft_person', 'meta_value' => '1', 'wpmft_internal' => true, 'lang' => '' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Backward-compatible person marker.
		$ids = array_values( array_unique( array_map( 'absint', array_merge( $ids, $marked ) ) ) );
		if ( $ids && $published ) { _prime_post_caches( $ids, false, true ); }
		return $ids;
	}

	private static function relation_ids( $value ) {
		$result = array();
		foreach ( (array) $value as $id ) {
			if ( is_array( $id ) ) { $result = array_merge( $result, self::relation_ids( $id ) ); }
			elseif ( ( is_int( $id ) || is_string( $id ) && ctype_digit( $id ) ) && (int) $id > 0 ) { $result[] = (int) $id; }
		}
		return array_values( array_unique( $result ) );
	}
	public static function index() {
		if ( null !== self::$index ) { return self::$index; }
		$cached = class_exists( 'WPMFT_Cache' ) ? WPMFT_Cache::read_graph() : false;
		if ( false !== $cached ) {
			self::$index = $cached['index']; self::$canonical = $cached['canonical']; self::$partners = $cached['partners'];
			// Keep the existing bulk post/meta priming; a graph hit must not introduce per-person SQL queries.
			if ( self::$canonical ) { _prime_post_caches( array_keys( self::$canonical ), false, true ); }
			return self::$index;
		}
		$ids = self::ids();
		self::$index = array();
		self::$canonical = array();
		self::$partners = array();
		if ( ! $ids ) { self::cache_index(); return self::$index; }
		update_meta_cache( 'post', $ids );
		$all = array_fill_keys( $ids, true );
		foreach ( $ids as $id ) {
			$canonical = $id;
			if ( function_exists( 'pll_get_post_translations' ) ) {
				$translations = array_intersect( array_values( pll_get_post_translations( $id ) ), $ids );
				if ( $translations ) { $canonical = min( $translations ); }
			}
			self::$canonical[$id] = (int) $canonical;
		}
		foreach ( $ids as $id ) {
			if ( self::$canonical[$id] !== $id ) { continue; }
			$parents = array_merge( self::relation_ids( get_post_meta( $id, 'father', true ) ), self::relation_ids( get_post_meta( $id, 'mother', true ) ) );
			$extra = get_post_meta( $id, '_wpmft_spouses', true );
			$spouses = array_merge( self::relation_ids( get_post_meta( $id, 'spouse', true ) ), self::relation_ids( $extra ) );
			$normalize = function ( $items ) use ( $id, $all ) {
				$result = array();
				foreach ( array_map( 'absint', $items ) as $item ) {
					if ( isset( $all[$item] ) ) { $item = self::$canonical[$item]; if ( $item !== $id ) { $result[] = $item; } }
				}
				return array_values( array_unique( $result ) );
			};
			self::$index[$id] = array( 'parents' => $normalize( $parents ), 'spouses' => $normalize( $spouses ), 'children' => array(), 'siblings' => array() );
		}
		foreach ( self::$index as $id => $r ) {
			foreach ( $r['parents'] as $parent ) { if ( isset( self::$index[$parent] ) ) { self::$index[$parent]['children'][] = $id; } }
			foreach ( $r['spouses'] as $spouse ) { self::connect_partners( $id, $spouse, 'legacy' ); }
			// Two recorded parents are co-parents, with no assumption about their relationship status.
			if ( count( $r['parents'] ) === 2 ) {
				list( $a, $b ) = $r['parents']; self::connect_partners( $a, $b, 'co_parent' );
			}
		}
		// Read either person's declaration, including translated records, without writing to other posts.
		foreach ( $ids as $id ) {
			foreach ( self::partner_declarations( $id ) as $partner => $status ) {
				if ( isset( self::$canonical[$partner] ) ) { self::connect_partners( self::$canonical[$id], self::$canonical[$partner], $status ); }
			}
		}
		foreach ( self::$index as $id => $r ) {
			$siblings = array();
			foreach ( $r['parents'] as $parent ) { $siblings = array_merge( $siblings, self::$index[$parent]['children'] ); }
			self::$index[$id]['siblings'] = array_values( array_diff( array_unique( $siblings ), array( $id ) ) );
			self::$index[$id]['spouses'] = array_keys( array_filter( self::$partners[$id] ?? array(), function ( $status ) { return ! self::former_status( $status ); } ) );
		}
		self::cache_index();
		return self::$index;
	}

	private static function cache_index() {
		if ( class_exists( 'WPMFT_Cache' ) ) { WPMFT_Cache::write_graph( array( 'index' => self::$index, 'canonical' => self::$canonical, 'partners' => self::$partners ) ); }
	}

	public static function canonical( $id ) { self::index(); return self::$canonical[$id] ?? $id; }
	public static function display_id( $id, $lang ) {
		if ( function_exists( 'pll_get_post' ) ) {
			$t = pll_get_post( $id, $lang );
			if ( $t && 'publish' === get_post_status( $t ) && self::is_person( $t ) && ( ! WPMFT_Demo::scoped_request() || WPMFT_Demo::allows_person( $t ) ) ) { return (int) $t; }
		}
		return $id;
	}

	public static function summary( $id, $lang = 'en' ) {
		$display = self::display_id( $id, $lang );
		$p = get_post( $display );
		$s = wpmft_settings();
		$died = (string) get_post_meta( $id, 'died', true );
		$thumb = get_post_thumbnail_id( $display ) ?: get_post_thumbnail_id( $id );
		return array(
			'id' => (int) $id, 'name' => $p ? html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ) : '',
			'born' => $s['hide_living_dates'] && ! $died ? '' : (string) get_post_meta( $id, 'born', true ),
			'died' => $died, 'gender' => (string) get_post_meta( $id, 'gender', true ),
			// WordPress medium images preserve the source proportions; hard-cropped thumbnails can cut off faces.
			'photo' => $thumb ? WPMFT_Media::url( $thumb, 'medium' ) : '',
		);
	}

	public static function tree( $root, $depth, $lang ) {
		$index = self::index(); $root = self::canonical( $root );
		if ( ! isset( $index[$root] ) ) { $root = (int) ( array_key_first( $index ) ?? 0 ); }
		if ( ! $root ) { return array( 'root' => 0, 'nodes' => array(), 'edges' => array(), 'total' => 0, 'truncated' => false ); }
		$depth = max( 1, min( 3, $depth ) );
		// A former partner remains visible when they are a recorded ancestor of the selected person.
		$ancestors = array(); $ancestor_queue = array( $root );
		while ( $ancestor_queue ) {
			$id = array_pop( $ancestor_queue );
			if ( isset( $ancestors[$id] ) || ! isset( $index[$id] ) ) { continue; }
			$ancestors[$id] = true; $ancestor_queue = array_merge( $ancestor_queue, $index[$id]['parents'] );
		}
		$excluded = array(); $household = array( $root );
		foreach ( $index[$root]['spouses'] as $partner ) {
			if ( in_array( self::partner_status( $root, $partner ), array( 'together', 'legacy' ), true ) ) { $household[] = $partner; }
		}
		foreach ( $household as $person ) {
			foreach ( self::partner_statuses( $person ) as $partner => $status ) {
				if ( self::former_status( $status ) && ! isset( $ancestors[$partner] ) ) { $excluded[$partner] = true; }
			}
		}
		$queue = array( array( $root, 0, 0, 'self' ) ); $seen = array(); $nodes = array(); $truncated = false;
		while ( $queue ) {
			list( $id, $distance, $generation, $role ) = array_shift( $queue );
			if ( isset( $seen[$id] ) || isset( $excluded[$id] ) || ! isset( $index[$id] ) ) { continue; }
			if ( count( $nodes ) >= 60 ) { $truncated = true; break; }
			$seen[$id] = true;
			$node = self::summary( $id, $lang );
			$node['generation'] = $generation;
			$direct = $index[$root];
			if ( $id === $root ) { $role = 'self'; }
			elseif ( in_array( $id, $direct['parents'], true ) ) { $role = 'parent'; }
			elseif ( in_array( $id, $direct['spouses'], true ) ) { $role = 'spouse'; $node['partnerStatus'] = self::partner_status( $root, $id ); }
			elseif ( in_array( $id, $direct['children'], true ) ) { $role = 'child'; }
			elseif ( in_array( $id, $direct['siblings'], true ) ) { $role = 'sibling'; }
			else { $role = 'relative'; }
			$node['role'] = $role;
			$nodes[$id] = $node;
			if ( $distance >= $depth ) { continue; }
			foreach ( $index[$id]['parents'] as $next ) {
				$former_pair = false;
				foreach ( $index[$id]['parents'] as $other ) { if ( $other !== $next && self::former_status( self::partner_status( $next, $other ) ) ) { $former_pair = true; break; } }
				// Expanding a descendant must not reintroduce their parent's former partner into this view.
				if ( $generation > 0 && $former_pair && ! isset( $ancestors[$next] ) && ! isset( $seen[$next] ) ) { continue; }
				$queue[] = array( $next, $distance + 1, $generation - 1, $generation <= -1 ? 'grandparent' : 'parent' );
			}
			foreach ( $index[$id]['spouses'] as $next ) { $queue[] = array( $next, $distance + 1, $generation, 'spouse' ); }
			foreach ( $index[$id]['children'] as $next ) { $queue[] = array( $next, $distance + 1, $generation + 1, $generation >= 1 ? 'grandchild' : 'child' ); }
			if ( $distance === 0 ) { foreach ( $index[$id]['siblings'] as $next ) { $queue[] = array( $next, 1, $generation, 'sibling' ); } }
		}
		$edges = array();
		foreach ( $nodes as $id => &$node ) {
			$r = $index[$id];
			$node['hasMore'] = (bool) array_diff( array_merge( $r['parents'], $r['spouses'], $r['children'], $r['siblings'] ), array_keys( $nodes ) );
			$father_ids = array_map( array( __CLASS__, 'canonical' ), self::relation_ids( get_post_meta( $id, 'father', true ) ) );
			$mother_ids = array_map( array( __CLASS__, 'canonical' ), self::relation_ids( get_post_meta( $id, 'mother', true ) ) );
			foreach ( $r['parents'] as $parent ) {
				if ( ! isset( $nodes[$parent] ) ) { continue; }
				$parent_role = in_array( $parent, $mother_ids, true ) ? 'mother' : ( in_array( $parent, $father_ids, true ) ? 'father' : 'parent' );
				$edges[] = array( 'from' => $parent, 'to' => $id, 'type' => 'parent', 'role' => $parent_role );
			}
			foreach ( $r['spouses'] as $spouse ) { if ( $id < $spouse && isset( $nodes[$spouse] ) ) { $edges[] = array( 'from' => $id, 'to' => $spouse, 'type' => 'spouse', 'status' => self::partner_status( $id, $spouse ) ); } }
		}
		unset( $node );
		return array( 'root' => $root, 'nodes' => array_values( $nodes ), 'edges' => $edges, 'total' => count( $index ), 'truncated' => $truncated );
	}

	/** The saved selection controls the visible gallery, including an intentionally empty gallery. */
	public static function gallery_ids( $id, $with_children = true ) {
		$id = absint( $id );
		if ( ! metadata_exists( 'post', $id, '_wpmft_gallery' ) ) {
			$canonical = self::canonical( $id );
			if ( $canonical !== $id && metadata_exists( 'post', $canonical, '_wpmft_gallery' ) ) {
				$id = $canonical;
			} else {
				// Keep imported galleries until the editor saves an explicit selection. Show this same list in the editor.
				$ids = self::referenced_image_ids( $canonical, $with_children );
				if ( $canonical !== $id ) { $ids = array_merge( $ids, self::referenced_image_ids( $id, $with_children ) ); }
				return array_values( array_unique( $ids ) );
			}
		}
		$gallery = get_post_meta( $id, '_wpmft_gallery', true );
		$ids = is_array( $gallery ) ? self::relation_ids( $gallery ) : array();
		return array_values( array_filter( $ids, function ( $attachment ) { return wp_attachment_is_image( $attachment ); } ) );
	}

	/** Per-person photo stories do not change a shared attachment's caption on another person's page. */
	public static function gallery_details( $id ) {
		$id = absint( $id );
		$canonical = self::canonical( $id );
		$inherited = $canonical !== $id ? get_post_meta( $canonical, '_wpmft_gallery_details', true ) : array();
		$local = get_post_meta( $id, '_wpmft_gallery_details', true );
		$inherited = is_array( $inherited ) ? $inherited : array();
		$local = is_array( $local ) ? $local : array();
		$result = array();
		foreach ( self::gallery_ids( $id ) as $photo ) {
			$details = isset( $local[$photo] ) && is_array( $local[$photo] ) ? $local[$photo] : ( $inherited[$photo] ?? array() );
			$details = is_array( $details ) ? $details : array();
			$result[$photo] = array(
				'description' => isset( $details['description'] ) && is_string( $details['description'] ) ? sanitize_textarea_field( $details['description'] ) : wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $photo, 'raw' ) ),
				'date' => isset( $details['date'] ) && is_string( $details['date'] ) ? sanitize_text_field( $details['date'] ) : '',
			);
		}
		return $result;
	}

	/** An explicitly blank translated number hides its inherited WhatsApp contact. */
	public static function whatsapp_number( $id ) {
		$id = absint( $id );
		$source = metadata_exists( 'post', $id, '_wpmft_whatsapp' ) ? $id : self::canonical( $id );
		return wpmft_whatsapp_number( get_post_meta( $source, '_wpmft_whatsapp', true ) );
	}

	/** All image references stay protected even when they are not selected for the visible gallery. */
	public static function referenced_image_ids( $id, $with_children = true ) {
		$ids = array( get_post_thumbnail_id( $id ) );
		$gallery = get_post_meta( $id, '_wpmft_gallery', true );
		if ( is_array( $gallery ) ) { $ids = array_merge( $ids, $gallery ); }
		$p = get_post( $id );
		if ( $p ) {
			preg_match_all( '/wp-image-(\d+)|"id"\s*:\s*(\d+)/', $p->post_content, $matches );
			$ids = array_merge( $ids, $matches[1], $matches[2] );
			preg_match_all( '/\[gallery\b[^\]]*ids=[\x22\x27]([0-9, ]+)/i', $p->post_content, $matches );
			foreach ( $matches[1] as $list ) { $ids = array_merge( $ids, explode( ',', $list ) ); }
		}
		$children = $with_children ? get_posts( array( 'post_type' => 'attachment', 'post_parent' => $id, 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'wpmft_internal' => true, 'suppress_filters' => false, 'lang' => '' ) ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', array_merge( $ids, $children ) ) ) ) );
		return array_values( array_filter( $ids, function ( $attachment ) { return wp_attachment_is_image( $attachment ); } ) );
	}

	public static function person( $id, $lang ) {
		$id = self::canonical( $id ); $index = self::index();
		if ( ! isset( $index[$id] ) ) { return new WP_Error( 'not_found', __( 'This person could not be found.', 'digitalpulse-family-archive' ) ); }
		$result = self::summary( $id, $lang ); $display = self::display_id( $id, $lang ); $p = get_post( $display );
		if ( ! $p instanceof WP_Post ) { return new WP_Error( 'not_found', __( 'This person could not be found.', 'digitalpulse-family-archive' ) ); }
		foreach ( array( 'birthPlace' => '_wpmft_birth_place', 'deathPlace' => '_wpmft_death_place', 'location' => '_wpmft_location', 'occupation' => '_wpmft_occupation', 'maidenName' => '_wpmft_maiden_name', 'nameDay' => '_wpmft_name_day' ) as $key => $meta ) {
			$result[$key] = (string) ( get_post_meta( $display, $meta, true ) ?: get_post_meta( $id, $meta, true ) );
		}
		$contact = function ( $key ) use ( $display, $id ) {
			$value = get_post_meta( $display, '_wpmft_' . $key, true ) ?: get_post_meta( $id, '_wpmft_' . $key, true );
			return is_scalar( $value ) ? (string) $value : '';
		};
		$email = sanitize_email( $contact( 'email' ) );
		$result['contacts'] = array(
			'email' => is_email( $email ) ? $email : '',
			'phone' => sanitize_text_field( $contact( 'phone' ) ), 'mobile' => sanitize_text_field( $contact( 'mobile' ) ),
			'whatsapp' => wpmft_whatsapp_url( self::whatsapp_number( $display ) ),
			'address' => sanitize_textarea_field( $contact( 'address' ) ), 'website' => self::contact_url( $contact( 'website' ) ),
		);
		$result['socials'] = array();
		foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok' ) as $key => $label ) {
			$url = self::contact_url( $contact( 'social_' . $key ) );
			if ( $url ) { $result['socials'][] = array( 'key' => $key, 'label' => $label, 'url' => $url ); }
		}
		// Unlocking the family password never grants WordPress editing permissions.
		if ( is_user_logged_in() ) {
			$edit_id = current_user_can( 'edit_post', $display ) ? $display : ( current_user_can( 'edit_post', $id ) ? $id : 0 );
			$edit_url = $edit_id ? get_edit_post_link( $edit_id, 'raw' ) : '';
			if ( $edit_url ) { $result['editUrl'] = esc_url_raw( add_query_arg( 'wpmft_admin_lang', wpmft_language( $lang ), $edit_url ), array( 'http', 'https' ) ); }
		}
		// Use saved static block HTML; never run arbitrary embedded shortcodes or remote dynamic blocks here.
		$content = preg_replace( '/<!--.*?-->/s', '', $p->post_content );
		$content = strip_shortcodes( $content );
		// Photographs are shown once in the controlled gallery, avoiding duplicate image downloads.
		$content = preg_replace( '/<img\b[^>]*>/i', '', $content );
		$content = preg_replace( '/<a\b[^>]*>\s*<\/a>/i', '', $content );
		$result['bio'] = WPMFT_Media::rewrite_html( wp_kses_post( wpautop( $content ) ) );
		$result['gallery'] = array();
		$gallery_details = self::gallery_details( $display );
		foreach ( self::gallery_ids( $display ) as $photo ) {
			$a = get_post( $photo );
			$result['gallery'][] = array_merge( WPMFT_Media::image_data( $photo ), array( 'id' => $photo, 'alt' => (string) get_post_meta( $photo, '_wp_attachment_image_alt', true ), 'caption' => $a ? wp_strip_all_tags( $a->post_excerpt ) : '', 'description' => $gallery_details[$photo]['description'], 'date' => $gallery_details[$photo]['date'] ) );
		}
		$portrait = get_post_thumbnail_id( $display ) ?: get_post_thumbnail_id( $id );
		if ( $portrait ) { $result['photo'] = WPMFT_Media::url( $portrait, 'large' ); }
		elseif ( $result['gallery'] ) { $result['photo'] = WPMFT_Media::url( $result['gallery'][0]['id'], 'large' ); }
		$result['relations'] = array();
		foreach ( $index[$id] as $relation => $ids ) {
			$result['relations'][$relation] = array_map( function ( $related ) use ( $lang, $id, $relation ) {
				$person = self::summary( $related, $lang );
				if ( $relation === 'spouses' ) { $person['partnerStatus'] = self::partner_status( $id, $related ); }
				return $person;
			}, $ids );
		}
		return $result;
	}

	/** Contact links are ordinary HTTP(S) URLs, never script, data, local or relative URLs. */
	private static function contact_url( $value ) {
		$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) && isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) ? $url : '';
	}

	public static function search( $query, $page, $lang ) {
		$index = self::index(); $query = trim( $query ); $matches = array();
		foreach ( array_keys( $index ) as $id ) {
			$p = get_post( self::display_id( $id, $lang ) );
			if ( ! $p instanceof WP_Post ) { continue; }
			$haystack = remove_accents( $p->post_title ); $needle = remove_accents( $query );
			if ( $query === '' || stripos( $haystack, $needle ) !== false || ( function_exists( 'mb_stripos' ) && mb_stripos( $haystack, $needle, 0, 'UTF-8' ) !== false ) ) { $matches[] = $id; }
		}
		$offset = ( max( 1, min( 10000, $page ) ) - 1 ) * 24;
		return array( 'people' => array_map( function ( $id ) use ( $lang ) { return self::summary( $id, $lang ); }, array_slice( $matches, $offset, 24 ) ), 'more' => count( $matches ) > $offset + 24 );
	}
}
