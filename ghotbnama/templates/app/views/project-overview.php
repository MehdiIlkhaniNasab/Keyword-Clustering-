<?php
/**
 * Project overview: where the project stands, the market (demand, competitors), the
 * opportunities and the 12-month roadmap.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$ws       = qtb_ws_get( $pid );
$kws      = qtb_data_get( $pid, 'keywords', array() );
$clusters = qtb_picture_clusters( $project, $R );
$dem      = qtb_demand( $kws );
$comps    = qtb_comps( $pid );
$cnt      = array_count_values( array_map( function ( $k ) {
	return $k['ws'] ?? 'keep';
}, $kws ) );
$appr     = count( array_filter( $ws['topics'], function ( $t ) {
	return 'approved' === $t['status'];
} ) );
$wurl     = function ( $s ) use ( $pid ) {
	return qtb_project_url( $pid, 'workshop', array( 'ws' => $s ) );
};
$pvol = array();
foreach ( $clusters as $c ) {
	$pvol[ $c['page'] ] = ( $pvol[ $c['page'] ] ?? 0 ) + (int) $c['vol'];
}
$cvol = max( 1, array_sum( $pvol ) );
// Next step of the project.
if ( ! $kws ) {
	$next = array( 'شروع: دریافت و غربال کلمات', $wurl( 'profile' ) );
} elseif ( ! $ws['topics'] ) {
	$next = array( 'ساخت موضوع‌ها', $wurl( 'triage' ) );
} elseif ( $appr < count( $ws['topics'] ) ) {
	$next = array( 'بازبینی و تأیید موضوع‌ها', $wurl( 'topics' ) );
} elseif ( empty( $ws['finalized'] ) || ! empty( $ws['dirty'] ) ) {
	$next = array( 'ساخت / به‌روزرسانی خروجی', $wurl( 'final' ) );
} else {
	$next = array( 'دانلود کیورد ریسرچ', qtb_project_url( $pid, 'export' ) );
}
?>
<div class="qtb-stats is-6">
	<div class="qtb-stat"><span>کلمات پروژه</span><b><?php echo esc_html( qtb_num( ( $cnt['keep'] ?? 0 ) + ( $cnt['unsure'] ?? 0 ) ) ); ?></b><small><?php echo esc_html( sprintf( '%s حذف · %s پارکینگ · %s نیاز به تصمیم', qtb_num( $cnt['remove'] ?? 0 ), qtb_num( $cnt['park'] ?? 0 ), qtb_num( $cnt['unsure'] ?? 0 ) ) ); ?></small></div>
	<div class="qtb-stat"><span>حجم جستجوی ماهانه بازار</span><b><?php echo esc_html( qtb_num( $dem['total'] ) ); ?></b><small>جمع حجم کلمات نگه‌داشته</small></div>
	<div class="qtb-stat is-green"><span>پیلار</span><b><?php echo esc_html( qtb_num( count( $ws['topics'] ) ) ); ?></b><small><?php echo esc_html( qtb_num( $appr ) ); ?> تأییدشده</small></div>
	<div class="qtb-stat is-green"><span>صفحه (کلاستر)</span><b><?php echo esc_html( qtb_num( count( $clusters ) ) ); ?></b><small><?php echo esc_html( qtb_num( $pvol ? count( array_filter( $clusters, function ( $c ) { return 'article' !== $c['page']; } ) ) : 0 ) ); ?> صفحه فروش</small></div>
	<div class="qtb-stat"><span>رقبا</span><b><?php echo esc_html( qtb_num( count( $comps ) ) ); ?></b><small><?php echo $comps ? 'از سئو سیگنال' : '<a href="' . esc_url( qtb_project_url( $pid, 'competitors' ) ) . '">بارگذاری فایل رقبا</a>'; // phpcs:ignore ?></small></div>
	<div class="qtb-stat is-gold"><span>قدم بعدی</span><b style="font-size:15px;line-height:1.6"><a href="<?php echo esc_url( $next[1] ); ?>"><?php echo esc_html( $next[0] ); ?></a></b><small><?php echo ! empty( $ws['finalized'] ) ? esc_html( 'آخرین خروجی ' . qtb_jdate( $ws['finalized'] ) . ( ! empty( $ws['dirty'] ) ? ' (تغییرات تازه دارد)' : '' ) ) : 'هنوز خروجی ساخته نشده'; ?></small></div>
</div>

<?php if ( ! $kws ) : ?>
	<div class="qtb-empty"><?php echo qtb_icon( 'compass', 40 ); // phpcs:ignore ?><h2>پروژه هنوز کلمه ندارد</h2><p>از «کارگاه کلاسترینگ» پروفایل کسب‌وکار را پر کنید و کلمات را از گوگل شیت یا فایل دریافت کنید. فایل رقبا (سئو سیگنال) را هم می‌توانید همین حالا در تب «رقبا» بارگذاری کنید.</p><a class="qtb-btn is-primary" href="<?php echo esc_url( $wurl( 'profile' ) ); ?>">رفتن به کارگاه</a></div>
	<?php
	return;
endif;
?>

<div class="qtb-grid-2" style="gap:16px">
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'chart', 18 ); // phpcs:ignore ?> تقاضای بازار <small>حجم جستجو بر اساس نیت کاربر</small></h2>
		<?php foreach ( $dem['by'] as $in => $v ) : ?>
			<div class="qtb-hbar"><span><?php echo esc_html( qtb_intents()[ $in ] ?? $in ); ?></span><span class="qtb-bar"><i class="<?php echo in_array( $in, array( 't', 'l' ), true ) ? 'is-gold' : ''; ?>" style="width:<?php echo esc_attr( round( 100 * $v / max( 1, $dem['total'] ) ) ); ?>%"></i></span><b class="num"><?php echo esc_html( qtb_pct( $v / max( 1, $dem['total'] ) ) ); ?></b></div>
		<?php endforeach; ?>
		<?php if ( $pvol ) : ?>
			<h3>حجم بر اساس نوع صفحه</h3>
			<?php foreach ( qtb_page_types() as $k => $t ) : $v = (int) ( $pvol[ $k ] ?? 0 ); ?>
				<div class="qtb-hbar"><span><?php echo qtb_chip_page( $k ); // phpcs:ignore ?></span><span class="qtb-bar"><i style="width:<?php echo esc_attr( round( 100 * $v / $cvol ) ); ?>%"></i></span><b class="num"><?php echo esc_html( qtb_pct( $v / $cvol ) ); ?></b></div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<div class="qtb-panel">
		<div class="qtb-panel-head"><h2><?php echo qtb_icon( 'layers', 18 ); // phpcs:ignore ?> پیلارها</h2><a class="qtb-small" href="<?php echo esc_url( qtb_project_url( $pid, 'clusters' ) ); ?>">ویرایش پیلارها و کلاسترها</a></div>
		<?php
		$pl = array();
		foreach ( $ws['topics'] as $t ) {
			$v = 0;
			foreach ( $t['kws'] as $i ) {
				$v += in_array( $kws[ $i ]['ws'] ?? 'keep', array( 'keep', 'unsure' ), true ) ? (int) ( $kws[ $i ]['v'] ?? 0 ) : 0;
			}
			$pl[] = array( 'id' => $t['id'], 'name' => $t['name'], 'vol' => $v, 'n' => count( $t['clusters'] ), 'status' => $t['status'] );
		}
		usort( $pl, function ( $a, $b ) {
			return $b['vol'] <=> $a['vol'];
		} );
		$pmax = max( 1, $pl ? $pl[0]['vol'] : 1 );
		?>
		<div class="qtb-pillars">
			<?php foreach ( array_slice( $pl, 0, 14 ) as $p ) : ?>
				<a class="qtb-pillar-row" href="<?php echo esc_url( qtb_project_url( $pid, 'clusters', array( 'tid' => $p['id'] ) ) ); ?>">
					<b><?php echo esc_html( $p['name'] ); ?></b>
					<span class="qtb-bar"><i style="width:<?php echo esc_attr( round( 100 * $p['vol'] / $pmax ) ); ?>%"></i></span>
					<span class="qtb-small qtb-muted qtb-hide-sm"><?php echo esc_html( qtb_num( $p['n'] ) ); ?> صفحه</span>
					<span class="qtb-small num"><?php echo esc_html( qtb_num( $p['vol'] ) ); ?></span>
				</a>
			<?php endforeach; ?>
			<?php if ( ! $pl ) : ?><p class="qtb-muted qtb-small">هنوز موضوعی ساخته نشده؛ در کارگاه بعد از غربال «ساخت موضوع‌ها» را بزنید.</p><?php endif; ?>
		</div>
	</div>
</div>

<?php
// Market: who owns our keywords (Seo Signal files), and Google's top 10 when it was looked up.
$cov   = $comps ? qtb_comp_coverage( $pid ) : null;
$serpd = array();
foreach ( $clusters as $c ) {
	foreach ( (array) ( $c['serp']['competitors'] ?? array() ) as $d ) {
		$serpd[ $d ] = ( $serpd[ $d ] ?? 0 ) + (int) $c['vol'];
	}
}
arsort( $serpd );
$gap = $comps ? qtb_comp_gap( $pid, 0 ) : array();
?>
<div class="qtb-grid-2" style="gap:16px">
	<div class="qtb-panel">
		<div class="qtb-panel-head"><h2><?php echo qtb_icon( 'radar', 18 ); // phpcs:ignore ?> سهم رقبا از بازار ما</h2><a class="qtb-small" href="<?php echo esc_url( qtb_project_url( $pid, 'competitors' ) ); ?>">تحلیل رقبا</a></div>
		<?php if ( $cov ) : ?>
			<p class="qtb-small qtb-muted" style="margin-top:0">چه سهمی از حجم جستجوی کلمات پروژه را هر رقیب در ۱۰ نتیجه اول دارد (سئو سیگنال).</p>
			<?php foreach ( $cov['by'] as $d => $x ) : ?>
				<div class="qtb-hbar"><span class="qtb-ltr"><?php echo esc_html( $d ); ?></span><span class="qtb-bar"><i style="width:<?php echo esc_attr( round( 100 * $x['vol'] / max( 1, $cov['total']['vol'] ) ) ); ?>%"></i></span><b class="num"><?php echo esc_html( qtb_pct( $x['vol'] / max( 1, $cov['total']['vol'] ) ) ); ?></b></div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( $serpd ) : ?>
			<h3>پرتکرار در ۱۰ نتیجه اول گوگل (کلمات بررسی‌شده)</h3>
			<p class="qtb-small" style="margin:0"><?php echo esc_html( implode( '، ', array_slice( array_keys( $serpd ), 0, 10 ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! $cov && ! $serpd ) : ?>
			<p class="qtb-muted qtb-small">فایل کلمات دامنه ۳ تا ۵ رقیب را از سئو سیگنال در تب «رقبا» بارگذاری کنید تا سهم بازار، فرصت‌ها و تحلیل هوشمند هر رقیب اینجا بیاید.</p>
		<?php endif; ?>
	</div>
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'bolt', 18 ); // phpcs:ignore ?> فرصت‌ها</h2>
		<?php if ( $gap ) : ?>
			<p class="qtb-small" style="margin-top:0"><b><?php echo esc_html( qtb_num( count( $gap ) ) ); ?></b> کلمه که رقبا در ۱۰ نتیجه اول دارند و در پروژه نیست، با حجم <?php echo esc_html( qtb_num( array_sum( array_column( $gap, 'v' ) ) ) ); ?>. <a href="<?php echo esc_url( qtb_project_url( $pid, 'competitors' ) ); ?>#gap">انتخاب و افزودن</a></p>
			<ul class="qtb-list"><?php foreach ( array_slice( $gap, 0, 6 ) as $g ) : ?><li><?php echo esc_html( $g['k'] . ' (' . qtb_num( $g['v'] ) . ')' ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<?php
		$quick = array_filter( $clusters, function ( $c ) {
			return ! empty( $c['quick_win'] );
		} );
		$beat  = array();
		foreach ( $comps as $c ) {
			$beat = array_merge( $beat, array_slice( qtb_comp_stats( $c )['beatable'], 0, 4 ) );
		}
		?>
		<?php if ( $quick ) : ?>
			<p class="qtb-small"><b><?php echo esc_html( qtb_num( count( $quick ) ) ); ?></b> صفحه سایت شما رتبه ۴ تا ۲۰ دارد (برد سریع): <?php echo esc_html( implode( '، ', array_slice( array_column( $quick, 'name' ), 0, 5 ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( $beat ) : ?>
			<p class="qtb-small" style="margin-bottom:4px">رقبا در این کلمات پرحجم فقط جایگاه ۴ تا ۱۰ دارند (قابل سبقت):</p>
			<p class="qtb-small qtb-muted" style="margin:0"><?php echo esc_html( implode( '، ', array_map( function ( $b ) { return $b['k'] . ' (#' . qtb_num( $b['rank'] ) . ')'; }, array_slice( $beat, 0, 10 ) ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! $gap && ! $quick && ! $beat ) : ?>
			<p class="qtb-muted qtb-small">با فایل رقبا و اتصال سایت (افزونه رابط) فرصت‌ها اینجا می‌آیند: کلماتی که رقبا دارند و ما نداریم، صفحاتی که رتبه ۴ تا ۲۰ دارند، و کلماتی که رقبا ضعیف‌اند.</p>
		<?php endif; ?>
	</div>
</div>

<?php if ( $clusters ) : $rm = qtb_roadmap( $clusters ); ?>
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'calendar', 18 ); // phpcs:ignore ?> نقشه راه ۱۲ ماهه <small>ترافیک ماهانه بالقوه وقتی صفحات هر فاز به جایگاه ۵ تا ۲ برسند؛ بازه است، نه وعده</small></h2>
		<div class="qtb-phases">
			<?php foreach ( $rm as $n => $ph ) : ?>
				<div class="qtb-phase">
					<div class="qtb-phase-h"><i><?php echo esc_html( qtb_fa( $n ) ); ?></i><div><b><?php echo esc_html( $ph['name'] ); ?></b><small><?php echo esc_html( $ph['months'] ); ?></small></div></div>
					<p class="qtb-small qtb-muted"><?php echo esc_html( $ph['what'] ); ?></p>
					<div class="qtb-phase-n"><b><?php echo esc_html( qtb_num( $ph['pages'] ) ); ?></b> صفحه · حجم <?php echo esc_html( qtb_num( $ph['vol'] ) ); ?></div>
					<div class="qtb-inline" style="gap:4px;margin:6px 0"><?php foreach ( $ph['types'] as $ty => $c ) : ?><span class="qtb-chip is-<?php echo esc_attr( $ty ); ?>"><?php echo esc_html( ( qtb_page_types()[ $ty ][0] ?? $ty ) . ' ' . qtb_num( $c ) ); ?></span><?php endforeach; ?></div>
					<div class="qtb-phase-tr"><?php echo esc_html( qtb_num( round( $ph['low'] ) ) . ' تا ' . qtb_num( round( $ph['high'] ) ) ); ?> <small>بازدید در ماه</small></div>
					<?php if ( $ph['ex'] ) : ?><p class="qtb-small" style="margin:6px 0 0"><?php echo esc_html( implode( '، ', $ph['ex'] ) ); ?></p><?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php $lo = array_sum( array_column( $rm, 'low' ) ); $hi = array_sum( array_column( $rm, 'high' ) ); ?>
		<p class="qtb-small" style="margin:12px 0 0">پایان ۱۲ ماه، اگر همه صفحات ساخته و به صفحه اول برسند: <b><?php echo esc_html( qtb_num( round( $lo ) ) . ' تا ' . qtb_num( round( $hi ) ) ); ?></b> بازدید ارگانیک در ماه از همین کلمات. ستون «فاز» در نقشه صفحات فایل خروجی هم آمده است.</p>
	</div>
<?php endif; ?>
