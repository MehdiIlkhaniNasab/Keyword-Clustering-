<?php
/**
 * Site inventory: products, product categories, blog posts and pages of the
 * project's website, used to map clusters to existing pages, find missing
 * landings, estimate revenue (product prices) and pick internal links.
 *
 * Sources (store_mode «auto» tries them in this order and skips silently when
 * one is not available):
 *   connector — the SeoReport Connector plugin on the client site (v1.5, the one Sokan
 *               uses): products with price and categories, product categories, tags,
 *               brands, posts and pages through /sokan/inventory (Sokan key — taken
 *               from the Sokan project of the same site when not entered), plus the
 *               orders of the last 90 days through /orders (SeoReport key, optional).
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
	if ( qtb_connector_conf( $project ) && ! qtb_store_is_local( $project ) ) {
		$out[] = 'connector';
	}
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
		$state = array( 'sources' => qtb_store_sources( $project ), 'si' => 0, 'phase' => 0, 'page' => 1, 'items' => array(), 'notes' => array(), 'source' => '', 'done' => false, 'sales' => array() );
	}
	if ( $state['si'] >= count( $state['sources'] ) ) {
		$state['done'] = true;
		return $state;
	}
	$src = $state['sources'][ $state['si'] ];
	$t0  = microtime( true );
	while ( ! $state['done'] && microtime( true ) - $t0 < 12 ) {
		switch ( $src ) {
			case 'connector':
				$r = qtb_store_connector_page( $project, $state );
				break;
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
		if ( isset( $r['sales'] ) ) {
			$state['sales'] = $r['sales'];
		}
		if ( ! empty( $r['finished'] ) ) {
			if ( 'connector' === $src && ! empty( $state['sales']['currency'] ) ) {
				foreach ( $state['items'] as &$it ) {
					if ( isset( $it['price'] ) ) {
						$it['price'] = qtb_to_toman( (float) $it['price'], $state['sales']['currency'] );
					}
				}
				unset( $it );
			}
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
	$l = array( 'connector' => 'افزونه رابط (SeoReport Connector)', 'local' => 'همین سایت (ووکامرس)', 'woo_rest' => 'API ووکامرس', 'wp_rest' => 'API عمومی وردپرس/ووکامرس', 'sitemap' => 'سایت‌مپ', 'none' => 'بدون اتصال' );
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
				'pid'   => (int) $id,
				'sold'  => (int) get_post_meta( $id, 'total_sales', true ),
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
	$sales = array();
	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'status' => array( 'wc-completed', 'wc-processing' ), 'date_created' => '>' . ( time() - 90 * DAY_IN_SECONDS ), 'limit' => 3000 ) );
		foreach ( $orders as $o ) {
			$items_o = array();
			foreach ( $o->get_items() as $it ) {
				$items_o[] = array( 'id' => (int) $it->get_product_id(), 'qty' => (int) $it->get_quantity(), 'total' => (float) $it->get_total() );
			}
			$sales = qtb_sales_add( $sales, array( 'total' => (float) $o->get_total(), 'currency' => $o->get_currency(), 'items' => $items_o, 'source' => (string) $o->get_meta( '_wc_order_attribution_source_type' ) ) );
		}
	}
	return array( 'items' => $items, 'finished' => true, 'sales' => $sales );
}

/**
 * Add one order to the sales summary (amounts in Toman).
 *
 * @param array $s     Summary {orders, revenue, organic_orders, organic_revenue, products: id → [qty, revenue]}.
 * @param array $order {total, currency, items: [{id, qty, total}], source}
 */
function qtb_sales_add( $s, $order ) {
	$s   = wp_parse_args( $s, array( 'orders' => 0, 'revenue' => 0, 'organic_orders' => 0, 'organic_revenue' => 0, 'products' => array(), 'days' => 90 ) );
	$cur = (string) ( $order['currency'] ?? 'IRT' );
	$tot = qtb_to_toman( (float) $order['total'], $cur );
	++$s['orders'];
	$s['revenue'] += $tot;
	if ( 'organic' === ( $order['source'] ?? '' ) ) {
		++$s['organic_orders'];
		$s['organic_revenue'] += $tot;
	}
	foreach ( (array) ( $order['items'] ?? array() ) as $it ) {
		$id = (int) ( $it['id'] ?? 0 );
		if ( ! $id ) {
			continue;
		}
		$row                    = $s['products'][ $id ] ?? array( 0, 0 );
		$s['products'][ $id ] = array( $row[0] + (int) $it['qty'], $row[1] + qtb_to_toman( (float) $it['total'], $cur ) );
	}
	return $s;
}

/**
 * Connector connection of a project: {url, key, report_key, from} or null.
 * The Sokan key is the project's own, else the one Sokan saved for the same site.
 */
function qtb_connector_conf( $project ) {
	$cfg  = $project['cfg'];
	$url  = qtb_project_root( $project );
	$key  = (string) $cfg['connector_key'];
	$from = 'project';
	if ( '' === $key ) {
		$sk = qtb_sokan_site_conn( qtb_project_domain( $project ) );
		if ( $sk ) {
			$key  = $sk['key'];
			$url  = $sk['url'] ?: $url;
			$from = 'sokan';
		}
	}
	if ( '' === $key && '' === (string) $cfg['report_key'] ) {
		return null;
	}
	return array( 'url' => untrailingslashit( $url ), 'key' => $key, 'report_key' => (string) $cfg['report_key'], 'from' => $from );
}

/**
 * The connector of the Sokan project with the same domain (url + decrypted Sokan key).
 */
function qtb_sokan_site_conn( $domain ) {
	global $wpdb;
	$t = $wpdb->prefix . 'sap_projects';
	if ( '' === $domain ) {
		return null;
	}
	$quiet = $wpdb->suppress_errors( true );
	$rows  = $wpdb->get_results( "SELECT site_url, options FROM $t", ARRAY_A ); // phpcs:ignore -- Sokan's table; absent when Sokan never ran here.
	$wpdb->suppress_errors( $quiet );
	foreach ( (array) $rows as $row ) {
		$opt = json_decode( (string) $row['options'], true ) ?: array();
		$url = (string) ( $opt['site_url'] ?? '' ) ?: (string) $row['site_url'];
		if ( qtb_host( $url ?: (string) $row['site_url'] ) !== $domain && qtb_host( (string) $row['site_url'] ) !== $domain ) {
			continue;
		}
		$key = qtb_decrypt( (string) ( $opt['site_key'] ?? '' ), 'seo-action-plan' );
		if ( '' !== $key ) {
			return array( 'url' => $url, 'key' => $key );
		}
	}
	return null;
}

/**
 * Site root of a project (for REST calls).
 */
function qtb_project_root( $project ) {
	return preg_match( '#^https?://#', (string) $project['site_url'] ) ? untrailingslashit( (string) $project['site_url'] ) : 'https://' . qtb_project_domain( $project );
}

/**
 * GET a SeoReport Connector route (falls back to ?rest_route= on sites without pretty permalinks).
 *
 * @param bool $report true: SeoReport key (/orders); false: Sokan key (/sokan/*).
 * @return array|WP_Error decoded JSON
 */
function qtb_connector_get( $project, $route, $args = array(), $report = false ) {
	$c = qtb_connector_conf( $project );
	if ( ! $c ) {
		return new WP_Error( 'qtb_conn_key', 'کلید افزونه رابط وارد نشده است.' );
	}
	$key = $report ? $c['report_key'] : $c['key'];
	if ( '' === $key ) {
		return new WP_Error( 'qtb_conn_key', $report ? 'کلید گزارش (SeoReport) وارد نشده است.' : 'کلید سکان افزونه رابط وارد نشده است.' );
	}
	$args = array_merge( $args, $report ? array( 'key' => $key ) : array( 'skey' => $key ) );
	$path = 'seoreport-connector/v1/' . $route;
	$head = array( $report ? 'X-SR-Key' : 'X-Sokan-Key' => $key, 'Accept' => 'application/json' );
	$res  = null;
	foreach ( array( add_query_arg( $args, $c['url'] . '/wp-json/' . $path ), add_query_arg( array_merge( $args, array( 'rest_route' => '/' . $path ) ), $c['url'] . '/' ) ) as $i => $url ) {
		$res = qtb_http( $url, array( 'timeout' => 60, 'headers' => $head ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'qtb_conn_net', qtb_net_error( $res ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 404 === $code && 0 === $i && 'rest_no_route' !== ( $data['code'] ?? '' ) ) {
			continue; // /wp-json/ itself missing: try ?rest_route=.
		}
		break;
	}
	if ( 403 === $code ) {
		return new WP_Error( 'qtb_conn_key', $report ? 'کلید گزارش (SeoReport) پذیرفته نشد.' : 'کلید سکان افزونه رابط پذیرفته نشد؛ همان کلیدی را بگذارید که در سکان › تنظیمات پروژه › اتصال به سایت وارد شده است.' );
	}
	if ( 404 === $code ) {
		return new WP_Error( 'qtb_conn_404', 'افزونه رابط روی سایت نصب نیست یا نسخه آن قدیمی است (نسخه ۱٫۵ لازم است؛ همان نسخه‌ای که سکان استفاده می‌کند).' );
	}
	if ( 200 !== $code || ! is_array( $data ) ) {
		return new WP_Error( 'qtb_conn_http', 'افزونه رابط پاسخ نداد (HTTP ' . $code . ').' );
	}
	return $data;
}

/**
 * One page of the connector. Phases: 0 products, 1 product categories, 2 brands,
 * 3 product tags, 4 posts, 5 pages, 6 orders (90 days).
 */
function qtb_store_connector_page( $project, $state ) {
	$phase   = (int) $state['phase'];
	$page    = (int) $state['page'];
	$sources = array( 'product', 'product_cat', '@brand', 'product_tag', 'post', 'page' );
	if ( $phase < 6 ) {
		$c = qtb_connector_conf( $project );
		if ( ! $c || '' === $c['key'] ) {
			return 0 === $phase ? new WP_Error( 'qtb_conn_key', 'کلید سکان افزونه رابط وارد نشده است.' ) : array( 'items' => array(), 'phase' => 6, 'page' => 1 );
		}
		$src = $sources[ $phase ];
		if ( '@brand' === $src ) {
			// Brand taxonomy name differs per plugin: read it from the source list.
			$src = '';
			$list = qtb_connector_get( $project, 'sokan/inventory', array( 'source' => '', 'page' => 1, 'per' => 10 ) );
			foreach ( is_wp_error( $list ) ? array() : (array) ( $list['sources'] ?? array() ) as $sv ) {
				if ( ! empty( $sv['brand'] ) ) {
					$src = (string) $sv['key'];
					break;
				}
			}
			if ( '' === $src ) {
				return array( 'items' => array(), 'phase' => $phase + 1, 'page' => 1 );
			}
		}
		$per  = 200;
		$data = qtb_connector_get( $project, 'sokan/inventory', array( 'source' => $src, 'page' => $page, 'per' => $per ) );
		if ( is_wp_error( $data ) ) {
			if ( 0 === $phase && 1 === $page ) {
				return $data;
			}
			return array( 'items' => array(), 'phase' => $phase + 1, 'page' => 1 );
		}
		$items = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $d ) {
			if ( empty( $d['url'] ) || ! empty( $d['redirect'] ) || ! empty( $d['noindex'] ) ) {
				continue; // Redirected / noindex pages are not targets.
			}
			if ( 'term' === ( $d['kind'] ?? '' ) ) {
				$row = array( 'type' => 'category', 'url' => (string) $d['url'], 'title' => (string) $d['title'], 'count' => (int) ( $d['count'] ?? 0 ), 'tax' => (string) ( $d['type'] ?? '' ), 'brand' => ! empty( $d['brand'] ) );
			} else {
				$type = in_array( $d['type'] ?? '', array( 'product', 'page' ), true ) ? $d['type'] : 'post';
				$row  = array( 'type' => $type, 'url' => (string) $d['url'], 'title' => (string) $d['title'], 'words' => (int) ( $d['words'] ?? 0 ) );
				if ( 'product' === $type ) {
					$pr           = (array) ( $d['product'] ?? array() );
					$row['pid']   = (int) $d['id'];
					$row['price'] = (float) ( $pr['price'] ?? 0 ); // Toman conversion happens with the order currency (see below).
					$row['cats']  = (array) ( $pr['cats'] ?? array() );
					$row['stock'] = (string) ( $pr['stock'] ?? '' );
					$row['rating'] = (float) ( $pr['rating'] ?? 0 );
				}
			}
			$items[] = $row;
		}
		return ! empty( $data['more'] ) && $page * $per < QTB_STORE_MAX
			? array( 'items' => $items, 'phase' => $phase, 'page' => $page + 1 )
			: array( 'items' => $items, 'phase' => $phase + 1, 'page' => 1 );
	}
	// Orders of the last 90 days (SeoReport key; skipped silently without it).
	$per    = 100;
	$offset = ( $page - 1 ) * $per;
	$data   = qtb_connector_get( $project, 'orders', array( 'after' => gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ), 'before' => gmdate( 'Y-m-d' ), 'per_page' => $per, 'offset' => $offset ), true );
	if ( is_wp_error( $data ) ) {
		return array( 'items' => array(), 'finished' => true );
	}
	$sales = (array) ( $state['sales'] ?? array() );
	foreach ( (array) ( $data['orders'] ?? array() ) as $o ) {
		$sales = qtb_sales_add( $sales, $o );
		$sales['currency'] = (string) ( $o['currency'] ?? '' );
	}
	$done = $offset + $per >= (int) ( $data['total'] ?? 0 ) || $offset + $per >= 3000 || empty( $data['orders'] );
	return $done ? array( 'items' => array(), 'finished' => true, 'sales' => $sales ) : array( 'items' => array(), 'phase' => 6, 'page' => $page + 1, 'sales' => $sales );
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
