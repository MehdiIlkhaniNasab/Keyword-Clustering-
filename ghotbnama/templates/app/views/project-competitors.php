<?php
/**
 * Competitors from Seo Signal files: comparison, what wins in this market, AI analysis
 * of each competitor, and the keywords they rank for that the project lacks.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$comps = qtb_comps( $pid );
$lab   = qtb_comp_type_labels();
$cov   = $comps ? qtb_comp_coverage( $pid ) : array( 'by' => array(), 'total' => array( 'n' => 0, 'vol' => 0 ) );
$has   = (bool) qtb_data_get( $pid, 'keywords', array() );
$tcol  = array( 'product' => '#E5AE88', 'brand' => '#9BB7DC', 'tag' => '#C7B6E8', 'category' => '#8CC5A3', 'article' => '#DDC27A', 'home' => '#B9C6BF', 'other' => '#DCE3DF' );
qtb_view( 'ws-job', array( 'project' => $project, 'job' => qtb_job_settle( $pid, 'ws_job' ) ) );
?>
<div class="qtb-panel">
	<div class="qtb-panel-head">
		<h2><?php echo qtb_icon( 'radar', 18 ); // phpcs:ignore ?> رقبا <small>خروجی «کلمات دامنه» سئو سیگنال (JSON) برای ۳ تا ۵ رقیب؛ برای به‌روز کردن یک رقیب، فایل تازه‌اش را دوباره بارگذاری کنید</small></h2>
		<label class="qtb-check qtb-small"><input type="checkbox" data-qtb-compexport <?php checked( ! empty( $project['cfg']['export_comp'] ) ); ?>> شیت «رقبا» در فایل خروجی</label>
	</div>
	<form class="qtb-upload is-light" data-qtb-compup="<?php echo esc_attr( $pid ); ?>" style="margin-top:0">
		<?php echo qtb_icon( 'upload', 18 ); // phpcs:ignore ?>
		<input type="file" name="files[]" accept=".json,application/json" multiple required>
		<button class="qtb-btn is-small is-primary" type="submit">بارگذاری رقیب</button>
		<span class="qtb-small qtb-muted"><?php echo esc_html( sprintf( '%s از %s رقیب', qtb_num( count( $comps ) ), qtb_num( QTB_COMP_MAX ) ) ); ?></span>
	</form>
	<?php if ( ! $comps ) : ?>
		<p class="qtb-small qtb-muted" style="margin:10px 0 0">با فایل رقبا: کلماتی که رقیب دارد و پروژه ندارد پیدا می‌شود، دو کلمه‌ای که رقیب با یک صفحه گرفته در یک کلاستر می‌روند (بدون هزینه نتایج گوگل)، نوع صفحه‌ای که در این بازار رتبه می‌گیرد در پیشنهاد نوع صفحه می‌آید و هوش مصنوعی هر رقیب را تحلیل می‌کند.</p>
	<?php endif; ?>
</div>

<?php if ( $comps ) : ?>
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'chart', 18 ); // phpcs:ignore ?> مقایسه رقبا</h2>
		<div class="qtb-table-wrap"><table class="qtb-table">
			<thead><tr><th>دامنه</th><th class="num">کلمات رتبه‌دار</th><th class="num">در ۳ نتیجه اول</th><th class="num">ترافیک تخمینی ماهانه</th><th class="num">از کلمات پروژه ما</th><th>ترافیک از چه صفحاتی</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $comps as $c ) : $st = qtb_comp_stats( $c ); $cv = $cov['by'][ $c['domain'] ] ?? array( 'n' => 0, 'vol' => 0 ); ?>
				<tr>
					<td><b class="qtb-ltr"><?php echo esc_html( $c['domain'] ); ?></b><br><span class="qtb-small qtb-muted"><?php echo esc_html( 'بارگذاری ' . qtb_jdate( $c['added'] ) ); ?></span></td>
					<td class="num"><?php echo esc_html( qtb_num( count( $c['kws'] ) ) ); ?></td>
					<td class="num"><?php echo esc_html( qtb_num( $st['top3'] ) ); ?></td>
					<td class="num"><b><?php echo esc_html( qtb_num( $st['traffic'] ) ); ?></b></td>
					<td class="num"><?php echo esc_html( qtb_num( $cv['n'] ) ); ?> <span class="qtb-small qtb-muted">(<?php echo esc_html( qtb_pct( $cv['vol'] / max( 1, $cov['total']['vol'] ) ) ); ?> حجم)</span></td>
					<td style="min-width:200px">
						<div class="qtb-mix">
							<?php foreach ( $st['types_traffic'] as $ty => $tr ) : $w = 100 * $tr / max( 1, $st['traffic'] ); if ( $w < 2 ) { continue; } ?>
								<i style="width:<?php echo esc_attr( round( $w, 1 ) ); ?>%;background:<?php echo esc_attr( $tcol[ $ty ] ?? '#ccc' ); ?>" title="<?php echo esc_attr( ( $lab[ $ty ] ?? $ty ) . ' ' . round( $w ) . '٪' ); ?>"></i>
							<?php endforeach; ?>
						</div>
						<span class="qtb-small qtb-muted"><?php echo esc_html( implode( '، ', array_map( function ( $ty, $tr ) use ( $lab, $st ) { return ( $lab[ $ty ] ?? $ty ) . ' ' . qtb_num( round( 100 * $tr / max( 1, $st['traffic'] ) ) ) . '٪'; }, array_slice( array_keys( $st['types_traffic'] ), 0, 3 ), array_slice( $st['types_traffic'], 0, 3 ) ) ) ); ?></span>
					</td>
					<td class="qtb-nowrap">
						<button type="button" class="qtb-btn is-small <?php echo empty( $c['ai'] ) ? 'is-primary' : ''; ?>" data-qtb-compai="<?php echo esc_attr( $c['domain'] ); ?>"><?php echo qtb_icon( 'bot', 14 ); // phpcs:ignore ?> <?php echo empty( $c['ai'] ) ? 'تحلیل هوشمند' : 'تحلیل دوباره'; ?></button>
						<button type="button" class="qtb-icon-btn is-del" data-qtb-compdel="<?php echo esc_attr( $c['domain'] ); ?>" title="حذف رقیب"><?php echo qtb_icon( 'trash', 15 ); // phpcs:ignore ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<div class="qtb-inline qtb-small" style="margin-top:8px">
			<?php foreach ( $tcol as $ty => $col ) : ?><span><i class="qtb-dot" style="display:inline-block;background:<?php echo esc_attr( $col ); ?>"></i><?php echo esc_html( $lab[ $ty ] ); ?></span><?php endforeach; ?>
		</div>
	</div>

	<?php foreach ( $comps as $c ) : $st = qtb_comp_stats( $c ); $ai = $c['ai'] ?? null; ?>
		<details class="qtb-panel qtb-comp" <?php echo $ai ? 'open' : ''; ?>>
			<summary><h2 style="display:inline"><?php echo qtb_icon( 'globe', 18 ); // phpcs:ignore ?> <span class="qtb-ltr"><?php echo esc_html( $c['domain'] ); ?></span> <small><?php echo $ai ? esc_html( 'تحلیل ' . qtb_jdate( $c['ai_at'] ?? '' ) ) : 'بدون تحلیل هوشمند'; ?></small></h2></summary>
			<?php if ( $ai ) : ?>
				<p class="qtb-comp-sum"><?php echo esc_html( $ai['summary'] ); ?></p>
				<?php if ( ! empty( $ai['structure'] ) ) : ?><p class="qtb-note"><?php echo qtb_icon( 'layers', 16 ); // phpcs:ignore ?><span><b>ساختار:</b> <?php echo esc_html( $ai['structure'] ); ?></span></p><?php endif; ?>
				<div class="qtb-grid-2" style="gap:16px">
					<div><h3 class="qtb-small">نقاط قوت</h3><ul class="qtb-list is-good"><?php foreach ( (array) $ai['strengths'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul></div>
					<div><h3 class="qtb-small">نقاط ضعف</h3><ul class="qtb-list is-bad"><?php foreach ( (array) $ai['weaknesses'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul></div>
				</div>
				<h3 class="qtb-small">فرصت‌های ما</h3>
				<div class="qtb-table-wrap"><table class="qtb-table is-compact"><tbody>
					<?php foreach ( (array) $ai['opportunities'] as $o ) : ?><tr><td><b><?php echo esc_html( $o['target'] ); ?></b></td><td><?php echo esc_html( $o['action'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
				<h3 class="qtb-small">اقدام‌های اولویت‌دار</h3>
				<ol class="qtb-small"><?php foreach ( (array) $ai['actions'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ol>
			<?php endif; ?>
			<div class="qtb-grid-2" style="gap:16px;margin-top:10px">
				<div>
					<h3 class="qtb-small">پرترافیک‌ترین صفحات</h3>
					<div class="qtb-table-wrap"><table class="qtb-table is-compact">
						<thead><tr><th>صفحه</th><th>نوع</th><th class="num">کلمه</th><th class="num">ترافیک</th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $st['pages'], 0, 12 ) as $pg ) : ?>
							<tr><td class="qtb-small"><a href="<?php echo esc_url( rtrim( $c['url'], '/' ) . '/' . ltrim( $pg['page'], '/' ) ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( implode( '، ', $pg['kws'] ) ); ?>"><?php echo esc_html( qtb_mb_cut( urldecode( $pg['page'] ), 48 ) ); ?></a></td><td class="qtb-small"><?php echo esc_html( $lab[ $pg['type'] ] ?? '' ); ?></td><td class="num"><?php echo esc_html( qtb_num( $pg['n'] ) ); ?></td><td class="num"><?php echo esc_html( qtb_num( round( $pg['traffic'] ) ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				</div>
				<div>
					<h3 class="qtb-small">کلمات پرحجمی که رقیب در جایگاه ۴ تا ۱۰ است (قابل سبقت)</h3>
					<div class="qtb-table-wrap"><table class="qtb-table is-compact">
						<thead><tr><th>کلمه</th><th class="num">جایگاه</th><th class="num">حجم</th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $st['beatable'], 0, 12 ) as $b ) : ?>
							<tr><td><?php echo esc_html( $b['k'] ); ?></td><td class="num"><?php echo esc_html( qtb_num( $b['rank'] ) ); ?></td><td class="num"><?php echo esc_html( qtb_num( $b['v'] ) ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $st['beatable'] ) : ?><tr><td colspan="3" class="qtb-muted">موردی نیست.</td></tr><?php endif; ?>
						</tbody>
					</table></div>
				</div>
			</div>
			<?php if ( $c['share'] ) : ?>
				<p class="qtb-small qtb-muted" style="margin:10px 0 0">سهم کلیک دامنه‌ها در کلمات این رقیب (سئو سیگنال): <?php echo esc_html( implode( '، ', array_map( function ( $s ) { return $s[0] . ' ' . qtb_num( $s[1] ) . '٪'; }, $c['share'] ) ) ); ?></p>
			<?php endif; ?>
		</details>
	<?php endforeach; ?>

	<?php
	$minv  = absint( $_GET['minv'] ?? 0 ); // phpcs:ignore
	$multi = ! empty( $_GET['multi'] ); // phpcs:ignore
	$gap   = array_values( array_filter( qtb_comp_gap( $pid, $minv ), function ( $g ) use ( $multi ) {
		return ! $multi || $g['n'] >= 2;
	} ) );
	$per   = 200;
	$pages = (int) ceil( count( $gap ) / $per );
	$cur   = max( 1, min( max( 1, $pages ), absint( $_GET['pg'] ?? 1 ) ) ); // phpcs:ignore
	?>
	<div class="qtb-panel" id="gap" data-qtb-gap="<?php echo esc_attr( $pid ); ?>">
		<div class="qtb-panel-head">
			<h2><?php echo qtb_icon( 'gap', 18 ); // phpcs:ignore ?> کلماتی که رقبا دارند و پروژه ندارد <small><?php echo esc_html( qtb_num( count( $gap ) ) ); ?> کلمه در ۱۰ نتیجه اول رقبا؛ انتخاب کنید و به پروژه اضافه کنید (غربال و موضوع‌بندی می‌شوند)</small></h2>
			<form class="qtb-filters" method="get" action="<?php echo esc_url( qtb_project_url( $pid, 'competitors' ) ); ?>">
				<input type="number" name="minv" value="<?php echo esc_attr( $minv ); ?>" placeholder="حداقل حجم" style="width:110px">
				<label class="qtb-check qtb-small"><input type="checkbox" name="multi" value="1" <?php checked( $multi ); ?>> حداقل ۲ رقیب</label>
				<button class="qtb-btn is-small" type="submit">اعمال</button>
			</form>
		</div>
		<?php if ( ! $has ) : ?>
			<p class="qtb-note is-warn"><?php echo qtb_icon( 'alert', 16 ); // phpcs:ignore ?><span>اول کلمات پروژه را در کارگاه دریافت و غربال کنید؛ بعد کلمات رقبا به آن اضافه می‌شوند.</span></p>
		<?php endif; ?>
		<div class="qtb-table-wrap"><table class="qtb-table is-compact">
			<thead><tr><th><input type="checkbox" data-qtb-gapall></th><th>کلمه</th><th class="num">حجم</th><th class="num">رقبا</th><th class="num">بهترین جایگاه</th><th>صفحه رقیب</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $gap, ( $cur - 1 ) * $per, $per ) as $g ) : ?>
				<tr>
					<td><input type="checkbox" data-qtb-gapk="<?php echo esc_attr( $g['k'] ); ?>" data-v="<?php echo esc_attr( $g['v'] ); ?>"></td>
					<td><?php echo esc_html( $g['k'] ); ?></td>
					<td class="num"><?php echo esc_html( qtb_num( $g['v'] ) ); ?></td>
					<td class="num"><?php echo esc_html( qtb_num( $g['n'] ) ); ?></td>
					<td class="num"><?php echo esc_html( qtb_num( $g['best'] ) ); ?></td>
					<td class="qtb-small" title="<?php echo esc_attr( implode( "\n", array_map( function ( $h ) { return $h[0] . ' #' . $h[1] . ' ' . $h[2]; }, $g['hits'] ) ) ); ?>"><?php echo esc_html( ( $lab[ $g['type'] ] ?? '' ) . ' · ' . $g['hits'][0][0] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $gap ) : ?><tr><td colspan="6" class="qtb-muted">کلمه جدیدی نیست؛ پروژه همه کلمات ۱۰ نتیجه اول رقبا را دارد.</td></tr><?php endif; ?>
			</tbody>
		</table></div>
		<?php qtb_pager( $pages, $cur, array_filter( array( 'minv' => $minv ?: '', 'multi' => $multi ? 1 : '' ), 'strlen' ) ); ?>
		<?php if ( $has ) : ?>
			<div class="qtb-bulkbar" data-qtb-gapbar hidden>
				<span><b data-n>۰</b> کلمه انتخاب شد</span>
				<button type="button" class="qtb-btn is-small is-gold" data-qtb-gapadd><?php echo qtb_icon( 'plus', 14 ); // phpcs:ignore ?> افزودن به پروژه</button>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>
