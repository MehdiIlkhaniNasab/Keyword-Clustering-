<?php
/**
 * Content calendar: monthly blocks of articles with links, dates and briefs.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$cal   = (array) $R['calendar'];
$arts  = (array) ( $cal['articles'] ?? array() );
$ideas = (array) ( $cal['ideas'] ?? array() );
$by_m  = array();
foreach ( $arts as $a ) {
	$by_m[ $a['month'] ][] = $a;
}
?>
<div class="qtb-toolbar">
	<div class="qtb-inline">
		<span class="qtb-muted qtb-small"><?php echo esc_html( sprintf( '%s مقاله در %s ماه · %s مقاله در ماه · از %s ایده', qtb_num( count( $arts ) ), qtb_num( $cal['meta']['months'] ?? 0 ), qtb_num( $cal['meta']['per_month'] ?? 0 ), qtb_num( $cal['meta']['candidates'] ?? 0 ) ) ); ?></span>
		<a class="qtb-small" href="<?php echo esc_url( qtb_project_url( $project['id'], 'settings' ) ); ?>#calendar">تغییر تعداد و روزهای انتشار</a>
	</div>
	<div class="qtb-actions">
		<button type="button" class="qtb-btn" data-qtb-calendar="<?php echo esc_attr( $project['id'] ); ?>"><?php echo qtb_icon( 'refresh', 16 ); // phpcs:ignore ?> بازسازی تقویم</button>
		<a class="qtb-btn is-primary" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'ghotbnama/v1/download/' . $project['id'] . '/calendar' ) ) ); ?>"><?php echo qtb_icon( 'download', 16 ); // phpcs:ignore ?> تقویم محتوا (اکسل)</a>
	</div>
</div>
<?php if ( ! $arts ) : ?>
	<div class="qtb-empty"><?php echo qtb_icon( 'calendar', 40 ); // phpcs:ignore ?><h2>مقاله‌ای برای تقویم نیست</h2><p>همه کلاسترهای بلاگ صفحه دارند یا کلاستر آموزشی پیدا نشد.</p></div>
<?php endif; ?>
<?php foreach ( $by_m as $m => $list ) : ?>
	<div class="qtb-month">
		<div class="qtb-month-h"><b>M<?php echo esc_html( qtb_fa( $m ) ); ?></b><span class="qtb-muted qtb-small">ماه <?php echo esc_html( qtb_fa( $m ) ); ?> · <?php echo esc_html( qtb_num( array_sum( array_column( $list, 'kp' ) ) ) ); ?> جستجوی ماهانه</span></div>
		<?php foreach ( $list as $a ) : ?>
			<div class="qtb-art">
				<span class="qtb-art-n"><?php echo esc_html( qtb_fa( $a['n'] ) ); ?></span>
				<div style="min-width:0">
					<h4><?php echo esc_html( $a['title'] ); ?></h4>
					<div class="qtb-small"><b><?php echo esc_html( $a['idea'] ); ?></b> · <?php echo esc_html( qtb_num( $a['kp'] ) ); ?> جستجو · <?php echo esc_html( $a['pillar'] ); ?> <?php echo $a['existing'] ? ' · <span class="qtb-chip is-optimize">آپدیت مقاله موجود</span>' : ''; ?></div>
					<?php if ( $a['desc'] ) : ?><div class="qtb-small qtb-muted"><?php echo esc_html( $a['desc'] ); ?></div><?php endif; ?>
					<details class="qtb-small"><summary style="cursor:pointer;color:var(--accent-ink)">بریف، کلمات هم‌کانسپت و منبع</summary>
						<?php if ( $a['outline'] ) : ?><ol style="margin:4px 0;padding-right:18px"><?php foreach ( $a['outline'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ol><?php endif; ?>
						<p style="margin:4px 0"><b>کلمات:</b> <?php echo esc_html( implode( '، ', array_map( function ( $kv ) { return $kv[0]; }, array_slice( $a['cloud'], 0, 15 ) ) ) ); ?></p>
						<?php if ( $a['faq'] ) : ?><p style="margin:4px 0"><b>FAQ:</b> <?php echo esc_html( implode( ' | ', $a['faq'] ) ); ?></p><?php endif; ?>
						<?php if ( $a['source'] ) : ?><p style="margin:4px 0"><b>منبع رقیب:</b> <?php echo qtb_link( $a['source'] ); // phpcs:ignore ?></p><?php endif; ?>
						<p style="margin:4px 0"><b>حدود <?php echo esc_html( qtb_num( $a['words'] ) ); ?> کلمه</b></p>
					</details>
				</div>
				<div class="qtb-links">
					<b class="qtb-small">لینک به لندینگ‌های فروش</b>
					<?php foreach ( array_slice( $a['out'], 0, 4 ) as $l ) : ?>
						<div title="<?php echo esc_attr( $l['anchor'] ); ?>"><b><?php echo esc_html( $l['anchor'] ); ?></b> ← <?php echo qtb_link( $l['url'], 30 ); // phpcs:ignore ?></div>
					<?php endforeach; ?>
				</div>
				<div class="qtb-art-date"><b><?php echo esc_html( $a['date'] ? qtb_jdate( $a['date'], 'day' ) : '—' ); ?></b><span class="qtb-muted"><?php echo esc_html( $a['date'] ? qtb_jweekday( $a['date'] ) : '' ); ?></span><br><span class="qtb-chip"><?php echo esc_html( $a['status'] ); ?></span></div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endforeach; ?>

<?php
$rest = array_values( array_filter( $ideas, function ( $x ) {
	return ! $x['pick'];
} ) );
if ( $rest ) :
	?>
	<details class="qtb-panel">
		<summary><?php echo qtb_icon( 'list', 18 ); // phpcs:ignore ?> ایده‌های بعدی (<?php echo esc_html( qtb_num( count( $rest ) ) ); ?>)</summary>
		<div class="qtb-table-wrap"><table class="qtb-table is-compact">
			<thead><tr><th>کلمه کلیدی</th><th class="num">جستجو</th><th>پیلار</th><th class="num">دقت نتایج</th><th class="num">تخصص</th><th>سفر مشتری</th><th class="num">سختی</th><th class="num">امتیاز</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $rest, 0, 150 ) as $x ) : ?>
				<tr><td><?php echo esc_html( $x['k'] ); ?></td><td class="num"><?php echo esc_html( qtb_num( $x['v'] ) ); ?></td><td><?php echo esc_html( $x['pillar'] ); ?></td><td class="num"><?php echo esc_html( '' === $x['clarity'] ? '—' : qtb_num( $x['clarity'] ) ); ?></td><td class="num"><?php echo esc_html( qtb_num( $x['expertise'] ) ); ?></td><td><?php echo esc_html( $x['journey'] ); ?></td><td class="num"><?php echo esc_html( qtb_num( $x['diff'] ) ); ?></td><td class="num"><?php echo esc_html( qtb_num( $x['cal_score'] ?? $x['score'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	</details>
<?php endif; ?>
