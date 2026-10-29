<?php
/**
 * Site inventory: products, product categories, blog posts and pages of the
 * project's website, used to map clusters to existing pages, find missing
 * landings, estimate revenue (product prices) and pick internal links.
 *
 * Sources (store_mode «auto» tries them in this order and skips silently when
 * one is not available):
 *   local    — this WordPress site (WooCommerce tables), when the project's domain is this site.
 *   woo_rest — WooCommerce REST API v3 with consumer key / secret (private stores).
 *   wp_rest  — public WooCommerce Store API + WordPress REST API (no keys needed).
 *   sitemap  — sitemap.xml URLs only (titles from slugs).
 *
 * Every source is resumable: qtb_store_step() does a few requests per call.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

const QTB_STORE_MAX = 4000; // Items per type.

/**
 * Is the project's website this WordPress site?
 */
function qtb_store_is_local( $project ) {
	$dom = qtb_project_domain( $project );
	return '' === $dom || qtb_host( home_url() ) === $dom;
}

/**
 * Sources to try for a project.
 */
function qtb_store_sources( $project ) {
	$mode = (string) $project['cfg']['store_mode'];
	if ( 'none' === $mode ) {
		return array();
	}
	if ( 'auto' !== $mode ) {
		return array( $mode );
	}
	$out = array();
	if ( qtb_store_is_local( $project ) ) {
		$out[] = 'local';
	}
	if ( '' !== (string) $project['cfg']['woo_key'] && '' !== (string) $project['cfg']['woo_secret'] ) {
		$out[] = 'woo_rest';
	}
	if ( '' !== qtb_project_domain( $project ) && ! qtb_store_is_local( $project ) ) {
		$out[] = 'wp_rest';
		$out[] = 'sitemap';
	}
	return $out;
}

/**
 * One resumable inventory step.
 *
 * @param array $state {sources, si, phase, page, items, notes, done}
 * @return array new state
 */
function qtb_store_step( $project, $state ) {
	if ( empty( $state['sources'] ) && ! isset( $state['si'] ) ) {
		$state = array( 'sources' => qtb_store_sources( $project ), 'si' => 0, 'phase' => 0, 'page' => 1, 'items' => array(), 'notes' => array(), 'source' => '', 'done' => false );
	}
	if ( $state['si'] >= count( $state['sources'] ) ) {
		$state['done'] = true;
		return $state;
	}
	$src = $state['sources'][ $state['si'] ];
	$t0  = microtime( true );
	while ( ! $state['done'] && microtime( true ) - $t0 < 12 ) {
		switch ( $src ) {
			case 'local':
				$r = qtb_store_local();
				break;
			case 'woo_rest':
				$r = qtb_store_remote_page( $project, $state, true );
				break;
			case 'wp_rest':
				$r = qtb_store_remote_page( $project, $state, false );
				break;
			default:
				$r = qtb_store_sitemap( $project );
		}
		if ( is_wp_error( $r ) ) {
			$state['notes'][] = qtb_store_source_label( $src ) . ': ' . $r->get_error_message();
			$state['si']++;
			$state['phase'] = 0;
			$state['page']  = 1;
			if ( $state['si'] >= count( $state['sources'] ) ) {
				$state['done'] = true;
			}
			return $state;
		}
		$state['items'] = array_merge( $state['items'], $r['items'] );
		if ( ! empty( $r['finished'] ) ) {
			if ( $state['items'] ) {
				$state['source'] = $src;
				$state['done']   = true;
			} else {
				$state['notes'][] = qtb_store_source_label( $src ) . ': صفحه‌ای پیدا نشد.';
				$state['si']++;
				$state['phase'] = 0;
				$state['page']  = 1;
				if ( $state['si'] >= count( $state['sources'] ) ) {
					$state['done'] = true;
				}
				return $state;
			}
		} else {
			$state['phase'] = $r['phase'];
			$state['page']  = $r['page'];
		}
	}
	return $state;
}

/**
 * Label of a source.
 */
function qtb_store_source_label( $src ) {
	$l = array( 'local' => 'همین سایت (ووکامرس)', 'woo_rest' => 'API ووکامرس', 'wp_rest' => 'API عمومی وردپرس/ووکامرس', 'sitemap' => 'سایت‌مپ', 'none' => 'بدون اتصال' );
	return $l[ $src ] ?? $src;
}

/**
 * Inventory of this site.
 */
function qtb_store_local() {
	$items = array();
	if ( post_type_exists( 'product' ) ) {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => QTB_STORE_MAX, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$cats    = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
			$items[] = array(
				'type'  => 'product',
				'url'   => get_permalink( $id ),
				'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'price' => qtb_to_toman( (float) get_post_meta( $id, '_price', true ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRT' ),
				'cats'  => is_wp_error( $cats ) ? array() : array_values( $cats ),
			);
		}
	}
	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => QTB_STORE_MAX ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$link = get_term_link( $t );
			if ( ! is_wp_error( $link ) && 'uncategorized' !== $t->slug ) {
				$items[] = array( 'type' => 'category', 'url' => $link, 'title' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $t->count );
			}
		}
	}
	foreach ( array( 'post', 'page' ) as $pt ) {
		$ids = get_posts( array( 'post_type' => $pt, 'post_status' => 'publish', 'numberposts' => QTB_STORE_MAX, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$items[] = array( 'type' => $pt, 'url' => get_permalink( $id ), 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
		}
	}
	return array( 'items' => $items, 'finished' => true );
}

/**
 * Store currency → Toman.
 */
function qtb_to_toman( $amount, $currency ) {
	switch ( strtoupper( (string) $currency ) ) {
		case 'IRR':
			return $amount / 10;
		case 'IRHT':
			return $amount * 1000;
		case 'IRHR':
			return $amount * 100;
		default:
			return $amount;
	}
}

/**
 * One page of a remote store. Phases: 0 products, 1 categories, 2 posts, 3 pages.
 */
function qtb_store_remote_page( $project, $state, $with_keys ) {
	$base  = untrailingslashit( 'https://' . qtb_project_domain( $project ) );
	if ( preg_match( '#^https?://#', (string) $project['site_url'] ) ) {
		$base = untrailingslashit( preg_replace( '#/+$#', '', (string) $project['site_url'] ) );
	}
	$phase = (int) $state['phase'];
	$page  = (int) $state['page'];
	$per   = 100;
	$auth  = array();
	if ( $with_keys ) {
		$routes = array( '/wp-json/wc/v3/products', '/wp-json/wc/v3/products/categories', '/wp-json/wp/v2/posts', '/wp-json/wp/v2/pages' );
		$auth   = array( 'Authorization' => 'Basic ' . base64_encode( $project['cfg']['woo_key'] . ':' . $project['cfg']['woo_secret'] ) );
	} else {
		$routes = array( '/wp-json/wc/store/v1/products', '/wp-json/wc/store/v1/products/categories', '/wp-json/wp/v2/posts', '/wp-json/wp/v2/pages' );
	}
	if ( $phase > 3 ) {
		return array( 'items' => array(), 'finished' => true );
	}
	$args = array( 'per_page' => $per, 'page' => $page );
	if ( $phase >= 2 ) {
		$args['_fields'] = 'link,title';
	}
	$res = qtb_http( add_query_arg( $args, $base . $routes[ $phase ] ), array( 'timeout' => 30, 'headers' => array_merge( $auth, array( 'Accept' => 'application/json' ) ) ) );
	if ( is_wp_error( $res ) ) {
		return 0 === $phase && 1 === $page ? new WP_Error( 'qtb_store_net', qtb_net_error( $res ) ) : array( 'items' => array(), 'phase' => $phase + 1, 'page' => 1 );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		if ( 0 === $phase && 1 === $page && $with_keys ) {
			return new WP_Error( 'qtb_store_auth', 'کلید API ووکامرس پذیرفته نشد (HTTP ' . $code . ').' );
		}
		// Not a WooCommerce store (or the Store API is off): move to posts.
		return array( 'items' => array(), 'phase' => $phase < 2 ? 2 : $phase + 1, 'page' => 1 );
	}
	$items = array();
	foreach ( $data as $d ) {
		if ( 0 === $phase ) {
			if ( $with_keys ) {
				$price = qtb_to_toman( (float) ( $d['price'] ?? 0 ), 'IRT' );
				$cats  = array_map( function ( $c ) {
					return (string) ( $c['name'] ?? '' );
				}, (array) ( $d['categories'] ?? array() ) );
			} else {
				$p     = (array) ( $d['prices'] ?? array() );
				$minor = (int) ( $p['currency_minor_unit'] ?? 0 );
				$price = qtb_to_toman( (float) ( $p['price'] ?? 0 ) / pow( 10, $minor ), (string) ( $p['currency_code'] ?? 'IRT' ) );
				$cats  = array_map( function ( $c ) {
					return (string) ( $c['name'] ?? '' );
				}, (array) ( $d['categories'] ?? array() ) );
			}
			$items[] = array( 'type' => 'product', 'url' => (string) ( $d['permalink'] ?? '' ), 'title' => html_entity_decode( (string) ( $d['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ), 'price' => $price, 'cats' => $cats );
		} elseif ( 1 === $phase ) {
			$curl    = (string) ( $d['permalink'] ?? $d['link'] ?? '' );
			if ( '' === $curl && ! empty( $d['slug'] ) ) {
				$curl = $base . '/product-category/' . $d['slug'] . '/';
			}
			$items[] = array( 'type' => 'category', 'url' => $curl, 'title' => html_entity_decode( (string) ( $d['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ), 'count' => (int) ( $d['count'] ?? 0 ) );
		} else {
			$items[] = array( 'type' => 2 === $phase ? 'post' : 'page', 'url' => (string) ( $d['link'] ?? '' ), 'title' => html_entity_decode( wp_strip_all_tags( (string) ( $d['title']['rendered'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
		}
	}
	$items = array_values( array_filter( $items, function ( $i ) {
		return '' !== $i['url'];
	} ) );
	$more  = count( $data ) >= $per && $page * $per < QTB_STORE_MAX;
	if ( $more ) {
		return array( 'items' => $items, 'phase' => $phase, 'page' => $page + 1 );
	}
	if ( $phase >= 3 ) {
		return array( 'items' => $items, 'finished' => true );
	}
	return array( 'items' => $items, 'phase' => $phase + 1, 'page' => 1 );
}

/**
 * URLs from the sitemap (titles from the slugs).
 */
function qtb_store_sitemap( $project ) {
	$base = 'https://' . qtb_project_domain( $project );
	$seen = array();
	$urls = array();
	$todo = array( $base . '/sitemap_index.xml', $base . '/wp-sitemap.xml', $base . '/sitemap.xml' );
	$n    = 0;
	while ( $todo && $n < 25 && count( $urls ) < QTB_STORE_MAX * 2 ) {
		$u = array_shift( $todo );
		if ( isset( $seen[ $u ] ) ) {
			continue;
		}
		$seen[ $u ] = true;
		++$n;
		$res = qtb_http( $u, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			continue;
		}
		$xml = (string) wp_remote_retrieve_body( $res );
		if ( ! preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $xml, $m ) ) {
			continue;
		}
		$is_index = false !== stripos( $xml, '<sitemapindex' );
		foreach ( $m[1] as $loc ) {
			$loc = html_entity_decode( $loc );
			if ( $is_index ) {
				// Skip image / author / tag sitemaps.
				if ( ! preg_match( '/(image|author|tag|video)/i', $loc ) ) {
					$todo[] = $loc;
				}
			} else {
				$urls[] = $loc;
			}
		}
		if ( $urls && ! $is_index && ! $todo ) {
			break;
		}
	}
	if ( ! $urls ) {
		return new WP_Error( 'qtb_store_sitemap', 'سایت‌مپ پیدا نشد.' );
	}
	$items = array();
	foreach ( array_unique( $urls ) as $url ) {
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$slug = trim( (string) preg_replace( '#.*/([^/]+)/?$#', '$1', rtrim( $path, '/' ) ) );
		if ( '' === $slug ) {
			continue;
		}
		$items[] = array( 'type' => qtb_url_type_guess( $path ), 'url' => $url, 'title' => str_replace( array( '-', '_' ), ' ', $slug ) );
	}
	return array( 'items' => $items, 'finished' => true );
}

/**
 * Page type from a URL path.
 */
function qtb_url_type_guess( $path ) {
	$p = strtolower( (string) $path );
	if ( preg_match( '#/(product-category|product_cat|category-product|categories|cat|shop|store|c)/#u', $p ) || preg_match( '#/دسته#u', $p ) ) {
		return 'category';
	}
	if ( preg_match( '#/(product|products|p|item|dkp-\d+)/#u', $p ) || preg_match( '#/محصول#u', $p ) ) {
		return 'product';
	}
	if ( preg_match( '#/(blog|mag|magazine|article|articles|news|post|posts|learn|guide|wiki)/#u', $p ) || preg_match( '#/(مقاله|مجله|بلاگ)#u', $p ) ) {
		return 'post';
	}
	return 'page';
}
