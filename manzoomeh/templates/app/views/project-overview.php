<?php
/**
 * Project overview: run panel, summary, pillars and best opportunities.
 *
 * @package Manzoomeh
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$job     = mzm_data_get( $project['id'], 'job', array() );
$running = $job && empty( $job['done'] ) && empty( $job['error'] );
$st      = $job ? mzm_job_status( $job ) : null;
$meta    = $R['meta'];
$input   = mzm_data_get( $project['id'], 'input', array() );
$has_sheet = '' !== mzm_sheet_id( $project['cfg']['sheet_url'] );
$mode    = mzm_serp_mode( $project );
?>
<div class="mzm-panel is-hero" id="run" data-mzm-run="<?php echo esc_attr( $project['id'] ); ?>" data-running="<?php echo $running ? 1 : 0; ?>">
	<div class="mzm-run">
		<div>
			<h2><?php echo mzm_icon( 'play', 18 ); // phpcs:ignore ?> اجرای خوشه‌بندی</h2>
			<p>
				<?php if ( $has_sheet ) : ?>
					کلمات از تب «<?php echo esc_html( $project['cfg']['input_tab'] ); ?>» گوگل شیت پروژه خوانده می‌شوند.
				<?php else : ?>
					لینک گوگل شیت وارد نشده؛ فایل کلمات (XLSX یا CSV، ستون اول کلمه، ستون دوم حجم) را بارگذاری کنید.
				<?php endif; ?>
				روش: <b><?php echo esc_html( array( 'free' => 'رایگان (زبانی + هوش مصنوعی)', 'economy' => 'اقتصادی (۱۰ نتیجه اول گوگل برای کلمات کلیدی هر گروه)', 'full' => 'کامل (۱۰ نتیجه اول گوگل برای همه کلمات)' )[ $mode ] ); ?></b>
			</p>
			<div class="mzm-run-actions">
				<?php if ( $has_sheet ) : ?>
					<button type="button" class="mzm-btn is-gold" data-mzm-start="sheet"><?php echo mzm_icon( 'sheet', 16 ); // phpcs:ignore ?> خواندن از گوگل شیت و اجرا</button>
				<?php endif; ?>
				<?php if ( $input ) : ?>
					<button type="button" class="mzm-btn" data-mzm-start="upload"><?php echo mzm_icon( 'refresh', 16 ); // phpcs:ignore ?> اجرای دوباره با <?php echo esc_html( mzm_num( count( $input ) ) ); ?> کلمه فعلی</button>
				<?php endif; ?>
				<?php if ( ! empty( $R['clusters'] ) ) : ?>
					<button type="button" class="mzm-btn is-ghost" style="color:#E9F5EF!important" data-mzm-start="upload" data-from="structure" title="بدون دریافت دوباره نتایج گوگل؛ فقط ساختار، امتیاز، شکاف و تقویم">بازسازی ساختار و تقویم</button>
				<?php endif; ?>
			</div>
			<form class="mzm-upload" data-mzm-upload="<?php echo esc_attr( $project['id'] ); ?>">
				<?php echo mzm_icon( 'upload', 18 ); // phpcs:ignore ?>
				<input type="file" name="file" accept=".xlsx,.csv,.tsv,.txt" required>
				<button class="mzm-btn is-small" type="submit">بارگذاری و اجرا</button>
			</form>
		</div>
		<div>
			<div class="mzm-progress"><i style="width:<?php echo esc_attr( $st ? $st['pct'] : 0 ); ?>%"></i></div>
			<p class="mzm-small" data-mzm-label style="margin:0 0 8px;color:#E9F5EF"><?php echo esc_html( $st ? ( $st['error'] ? 'خطا: ' . $st['error'] : ( $st['done'] ? 'آخرین اجرا کامل شد.' : $st['label'] . '…' ) ) : 'آماده اجرا' ); ?></p>
			<ul class="mzm-steps" data-mzm-steps>
				<?php foreach ( ( $st ? $st['steps'] : array_map( function ( $k, $l ) { return array( 'key' => $k, 'label' => $l, 'state' => 'todo' ); }, array_keys( mzm_pipeline_steps() ), mzm_pipeline_steps() ) ) as $s ) : ?>
					<li class="is-<?php echo esc_attr( $s['state'] ); ?>"><i></i><?php echo esc_html( $s['label'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<ul class="mzm-log" data-mzm-log <?php echo $st && $st['log'] ? '' : 'hidden'; ?>>
				<?php foreach ( $st ? $st['log'] : array() as $l ) : ?>
					<li class="is-<?php echo esc_attr( $l['l'] ); ?>"><b><?php echo esc_html( mzm_fa( $l['t'] ) ); ?></b><?php echo esc_html( $l['m'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</div>

<?php
if ( empty( $R['clusters'] ) ) {
	return;
}
$cov  = (array) ( $meta['coverage'] ?? array() );
$pmax = max( 1, max( array_column( $R['pillars'], 'vol' ) ?: array( 1 ) ) );
?>
<div class="mzm-stats is-6">
	<div class="mzm-stat"><span>کلمات معتبر</span><b><?php echo esc_html( mzm_num( $meta['keywords'] ?? 0 ) ); ?></b><small>از <?php echo esc_html( mzm_num( $meta['input'] ?? 0 ) ); ?> · <?php echo esc_html( mzm_num( $meta['removed'] ?? 0 ) ); ?> حذف</small></div>
	<div class="mzm-stat is-green"><span>کلاستر (صفحه)</span><b><?php echo esc_html( mzm_num( count( $R['clusters'] ) ) ); ?></b><small><?php echo esc_html( mzm_num( $meta['checked'] ?? 0 ) ); ?> بررسی‌شده با گوگل</small></div>
	<div class="mzm-stat"><span>پیلار</span><b><?php echo esc_html( mzm_num( count( $R['pillars'] ) ) ); ?></b><small>حجم کل <?php echo esc_html( mzm_num( $meta['volume'] ?? 0 ) ); ?></small></div>
	<div class="mzm-stat"><span>صفحه جدید لازم</span><b><?php echo esc_html( mzm_num( $cov['new'] ?? 0 ) ); ?></b><small><?php echo esc_html( mzm_num( $cov['optimize'] ?? 0 ) ); ?> صفحه برای بهینه‌سازی</small></div>
	<div class="mzm-stat is-gold"><span>برد سریع</span><b><?php echo esc_html( mzm_num( $meta['quick'] ?? 0 ) ); ?></b><small>رتبه ۴ تا ۲۰</small></div>
	<div class="mzm-stat is-gold"><span>فروش بالقوه ماهانه</span><b style="font-size:18px"><?php echo esc_html( ( $meta['revenue'] ?? 0 ) > 0 ? mzm_money( $meta['revenue'] ) : '—' ); ?></b><small><?php echo ( $meta['revenue'] ?? 0 ) > 0 ? 'با نرخ تبدیل ' . esc_html( mzm_num( $project['cfg']['cr'], 1 ) ) . '٪' : 'میانگین سبد را وارد کنید'; ?></small></div>
</div>

<div class="mzm-grid-2" style="gap:16px">
	<div class="mzm-panel">
		<div class="mzm-panel-head"><h2><?php echo mzm_icon( 'layers', 18 ); // phpcs:ignore ?> پیلارها</h2><a class="mzm-small" href="<?php echo esc_url( mzm_project_url( $project['id'], 'clusters' ) ); ?>">همه کلاسترها</a></div>
		<div class="mzm-pillars">
			<?php foreach ( array_slice( $R['pillars'], 0, 14 ) as $p ) : ?>
				<a class="mzm-pillar-row" href="<?php echo esc_url( mzm_project_url( $project['id'], 'clusters', array( 'pl' => $p['id'] ) ) ); ?>">
					<b><?php echo esc_html( $p['name'] ); ?></b>
					<span class="mzm-bar"><i style="width:<?php echo esc_attr( round( 100 * $p['vol'] / $pmax ) ); ?>%"></i></span>
					<span class="mzm-small mzm-muted mzm-hide-sm"><?php echo esc_html( mzm_num( count( $p['clusters'] ) ) ); ?> کلاستر</span>
					<span class="mzm-small num"><?php echo esc_html( mzm_num( $p['vol'] ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="mzm-panel">
		<h2><?php echo mzm_icon( 'chart', 18 ); // phpcs:ignore ?> ترکیب صفحات</h2>
		<?php
		$pages = (array) ( $meta['pages'] ?? array() );
		$total = max( 1, array_sum( $pages ) );
		foreach ( mzm_page_types() as $k => $t ) :
			$n = (int) ( $pages[ $k ] ?? 0 );
			?>
			<div class="mzm-hbar"><span><?php echo mzm_chip_page( $k ); // phpcs:ignore ?></span><span class="mzm-bar"><i style="width:<?php echo esc_attr( round( 100 * $n / $total ) ); ?>%"></i></span><b class="num"><?php echo esc_html( mzm_num( $n ) ); ?></b></div>
		<?php endforeach; ?>
		<h3>اولویت‌ها</h3>
		<?php
		$prio = (array) ( $meta['priority'] ?? array() );
		foreach ( array( 'P1', 'P2', 'P3' ) as $k ) :
			$n = (int) ( $prio[ $k ] ?? 0 );
			?>
			<div class="mzm-hbar"><span><?php echo mzm_chip_prio( $k ); // phpcs:ignore ?></span><span class="mzm-bar"><i class="<?php echo 'P1' === $k ? 'is-gold' : ''; ?>" style="width:<?php echo esc_attr( round( 100 * $n / max( 1, count( $R['clusters'] ) ) ) ); ?>%"></i></span><b class="num"><?php echo esc_html( mzm_num( $n ) ); ?></b></div>
		<?php endforeach; ?>
		<p class="mzm-muted mzm-small" style="margin:10px 0 0">
			هزینه این اجرا: هوش مصنوعی حدود <?php echo esc_html( mzm_num( $meta['ai_cost'] ?? 0, 3 ) ); ?> دلار · نتایج گوگل: <?php echo esc_html( mzm_num( $meta['serp_new'] ?? 0 ) ); ?> درخواست جدید، <?php echo esc_html( mzm_num( $meta['serp_cache'] ?? 0 ) ); ?> از کش.
		</p>
	</div>
</div>

<div class="mzm-panel">
	<div class="mzm-panel-head"><h2><?php echo mzm_icon( 'bolt', 18 ); // phpcs:ignore ?> برترین فرصت‌های فروش</h2><a class="mzm-small" href="<?php echo esc_url( mzm_project_url( $project['id'], 'gaps' ) ); ?>">فرصت‌ها و شکاف‌ها</a></div>
	<div class="mzm-table-wrap">
		<table class="mzm-table">
			<thead><tr><th>کلاستر</th><th>پیلار</th><th>نوع صفحه</th><th class="num">حجم</th><th class="num">امتیاز</th><th>اولویت</th><th>وضعیت</th><th>اقدام بعدی</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( mzm_sort( $R['clusters'], 'score' ), 0, 12 ) as $c ) : ?>
				<tr>
					<td><b><?php echo esc_html( $c['name'] ); ?></b> <span class="mzm-muted mzm-small">(<?php echo esc_html( mzm_num( count( $c['kws'] ) ) ); ?> کلمه)</span></td>
					<td><?php echo esc_html( $R['pillars'][ $c['pillar'] ]['name'] ?? '' ); ?></td>
					<td><?php echo mzm_chip_page( $c['page'] ); // phpcs:ignore ?></td>
					<td class="num"><?php echo esc_html( mzm_num( $c['vol'] ) ); ?></td>
					<td class="num"><?php echo mzm_score_badge( $c['score'] ); // phpcs:ignore ?></td>
					<td><?php echo mzm_chip_prio( $c['priority'] ); // phpcs:ignore ?><?php echo ! empty( $c['quick_win'] ) ? ' <span class="mzm-chip is-quick">برد سریع</span>' : ''; ?></td>
					<td><?php echo mzm_chip_cov( $c['coverage'] ); // phpcs:ignore ?></td>
					<td class="mzm-small"><?php echo esc_html( mzm_cluster_action( $c ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
