<?php
/**
 * Competitors: domains in Google's top 10 across the looked-up clusters.
 *
 * @package Manzoomeh
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$dom     = array();
$checked = 0;
foreach ( $R['clusters'] as $c ) {
	if ( ! $c['serp'] ) {
		continue;
	}
	++$checked;
	foreach ( $c['serp']['competitors'] as $rank => $d ) {
		if ( ! isset( $dom[ $d ] ) ) {
			$dom[ $d ] = array( 'n' => 0, 'vol' => 0, 'land' => 0, 'blog' => 0, 'top3' => 0, 'ex' => array() );
		}
		$dom[ $d ]['n']++;
		$dom[ $d ]['vol'] += $c['vol'];
		$dom[ $d ][ 'blog' === $c['page'] ? 'blog' : 'land' ]++;
		if ( count( $dom[ $d ]['ex'] ) < 3 ) {
			$dom[ $d ]['ex'][] = $c['name'];
		}
	}
}
uasort( $dom, function ( $a, $b ) {
	return $b['vol'] <=> $a['vol'];
} );
if ( ! $dom ) :
	?>
	<div class="mzm-empty">
		<?php echo mzm_icon( 'radar', 40 ); // phpcs:ignore ?>
		<h2>داده رقبا نیست</h2>
		<p>رقبا از ۱۰ نتیجه اول گوگل به دست می‌آیند. سرویس نتایج گوگل (Serper، DataForSEO یا SerpApi) را در تنظیمات وصل کنید و حالت خوشه‌بندی را «اقتصادی» یا «کامل» بگذارید.</p>
		<a class="mzm-btn is-primary" href="<?php echo esc_url( mzm_url( 'settings' ) ); ?>">تنظیمات اتصال‌ها</a>
	</div>
	<?php
	return;
endif;
$max = max( array_column( $dom, 'vol' ) );
?>
<div class="mzm-panel">
	<h2><?php echo mzm_icon( 'radar', 18 ); // phpcs:ignore ?> رقبای واقعی در گوگل <small>بر اساس <?php echo esc_html( mzm_num( $checked ) ); ?> کلاستر بررسی‌شده</small></h2>
	<div class="mzm-table-wrap"><table class="mzm-table">
		<thead><tr><th>دامنه</th><th>سهم حجم جستجو</th><th class="num">حضور</th><th class="num">لندینگ</th><th class="num">مقاله</th><th>نمونه کلاسترها</th></tr></thead>
		<tbody>
		<?php foreach ( array_slice( $dom, 0, 80, true ) as $d => $x ) : ?>
			<tr>
				<td><a class="mzm-ltr" href="<?php echo esc_url( 'https://' . $d ); ?>" target="_blank" rel="noopener"><b><?php echo esc_html( $d ); ?></b></a>
					<?php echo mzm_domain_in( $d, 'market_domains' ) ? '<span class="mzm-chip">بازارگاه</span>' : ( mzm_domain_in( $d, 'strong' ) ? '<span class="mzm-chip is-warn">دامنه قوی</span>' : '' ); ?></td>
				<td style="min-width:160px"><div class="mzm-bar"><i style="width:<?php echo esc_attr( round( 100 * $x['vol'] / max( 1, $max ) ) ); ?>%"></i></div><span class="mzm-small mzm-muted"><?php echo esc_html( mzm_num( $x['vol'] ) ); ?></span></td>
				<td class="num"><?php echo esc_html( mzm_num( $x['n'] ) ); ?></td>
				<td class="num"><?php echo esc_html( mzm_num( $x['land'] ) ); ?></td>
				<td class="num"><?php echo esc_html( mzm_num( $x['blog'] ) ); ?></td>
				<td class="mzm-small mzm-muted"><?php echo esc_html( implode( '، ', $x['ex'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
</div>
