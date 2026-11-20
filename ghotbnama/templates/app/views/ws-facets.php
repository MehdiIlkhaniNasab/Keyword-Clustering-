<?php
/**
 * Workshop: the facet dictionary — every word of the kept keywords in the lane of its
 * role. Drag a word to another lane to change its role; click it to merge it with
 * another spelling. The page tree of every topic is built from this table.
 *
 * @package Ghotbnama
 * @var array    $project
 * @var array    $ws
 * @var array    $kws
 * @var callable $url
 */

defined( 'ABSPATH' ) || exit;

$fx    = qtb_fx_get( $project['id'] );
$types = qtb_fx_types( $fx );
$res   = qtb_fx_reserved();
$vocab = qtb_fx_vocab( qtb_ws_active( $kws ) );
$miss  = array_diff_key( $vocab, (array) $fx['tokens'] );
$lanes = array();
foreach ( $types as $id => $ty ) {
	$lanes[ $id ] = array( 'toks' => array(), 'vol' => 0 );
}
foreach ( $vocab as $t => $s ) {
	if ( ! isset( $fx['tokens'][ $t ] ) ) {
		continue;
	}
	$row = $fx['tokens'][ $t ];
	$ty  = isset( $lanes[ $row['t'] ] ) ? $row['t'] : 'other';
	$lanes[ $ty ]['toks'][ $t ] = $s + array( 'c' => $row['c'], 'by' => $row['by'] ?? '' );
	$lanes[ $ty ]['vol']       += $s['v'];
}
// Own types by demand, then the fixed roles.
$own = array_diff_key( $lanes, $res );
uasort( $own, function ( $a, $b ) {
	return $b['vol'] <=> $a['vol'];
} );
$lanes = $own + array_intersect_key( $lanes, $res );
$tok_js = array();
foreach ( $vocab as $t => $s ) {
	$tok_js[] = array( $t, (int) $s['v'] );
}
$ex_of = function ( $s ) use ( $kws ) {
	$out = array();
	foreach ( $s['ex'] as $i ) {
		if ( isset( $kws[ $i ] ) ) {
			$out[] = $kws[ $i ]['k'];
		}
	}
	return implode( '، ', $out );
};
$stale = 0;
foreach ( $ws['topics'] as $t ) {
	$stale += qtb_fx_stale( $project, $t ) ? 1 : 0;
}
?>
<div class="qtb-fx" data-qtb-fx data-toks="<?php echo esc_attr( wp_json_encode( $tok_js, JSON_UNESCAPED_UNICODE ) ); ?>" data-types="<?php echo esc_attr( wp_json_encode( array_map( function ( $id, $ty ) { return array( $id, $ty['label'] ); }, array_keys( $types ), $types ), JSON_UNESCAPED_UNICODE ) ); ?>">
	<header class="qtb-fx-head">
		<div>
			<h2>فرهنگ ویژگی‌های پروژه</h2>
			<p>هر واژه‌ای که در کلمات نگه‌داشته آمده، اینجا در ردیف نقش خودش است. صفحه‌ها از ترکیب همین ویژگی‌ها ساخته می‌شوند: «دستبند» + «چرم» + «مردانه». اگر واژه‌ای در ردیف اشتباه است، آن را به ردیف درست بکشید؛ برای یکی کردن دو املا (مثل دو نوشتار یک برند) روی واژه بزنید.</p>
		</div>
		<div class="qtb-fx-meta">
			<span><b><?php echo esc_html( qtb_num( count( $vocab ) ) ); ?></b> واژه</span>
			<span><b><?php echo esc_html( qtb_num( count( (array) $fx['types'] ) ) ); ?></b> نوع ویژگی</span>
			<?php if ( '' !== $fx['built'] ) : ?><span>ساخت: <?php echo esc_html( qtb_jdate( $fx['built'] ) ); ?></span><?php endif; ?>
		</div>
	</header>

	<?php if ( ! $kws ) : ?>
		<p class="qtb-note"><?php echo qtb_icon( 'clock', 16 ); // phpcs:ignore ?><span>اول کلمات را غربال کنید؛ فرهنگ ویژگی‌ها از کلمات نگه‌داشته ساخته می‌شود.</span></p>
	<?php elseif ( $miss ) : ?>
		<div class="qtb-fx-build">
			<div>
				<b><?php echo esc_html( sprintf( '%s واژه هنوز نقش ندارد.', qtb_num( count( $miss ) ) ) ); ?></b>
				<span>هوش مصنوعی نوع‌های ویژگی همین کسب‌وکار را از روی واژه‌ها و پروفایل پیشنهاد می‌دهد و هر واژه را نقش‌گذاری می‌کند. بعد از آن همین جدول را بازبینی کنید. پردازش هر موضوع هم این کار را خودکار انجام می‌دهد.</span>
			</div>
			<button type="button" class="qtb-btn is-primary" data-qtb-wsgo="facets"><?php echo qtb_icon( 'bolt', 15 ); // phpcs:ignore ?> نقش‌گذاری <?php echo esc_html( qtb_num( count( $miss ) ) ); ?> واژه</button>
		</div>
	<?php endif; ?>
	<?php if ( $stale ) : ?>
		<p class="qtb-note is-warn"><?php echo qtb_icon( 'refresh', 16 ); // phpcs:ignore ?><span><?php echo esc_html( sprintf( 'فرهنگ ویژگی‌ها بعد از پردازش %s موضوع تغییر کرده است. برای اعمال، آن موضوع‌ها را «پردازش دوباره» کنید؛ کلاسترهای قفل‌شده دست نمی‌خورند.', qtb_num( $stale ) ) ); ?></span> <a class="qtb-btn is-small" href="<?php echo esc_url( $url( 'topics' ) ); ?>">موضوع‌ها</a></p>
	<?php endif; ?>

	<?php if ( $fx['tokens'] ) : ?>
	<div class="qtb-fx-tools">
		<input type="search" placeholder="جستجوی واژه…" data-qtb-fxsearch>
		<button type="button" class="qtb-btn is-small" data-qtb-fxtype><?php echo qtb_icon( 'plus', 14 ); // phpcs:ignore ?> نوع ویژگی جدید</button>
		<span class="qtb-small qtb-muted">ردیف‌های رنگی نوع‌های همین پروژه‌اند؛ چهار ردیف خاکستری نقش‌های ثابت.</span>
	</div>
	<div class="qtb-fx-lanes">
		<?php
		// One hue per facet type of the project (fixed roles stay grey).
		$hues = array( '#0E8A5F', '#3D6BD6', '#C8892A', '#9B4FB8', '#D2555A', '#1E9AA8', '#7A8A1F', '#B5671E', '#5468C9', '#A23E78' );
		$hn   = 0;
		?>
		<?php foreach ( $lanes as $id => $lane ) : ?>
			<?php $fixed = isset( $res[ $id ] ); ?>
			<section class="qtb-fx-lane<?php echo $fixed ? ' is-fixed is-' . esc_attr( $id ) : ''; ?>" data-fxlane="<?php echo esc_attr( $id ); ?>"<?php echo $fixed ? '' : ' style="--lane:' . esc_attr( $hues[ $hn++ % count( $hues ) ] ) . '"'; ?>>
				<div class="qtb-fx-lane-h">
					<b><?php echo esc_html( $types[ $id ]['label'] ); ?></b>
					<?php if ( $fixed ) : ?>
						<small><?php echo esc_html( $res[ $id ]['hint'] ); ?></small>
					<?php else : ?>
						<small>بخش: <?php echo esc_html( $types[ $id ]['plural'] ); ?></small>
						<button type="button" class="qtb-icon-btn" data-qtb-fxrename="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $types[ $id ]['label'] ); ?>" data-plural="<?php echo esc_attr( $types[ $id ]['plural'] ); ?>" title="تغییر نام نوع و بخش"><?php echo qtb_icon( 'edit', 13 ); // phpcs:ignore ?></button>
						<?php if ( ! $lane['toks'] ) : ?><button type="button" class="qtb-icon-btn is-del" data-qtb-fxdel="<?php echo esc_attr( $id ); ?>" title="حذف این نوع"><?php echo qtb_icon( 'x', 13 ); // phpcs:ignore ?></button><?php endif; ?>
					<?php endif; ?>
					<em><?php echo esc_html( qtb_num( count( $lane['toks'] ) ) . ' واژه · ' . qtb_num( $lane['vol'] ) ); ?></em>
				</div>
				<ul class="qtb-fx-toks" data-fxdrop="<?php echo esc_attr( $id ); ?>">
					<?php foreach ( $lane['toks'] as $t => $s ) : ?>
						<li draggable="true" data-tok="<?php echo esc_attr( $t ); ?>" data-c="<?php echo esc_attr( $s['c'] ); ?>" title="<?php echo esc_attr( $ex_of( $s ) ); ?>" class="<?php echo $s['c'] !== $t ? 'is-variant' : ''; ?><?php echo 'user' === $s['by'] ? ' is-user' : ''; ?>">
							<span><?php echo esc_html( $t ); ?></span>
							<?php if ( $s['c'] !== $t ) : ?><i title="یکی‌شده با «<?php echo esc_attr( $s['c'] ); ?>»">= <?php echo esc_html( $s['c'] ); ?></i><?php endif; ?>
							<em><?php echo esc_html( qtb_num( $s['v'] ) ); ?></em>
						</li>
					<?php endforeach; ?>
					<?php if ( ! $lane['toks'] ) : ?><li class="is-empty">واژه را اینجا رها کنید</li><?php endif; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>
