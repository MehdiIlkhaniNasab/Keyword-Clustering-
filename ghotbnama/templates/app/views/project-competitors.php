<?php
/**
 * Competitors: domains in Google's top 10 across the looked-up clusters.
 *
 * @package Ghotbnama
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
		$dom[ $d ][ 'article' === $c['page'] ? 'blog' : 'land' ]++;
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
	<div class="qtb-empty">
		<?php echo qtb_icon( 'radar', 40 ); // phpcs:ignore ?>
		<h2>داده رقبا نیست</h2>
		<p>رقبا از ۱۰ نتیجه اول گوگل به دست می‌آیند. سرویس نتایج گوگل (Serper، DataForSEO یا SerpApi) را در تنظیمات وصل کنید و حالت خوشه‌بندی را «اقتصادی» یا «کامل» بگذارید.</p>
		<a class="qtb-btn is-primary" href="<?php echo esc_url( qtb_url( 'settings' ) ); ?>">تنظیمات اتصال‌ها</a>
	</div>
	<?php
	return;
endif;
$max = max( array_column( $dom, 'vol' ) );
?>
<div class="qtb-panel">
	<h2><?php echo qtb_icon( 'radar', 18 ); // phpcs:ignore ?> رقبای واقعی در گوگل <small>بر اساس <?php echo esc_html( qtb_num( $checked ) ); ?> کلاستر بررسی‌شده</small></h2>
	<div class="qtb-table-wrap"><table class="qtb-table">
		<thead><tr><th>دامنه</th><th>سهم حجم جستجو</th><th class="num">حضور</th><th class="num">لندینگ</th><th class="num">مقاله</th><th>نمونه کلاسترها</th></tr></thead>
		<tbody>
		<?php foreach ( array_slice( $dom, 0, 80, true ) as $d => $x ) : ?>
			<tr>
				<td><a class="qtb-ltr" href="<?php echo esc_url( 'https://' . $d ); ?>" target="_blank" rel="noopener"><b><?php echo esc_html( $d ); ?></b></a>
					<?php echo qtb_domain_in( $d, 'market_domains' ) ? '<span class="qtb-chip">بازارگاه</span>' : ( qtb_domain_in( $d, 'strong' ) ? '<span class="qtb-chip is-warn">دامنه قوی</span>' : '' ); ?></td>
				<td style="min-width:160px"><div class="qtb-bar"><i style="width:<?php echo esc_attr( round( 100 * $x['vol'] / max( 1, $max ) ) ); ?>%"></i></div><span class="qtb-small qtb-muted"><?php echo esc_html( qtb_num( $x['vol'] ) ); ?></span></td>
				<td class="num"><?php echo esc_html( qtb_num( $x['n'] ) ); ?></td>
				<td class="num"><?php echo esc_html( qtb_num( $x['land'] ) ); ?></td>
				<td class="num"><?php echo esc_html( qtb_num( $x['blog'] ) ); ?></td>
				<td class="qtb-small qtb-muted"><?php echo esc_html( implode( '، ', $x['ex'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
</div>
