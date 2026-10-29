<?php
/**
 * SERP data: Google's top 10 for a keyword.
 *
 * Providers: Serper.dev, DataForSEO (Live, Iran / Persian) and SerpApi. Results
 * are normalised to one shape and cached in a shared table (default 30 days),
 * so every project and every re-run reuses what was already paid for.
 *
 * Normalised result:
 *   organic   [{p, url, domain, title, snippet}]   (max 10)
 *   paa       People Also Ask questions
 *   related   related searches
 *   features  {shopping, images, videos, local, knowledge, ads}
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is a paid SERP source configured?
 */
function qtb_serp_ready() {
	return 'off' !== qtb_serp_conf()['source'];
}

/**
 * Market key of the cache (country|language).
 */
function qtb_serp_market() {
	$c = qtb_serp_conf();
	return substr( $c['gl'] . '|' . $c['hl'], 0, 16 );
}

/**
 * Cache key of a keyword.
 */
function qtb_serp_hash( $keyword ) {
	return md5( qtb_key( $keyword ) );
}

/**
 * Cached SERPs for many keywords: hash → result (fresh only).
 */
function qtb_serp_cached_many( $keywords ) {
	global $wpdb;
	$days   = max( 1, (int) qtb_setting( 'serp_cache_days', 30 ) );
	$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$hashes = array_values( array_unique( array_map( 'qtb_serp_hash', (array) $keywords ) ) );
	$out    = array();
	foreach ( array_chunk( $hashes, 400 ) as $chunk ) {
		$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		$sql  = $wpdb->prepare( 'SELECT kw_hash, payload FROM ' . qtb_table( 'serp' ) . " WHERE market = %s AND fetched_at >= %s AND kw_hash IN ($in)", array_merge( array( qtb_serp_market(), $since ), $chunk ) ); // phpcs:ignore
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore
			$d = json_decode( (string) $r['payload'], true );
			if ( is_array( $d ) ) {
				$out[ $r['kw_hash'] ] = $d;
			}
		}
	}
	return $out;
}

/**
 * Store a SERP in the shared cache.
 */
function qtb_serp_store( $keyword, $result ) {
	global $wpdb;
	$t    = qtb_table( 'serp' );
	$hash = qtb_serp_hash( $keyword );
	$mk   = qtb_serp_market();
	$row  = array( 'keyword' => mb_substr( (string) $keyword, 0, 250 ), 'payload' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ), 'fetched_at' => gmdate( 'Y-m-d H:i:s' ) );
	$id   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE kw_hash = %s AND market = %s", $hash, $mk ) ); // phpcs:ignore
	if ( $id ) {
		$wpdb->update( $t, $row, array( 'id' => (int) $id ) ); // phpcs:ignore
	} else {
		$wpdb->insert( $t, array_merge( $row, array( 'kw_hash' => $hash, 'market' => $mk ) ) ); // phpcs:ignore
	}
}

/**
 * Number of SERPs in the shared cache (for the settings page).
 */
function qtb_serp_cache_count() {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . qtb_table( 'serp' ) ); // phpcs:ignore
}

/**
 * Fetch one keyword from the provider (no cache).
 *
 * @return array|WP_Error normalised result.
 */
function qtb_serp_fetch( $keyword ) {
	$c = qtb_serp_conf();
	if ( 'off' === $c['source'] ) {
		return new WP_Error( 'qtb_serp_off', 'سرویس نتایج گوگل تنظیم نشده است.' );
	}
	switch ( $c['provider'] ) {
		case 'dataforseo':
			$res = qtb_http( 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced', array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $c['login'] . ':' . $c['password'] ), 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( array(
					'keyword'       => $keyword,
					'location_code' => qtb_dfs_location( $c['gl'] ),
					'language_code' => $c['hl'],
					'depth'         => 10,
					'device'        => 'mobile',
				) ), JSON_UNESCAPED_UNICODE ),
			) );
			break;
		case 'serpapi':
			$res = qtb_http( add_query_arg( array( 'engine' => 'google', 'q' => rawurlencode( $keyword ), 'gl' => $c['gl'], 'hl' => $c['hl'], 'num' => 10, 'api_key' => $c['key'] ), 'https://serpapi.com/search.json' ), array( 'timeout' => 45 ) );
			break;
		default:
			$res = qtb_http( 'https://google.serper.dev/search', array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array( 'X-API-KEY' => $c['key'], 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'q' => $keyword, 'gl' => $c['gl'], 'hl' => $c['hl'], 'num' => 10 ), JSON_UNESCAPED_UNICODE ),
			) );
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'qtb_serp_http', 'دریافت نتایج گوگل ناموفق بود: ' . qtb_net_error( $res ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$d    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $d ) ) {
		$msg = is_array( $d ) ? (string) ( $d['message'] ?? $d['error'] ?? $d['status_message'] ?? '' ) : '';
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return new WP_Error( 'qtb_serp_auth', 'کلید سرویس نتایج گوگل رد شد یا اعتبار حساب تمام شده است (HTTP ' . $code . ')' . ( $msg ? ': ' . mb_substr( $msg, 0, 160 ) : '' ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'qtb_serp_rate', 'سقف درخواست سرویس نتایج گوگل پر شده؛ چند دقیقه بعد ادامه دهید.' );
		}
		return new WP_Error( 'qtb_serp_http', 'سرویس نتایج گوگل خطا داد (HTTP ' . $code . ')' . ( $msg ? ': ' . mb_substr( $msg, 0, 160 ) : '' ) );
	}
	switch ( $c['provider'] ) {
		case 'dataforseo':
			return qtb_serp_from_dfs( $d );
		case 'serpapi':
			return qtb_serp_from_serpapi( $d );
		default:
			return qtb_serp_from_serper( $d );
	}
}

/**
 * DataForSEO location code of a country.
 */
function qtb_dfs_location( $gl ) {
	$codes = array( 'ir' => 2364, 'us' => 2840, 'gb' => 2826, 'de' => 2276, 'ae' => 2784, 'tr' => 2792, 'ca' => 2124, 'au' => 2036, 'af' => 2004 );
	return $codes[ strtolower( (string) $gl ) ] ?? 2364;
}

/**
 * One organic row.
 */
function qtb_serp_row( $pos, $url, $title, $snippet ) {
	return array(
		'p'       => (int) $pos,
		'url'     => (string) $url,
		'domain'  => qtb_host( $url ),
		'title'   => wp_strip_all_tags( (string) $title ),
		'snippet' => mb_substr( wp_strip_all_tags( (string) $snippet ), 0, 300 ),
	);
}

/**
 * Serper.dev → normalised.
 */
function qtb_serp_from_serper( $d ) {
	$org = array();
	foreach ( (array) ( $d['organic'] ?? array() ) as $o ) {
		if ( count( $org ) >= 10 || empty( $o['link'] ) ) {
			continue;
		}
		$org[] = qtb_serp_row( $o['position'] ?? count( $org ) + 1, $o['link'], $o['title'] ?? '', $o['snippet'] ?? '' );
	}
	return array(
		'organic'  => $org,
		'paa'      => array_values( array_filter( array_map( function ( $q ) {
			return (string) ( $q['question'] ?? '' );
		}, (array) ( $d['peopleAlsoAsk'] ?? array() ) ) ) ),
		'related'  => array_values( array_filter( array_map( function ( $q ) {
			return (string) ( $q['query'] ?? '' );
		}, (array) ( $d['relatedSearches'] ?? array() ) ) ) ),
		'features' => array(
			'shopping'  => ! empty( $d['shopping'] ),
			'images'    => ! empty( $d['images'] ),
			'videos'    => ! empty( $d['videos'] ),
			'local'     => ! empty( $d['places'] ) || ! empty( $d['localResults'] ),
			'knowledge' => ! empty( $d['knowledgeGraph'] ),
			'ads'       => count( (array) ( $d['ads'] ?? array() ) ),
		),
	);
}

/**
 * SerpApi → normalised.
 */
function qtb_serp_from_serpapi( $d ) {
	$org = array();
	foreach ( (array) ( $d['organic_results'] ?? array() ) as $o ) {
		if ( count( $org ) >= 10 || empty( $o['link'] ) ) {
			continue;
		}
		$org[] = qtb_serp_row( $o['position'] ?? count( $org ) + 1, $o['link'], $o['title'] ?? '', $o['snippet'] ?? '' );
	}
	return array(
		'organic'  => $org,
		'paa'      => array_values( array_filter( array_map( function ( $q ) {
			return (string) ( $q['question'] ?? '' );
		}, (array) ( $d['related_questions'] ?? array() ) ) ) ),
		'related'  => array_values( array_filter( array_map( function ( $q ) {
			return (string) ( $q['query'] ?? '' );
		}, (array) ( $d['related_searches'] ?? array() ) ) ) ),
		'features' => array(
			'shopping'  => ! empty( $d['shopping_results'] ) || ! empty( $d['inline_shopping'] ),
			'images'    => ! empty( $d['inline_images'] ),
			'videos'    => ! empty( $d['inline_videos'] ) || ! empty( $d['video_results'] ),
			'local'     => ! empty( $d['local_results'] ),
			'knowledge' => ! empty( $d['knowledge_graph'] ),
			'ads'       => count( (array) ( $d['ads'] ?? array() ) ),
		),
	);
}

/**
 * DataForSEO (advanced) → normalised.
 */
function qtb_serp_from_dfs( $d ) {
	$items = (array) ( $d['tasks'][0]['result'][0]['items'] ?? array() );
	$out   = array( 'organic' => array(), 'paa' => array(), 'related' => array(), 'features' => array( 'shopping' => false, 'images' => false, 'videos' => false, 'local' => false, 'knowledge' => false, 'ads' => 0 ) );
	foreach ( $items as $it ) {
		$type = (string) ( $it['type'] ?? '' );
		if ( 'organic' === $type && count( $out['organic'] ) < 10 && ! empty( $it['url'] ) ) {
			$out['organic'][] = qtb_serp_row( count( $out['organic'] ) + 1, $it['url'], $it['title'] ?? '', $it['description'] ?? '' );
		} elseif ( 'people_also_ask' === $type ) {
			foreach ( (array) ( $it['items'] ?? array() ) as $q ) {
				if ( ! empty( $q['title'] ) ) {
					$out['paa'][] = (string) $q['title'];
				}
			}
		} elseif ( 'related_searches' === $type ) {
			$out['related'] = array_merge( $out['related'], array_map( 'strval', (array) ( $it['items'] ?? array() ) ) );
		} elseif ( in_array( $type, array( 'shopping', 'popular_products', 'commercial_units' ), true ) ) {
			$out['features']['shopping'] = true;
		} elseif ( 'images' === $type ) {
			$out['features']['images'] = true;
		} elseif ( in_array( $type, array( 'video', 'short_videos' ), true ) ) {
			$out['features']['videos'] = true;
		} elseif ( in_array( $type, array( 'local_pack', 'map' ), true ) ) {
			$out['features']['local'] = true;
		} elseif ( 'knowledge_graph' === $type ) {
			$out['features']['knowledge'] = true;
		} elseif ( 'paid' === $type ) {
			++$out['features']['ads'];
		}
	}
	return $out;
}

/**
 * Settings page test: one live request with a sample keyword.
 */
function qtb_serp_test() {
	$r = qtb_serp_fetch( 'خرید گل آپارتمانی' );
	if ( is_wp_error( $r ) ) {
		return array( 'ok' => false, 'msg' => $r->get_error_message() );
	}
	return array( 'ok' => count( $r['organic'] ) > 0, 'msg' => sprintf( 'موفق؛ %s نتیجه گرفته شد (اول: %s).', qtb_num( count( $r['organic'] ) ), $r['organic'][0]['domain'] ?? '—' ) );
}
