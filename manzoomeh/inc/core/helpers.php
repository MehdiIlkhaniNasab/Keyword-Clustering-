<?php
/**
 * Formatting, storage and security helpers.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convert Latin digits to Persian digits.
 */
function mzm_fa( $value ) {
	return strtr( (string) $value, array(
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
	) );
}

/**
 * Persian formatted number with thousands separator.
 */
function mzm_num( $n, $decimals = 0 ) {
	if ( null === $n || '' === $n ) {
		return '—';
	}
	$s = number_format( (float) $n, $decimals, '.', ',' );
	if ( $decimals > 0 ) {
		$s = rtrim( rtrim( $s, '0' ), '.' );
	}
	return mzm_fa( strtr( $s, array( ',' => '٬', '.' => '٫' ) ) );
}

/**
 * Short money label in Toman (۱٫۲ میلیون، ۳۵۰ هزار).
 */
function mzm_money( $toman ) {
	$t = (float) $toman;
	if ( $t >= 1000000000 ) {
		return mzm_num( $t / 1000000000, 1 ) . ' میلیارد تومان';
	}
	if ( $t >= 1000000 ) {
		return mzm_num( $t / 1000000, 1 ) . ' میلیون تومان';
	}
	if ( $t >= 1000 ) {
		return mzm_num( $t / 1000, 0 ) . ' هزار تومان';
	}
	return mzm_num( $t ) . ' تومان';
}

/**
 * Percent from a ratio (0.123 → ۱۲٫۳٪).
 */
function mzm_pct( $ratio, $decimals = 0 ) {
	if ( null === $ratio ) {
		return '—';
	}
	return mzm_num( $ratio * 100, $decimals ) . '٪';
}

/**
 * Normalize a URL for joining (host without www + decoded path).
 */
function mzm_norm_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$url   = rawurldecode( $url );
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return rtrim( $url, '/' );
	}
	$host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
	$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
	return $host . ( '' === $path ? '/' : $path );
}

/**
 * Host of a URL without www.
 */
function mzm_host( $url ) {
	$h = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
	if ( '' === $h && '' !== (string) $url && false === strpos( (string) $url, '/' ) ) {
		$h = (string) $url;
	}
	return strtolower( preg_replace( '/^www\./', '', $h ) );
}

/**
 * Shorten a URL for display: path only, decoded.
 */
function mzm_short_url( $url, $max = 60 ) {
	$url   = rawurldecode( (string) $url );
	$parts = wp_parse_url( $url );
	$path  = ( $parts['path'] ?? '/' );
	if ( '' === $path || '/' === $path ) {
		return ( $parts['host'] ?? '' ) ?: 'صفحه اصلی';
	}
	if ( mb_strlen( $path ) > $max ) {
		$path = mb_substr( $path, 0, $max - 1 ) . '…';
	}
	return $path;
}

/**
 * Compress data for storage.
 */
function mzm_pack( $data ) {
	return base64_encode( gzcompress( wp_json_encode( $data ), 6 ) );
}

/**
 * Decompress stored data.
 */
function mzm_unpack( $packed ) {
	if ( empty( $packed ) || ! is_string( $packed ) ) {
		return array();
	}
	$raw = base64_decode( $packed, true );
	if ( false === $raw ) {
		return array();
	}
	$json = @gzuncompress( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$data = false === $json ? null : json_decode( $json, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Encryption key. $salt_name lets the tool read secrets other Ronagh tools stored
 * on the same site (Sokan uses 'seo-action-plan').
 */
function mzm_crypto_key( $salt_name = 'manzoomeh' ) {
	return hash( 'sha256', wp_salt( 'auth' ) . $salt_name, true );
}

/**
 * Encrypt a secret (API keys, tokens) before storing it.
 */
function mzm_encrypt( $plain ) {
	$plain = (string) $plain;
	if ( '' === $plain ) {
		return '';
	}
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		return 'b64:' . base64_encode( $plain );
	}
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	return 'sb1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, mzm_crypto_key() ) );
}

/**
 * Decrypt a stored secret.
 */
function mzm_decrypt( $stored, $salt_name = 'manzoomeh' ) {
	$stored = (string) $stored;
	if ( '' === $stored ) {
		return '';
	}
	if ( 0 === strpos( $stored, 'b64:' ) ) {
		return (string) base64_decode( substr( $stored, 4 ) );
	}
	if ( 0 !== strpos( $stored, 'sb1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
		return $stored;
	}
	$raw   = base64_decode( substr( $stored, 4 ) );
	$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, mzm_crypto_key( $salt_name ) );
	return false === $plain ? '' : $plain;
}

/**
 * Mask a secret for display.
 */
function mzm_mask( $secret ) {
	$secret = (string) $secret;
	if ( strlen( $secret ) < 8 ) {
		return '' === $secret ? '' : '••••';
	}
	return substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 );
}

/**
 * Split a textarea / cell into a clean list (newline, comma, Persian comma).
 */
function mzm_list( $text ) {
	$lines = preg_split( '/\r\n|\r|\n|,|،|;|؛/u', (string) $text );
	return array_values( array_unique( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) );
}

/**
 * Sort rows by a key (desc by default), stable.
 */
function mzm_sort( $rows, $key, $desc = true ) {
	$i = 0;
	foreach ( $rows as &$r ) {
		$r['__i'] = $i++;
	}
	unset( $r );
	usort( $rows, function ( $a, $b ) use ( $key, $desc ) {
		$x = $a[ $key ] ?? 0;
		$y = $b[ $key ] ?? 0;
		$c = $desc ? $y <=> $x : $x <=> $y;
		return $c ?: $a['__i'] <=> $b['__i'];
	} );
	foreach ( $rows as &$r ) {
		unset( $r['__i'] );
	}
	unset( $r );
	return $rows;
}

/**
 * Current time in MySQL format (site timezone).
 */
function mzm_now() {
	return current_time( 'mysql' );
}

/**
 * Can the current user use the tool?
 */
function mzm_can() {
	return current_user_can( MZM_CAP ) || current_user_can( 'manage_options' );
}

/**
 * Inline SVG icon set (stroke icons, 24px grid).
 */
function mzm_icon( $name, $size = 18 ) {
	$paths = array(
		'orbit'    => '<circle cx="12" cy="12" r="3.2"/><ellipse cx="12" cy="12" rx="10" ry="4.2" transform="rotate(-28 12 12)"/><circle cx="19.6" cy="7.4" r="1.4" fill="currentColor" stroke="none"/><circle cx="4.6" cy="16.2" r="1" fill="currentColor" stroke="none"/>',
		'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'check'    => '<path d="M20 6 9 17l-5-5"/>',
		'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
		'alert'    => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
		'refresh'  => '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
		'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
		'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'log'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
		'spark'    => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/>',
		'link'     => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
		'trash'    => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
		'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
		'chart'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
		'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
		'sheet'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 3v18"/>',
		'layers'   => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
		'filter'   => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
		'coins'    => '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7"/><path d="M9 15v2c0 1.7 2.7 3 6 3s6-1.3 6-3v-5c0-1.6-2.4-2.9-5.5-3"/>',
		'radar'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><path d="M12 12 19 5"/><circle cx="12" cy="12" r="1"/>',
		'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		'bot'      => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M9 17h6"/>',
		'research' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'bolt'     => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
		'gap'      => '<path d="M4 12h5M15 12h5"/><path d="M9 7v10M15 7v10"/>',
		'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
		'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
		'store'    => '<path d="M3 9 5 4h14l2 5"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13v7h14v-7"/>',
		'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
		'play'     => '<path d="M7 4v16l13-8z"/>',
		'undo'     => '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
		'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17h.01"/>',
	);
	$p = $paths[ $name ] ?? $paths['grid'];
	return sprintf(
		'<svg class="mzm-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
		(int) $size,
		$p
	);
}

/**
 * Render a view template with variables.
 */
function mzm_view( $name, $vars = array() ) {
	$file = MZM_DIR . '/templates/app/views/' . $name . '.php';
	if ( ! file_exists( $file ) ) {
		return;
	}
	extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	include $file;
}

/**
 * Allow a long-running step to finish (time + memory).
 */
function mzm_raise_limits( $seconds = 120 ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	wp_raise_memory_limit( 'admin' );
}
