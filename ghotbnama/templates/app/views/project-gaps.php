<?php
/**
 * Opportunities & gaps: quick wins, pages to create / optimize, content gap
 * analysis from Google's top 10, cannibalization.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$sorted = qtb_sort( $R['clusters'], 'score' );
$quick  = array_filter( $sorted, function ( $c ) {
	return ! empty( $c['quick_win'] );
} );
$new_l  = array_filter( $sorted, function ( $c ) {
	return 'new' === $c['coverage'] && 'article' !== $c['page'];
} );
$opt    = array_filter( $sorted, function ( $c ) {
	return 'optimize' === $c['coverage'] && empty( $c['quick_win'] );
} );
$gaps   = array_filter( $sorted, function ( $c ) {
	return ! empty( $c['gap']['angle'] ) || ! empty( $c['gap']['gaps'] );
} );
$issues = array_filter( $sorted, function ( $c ) {
	return ! empty( $c['issues'] );
} );
$table  = function ( $list, $limit = 25 ) use ( $R ) {
	?>
	<div class="qtb-table-wrap"><table class="qtb-table">
		<thead><tr><th>کلاستر</th><th>پیلار</th><th>نوع صفحه</th><th class="num">حجم</th><th class="num">امتیاز</th><th class="num">سختی</th><th>صفحه / جایگاه</th></tr></thead>
		<tbody>
		<?php foreach ( array_slice( $list, 0, $limit ) as $c ) : ?>
			<tr>
				<td><b><?php echo esc_html( $c['name'] ); ?></b><br><span class="qtb-muted qtb-small"><?php echo esc_html( qtb_cluster_action( $c ) ); ?></span></td>
				<td><?php echo esc_html( $R['pillars'][ $c['pillar'] ]['name'] ?? '' ); ?></td>
				<td><?php echo qtb_chip_page( $c['page'], $c ); // phpcs:ignore ?></td>
				<td class="num"><?php echo esc_html( qtb_num( $c['vol'] ) ); ?></td>
				<td class="num"><?php echo qtb_score_badge( $c['score'] ); // phpcs:ignore ?></td>
				<td class="num"><?php echo esc_html( qtb_num( $c['diff'] ) ); ?></td>
				<td class="qtb-small"><?php echo $c['target'] ? qtb_link( $c['target']['url'], 36 ) : '—'; // phpcs:ignore ?><?php echo $c['serp'] && $c['serp']['own_pos'] ? ' · جایگاه ' . esc_html( qtb_num( $c['serp']['own_pos'] ) ) : ''; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php
};
?>
<div class="qtb-stats">
	<div class="qtb-stat is-gold"><span>برد سریع (رتبه ۴ تا ۲۰)</span><b><?php echo esc_html( qtb_num( count( $quick ) ) ); ?></b></div>
	<div class="qtb-stat is-green"><span>لندینگ جدید لازم</span><b><?php echo esc_html( qtb_num( count( $new_l ) ) ); ?></b></div>
	<div class="qtb-stat"><span>صفحه برای بهینه‌سازی</span><b><?php echo esc_html( qtb_num( count( $opt ) ) ); ?></b></div>
	<div class="qtb-stat"><span>هشدار کنیبالیزیشن</span><b><?php echo esc_html( qtb_num( count( $issues ) ) ); ?></b></div>
</div>

<?php if ( $quick ) : ?>
	<div class="qtb-panel is-attention">
		<h2><?php echo qtb_icon( 'bolt', 18 ); // phpcs:ignore ?> برد سریع <small>سایت در رتبه ۴ تا ۲۰ است؛ کمترین هزینه برای بیشترین رشد فروش</small></h2>
		<?php $table( $quick ); ?>
	</div>
<?php endif; ?>

<div class="qtb-panel">
	<h2><?php echo qtb_icon( 'plus', 18 ); // phpcs:ignore ?> لندینگ‌های فروش که سایت ندارد <small>به ترتیب ارزش فروش</small></h2>
	<?php
	if ( $new_l ) {
		$table( $new_l, 30 );
	} else {
		echo '<p class="qtb-muted">همه کلاسترهای فروش صفحه دارند (یا به صفحات سایت دسترسی نبود).</p>';
	}
	?>
</div>

<?php if ( $opt ) : ?>
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'refresh', 18 ); // phpcs:ignore ?> صفحات موجود برای بهینه‌سازی</h2>
		<?php $table( $opt, 30 ); ?>
	</div>
<?php endif; ?>

<h2 class="qtb-section-title"><?php echo qtb_icon( 'gap', 18 ); // phpcs:ignore ?> تحلیل شکاف محتوایی از ۱۰ نتیجه اول گوگل</h2>
<?php if ( ! $gaps ) : ?>
	<p class="qtb-note is-warn"><?php echo qtb_icon( 'alert', 16 ); // phpcs:ignore ?><span>تحلیل شکاف نیاز به هوش مصنوعی دارد (و برای دقت بیشتر سرویس نتایج گوگل). اتصال را در تنظیمات بررسی کنید و خوشه‌بندی را دوباره اجرا کنید.</span></p>
<?php endif; ?>
<?php
foreach ( array_slice( $gaps, 0, 60 ) as $c ) :
	$g = $c['gap'];
	?>
	<details class="qtb-panel">
		<summary>
			<?php echo qtb_chip_page( $c['page'], $c ); // phpcs:ignore ?>
			<span><?php echo esc_html( $c['name'] ); ?></span>
			<span class="qtb-muted qtb-small"><?php echo esc_html( qtb_num( $c['vol'] ) ); ?> جستجو</span>
			<?php echo qtb_score_badge( $c['score'] ); // phpcs:ignore ?>
			<?php echo empty( $g['serp'] ) ? '<span class="qtb-chip">بدون داده گوگل</span>' : '<span class="qtb-chip is-serp">۱۰ نتیجه گوگل</span>'; ?>
		</summary>
		<?php if ( ! empty( $g['intent'] ) ) : ?>
			<p class="qtb-note"><?php echo qtb_icon( 'target', 16 ); // phpcs:ignore ?><span><b>نیت کاربر:</b> <?php echo esc_html( $g['intent'] ); ?><br><b>زاویه برنده ما:</b> <?php echo esc_html( $g['angle'] ?? '' ); ?></span></p>
		<?php endif; ?>
		<div class="qtb-gap">
			<div>
				<h4>شکاف‌ها (چیزی که رقبا ندارند)</h4>
				<ul><?php foreach ( (array) ( $g['gaps'] ?? array() ) as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul>
				<h4>باید حتماً پوشش داده شود</h4>
				<ul><?php foreach ( (array) ( $g['must_cover'] ?? array() ) as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul>
				<h4>سوالات متداول</h4>
				<ul><?php foreach ( (array) ( $g['faq'] ?? array() ) as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul>
			</div>
			<div>
				<h4>عنوان و متا</h4>
				<p class="qtb-brief-title" data-qtb-copy style="margin:0"><?php echo esc_html( $g['title'] ?? '' ); ?></p>
				<p class="qtb-small qtb-muted" data-qtb-copy style="margin:2px 0 6px"><?php echo esc_html( $g['meta'] ?? '' ); ?></p>
				<h4>ساختار صفحه (H2)</h4>
				<ol class="qtb-small" style="margin:0;padding-right:18px"><?php foreach ( (array) ( $g['outline'] ?? array() ) as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ol>
				<p class="qtb-small"><b>حدود <?php echo esc_html( qtb_num( $g['words'] ?? 0 ) ); ?> کلمه</b> · اسکیما: <?php echo esc_html( $g['schema'] ?? '' ); ?><br><b>دعوت به اقدام:</b> <?php echo esc_html( $g['cta'] ?? '' ); ?></p>
				<?php if ( $c['serp'] ) : ?>
					<h4>۱۰ نتیجه اول گوگل</h4>
					<ul class="qtb-serp-list">
						<?php foreach ( array_slice( (array) $c['serp']['titles'], 0, 10 ) as $i => $t ) : ?>
							<li><i><?php echo esc_html( qtb_num( $i + 1 ) ); ?></i><span><?php echo esc_html( $t ); ?></span><span class="qtb-muted"><?php echo esc_html( $c['serp']['competitors'][ $i ] ?? '' ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</details>
<?php endforeach; ?>

<?php if ( $issues ) : ?>
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'alert', 18 ); // phpcs:ignore ?> کنیبالیزیشن و هشدارها</h2>
		<div class="qtb-table-wrap"><table class="qtb-table">
			<thead><tr><th>کلاستر</th><th>مشکل</th><th>آدرس‌ها</th></tr></thead>
			<tbody>
			<?php
			foreach ( $issues as $c ) :
				foreach ( $c['issues'] as $is ) :
					?>
					<tr><td><b><?php echo esc_html( $c['name'] ); ?></b></td><td><?php echo esc_html( $is['text'] ); ?></td><td class="qtb-small"><?php echo implode( '<br>', array_map( 'qtb_link', (array) $is['urls'] ) ); // phpcs:ignore ?></td></tr>
					<?php
				endforeach;
			endforeach;
			?>
			</tbody>
		</table></div>
	</div>
<?php endif; ?>
