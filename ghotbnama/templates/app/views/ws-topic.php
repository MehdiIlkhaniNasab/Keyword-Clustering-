<?php
/**
 * Workshop: review board of one topic — drag keywords between clusters, park or remove,
 * see the Google evidence, set the page, comment → project rules, approve.
 *
 * @package Ghotbnama
 * @var array    $project
 * @var array    $ws
 * @var array    $kws
 * @var int      $tid
 * @var callable $url
 */

defined( 'ABSPATH' ) || exit;

$ti     = qtb_ws_topic_index( $ws, $tid );
$t      = $ws['topics'][ $ti ];
$states = qtb_ws_topic_states();
$conf_l = array( 'high' => 'اطمینان زیاد', 'mid' => 'اطمینان متوسط', 'low' => 'اطمینان کم' );
$why_l  = array( 'serp' => 'گوگل تأیید کرد: نتایج مشترک با صفحه', 'lexical' => 'شباهت معنایی (بدون سرپ)', 'ai' => 'جابه‌جایی هوش مصنوعی', 'user' => 'تصمیم شما', 'comp' => 'رقیب هر دو را با یک صفحه گرفته', 'facet' => 'همان ویژگی‌های صفحه', 'tail' => 'کم‌حجم، بدون شاهد گوگل؛ به نزدیک‌ترین صفحه رفت', 'near' => 'کم‌حجم و شبیه هیچ صفحه‌ای نبود؛ به نزدیک‌ترین صفحه رفت', 'syn' => 'مترادف: نتایج گوگل تقریباً یکسان', 'serp_kw' => 'نتایج گوگل خود این کلمه به این صفحه نزدیک‌تر بود' );
$facet  = 'facet' === ( $project['cfg']['engine'] ?? 'facet' ) || array_filter( $t['clusters'], function ( $c ) {
	return ! empty( $c['fx'] );
} );
$fxd    = qtb_fx_get( $project['id'] );
$ftypes = qtb_fx_types( $fxd );
$fwhy   = qtb_fx_why_labels();
$TAIL   = 8; // Keywords shown before «+N more».
$act_l  = array( 'move' => 'انتقال به', 'new' => 'کلاستر جدید', 'remove' => 'حذف', 'park' => 'پارکینگ' );
$cl_js  = array();
foreach ( $t['clusters'] as $c ) {
	$cl_js[] = array( $c['id'], $c['name'] );
}
$tp_js = array();
foreach ( $ws['topics'] as $o ) {
	if ( (int) $o['id'] !== (int) $tid ) {
		$tp_js[] = array( $o['id'], $o['name'] );
	}
}
$names = array_column( $t['clusters'], 'name', 'id' );
// Clusters of the other pillars (to move a keyword into, or merge a cluster with).
$all_js = array();
foreach ( $ws['topics'] as $o ) {
	if ( (int) $o['id'] === (int) $tid ) {
		continue;
	}
	foreach ( $o['clusters'] as $oc ) {
		$all_js[] = array( (int) $o['id'], (int) $oc['id'], $oc['name'], $o['name'] );
	}
}
$aside = array( 'park' => array(), 'remove' => array() );
foreach ( $t['kws'] as $i ) {
	$st = $kws[ $i ]['ws'] ?? 'keep';
	if ( isset( $aside[ $st ] ) ) {
		$aside[ $st ][] = $i;
	}
}
$low = 0;
$sug = 0;
$rev = 0;
$tails = 0;
foreach ( $t['clusters'] as $c ) {
	foreach ( $c['kws'] as $i ) {
		$m    = $c['meta'][ $i ] ?? array();
		$low += 'low' === ( $m['conf'] ?? '' ) ? 1 : 0;
		$rev += ! empty( $m['review'] ) ? 1 : 0;
		$tails += 'tail' === ( $m['why'] ?? '' ) ? 1 : 0;
		$sug += ! empty( $m['ai'] ) && 'keep' !== $m['ai']['action'] && 'ai' !== ( $m['why'] ?? '' ) ? 1 : 0;
	}
}
$next = null;
foreach ( $ws['topics'] as $o ) {
	if ( (int) $o['id'] !== (int) $tid && in_array( $o['status'], array( 'ready', 'new' ), true ) ) {
		$next = $o;
		break;
	}
}
$m = $t['metrics'];
// A keyword chip.
$chip = function ( $i, $c, $n = 0 ) use ( $kws, $conf_l, $why_l, $act_l, $names, $TAIL ) {
	$kw   = $kws[ $i ];
	$meta = $c ? ( $c['meta'][ $i ] ?? array() ) : array();
	$conf = $meta['conf'] ?? ( $c ? 'mid' : '' );
	$tip  = array();
	if ( $c ) {
		$tip[] = ( $conf_l[ $conf ] ?? '' ) . ' · ' . ( $why_l[ $meta['why'] ?? '' ] ?? '' );
		if ( ! empty( $meta['comp'] ) ) {
			$tip[] = 'شاهد رقیب: ' . $meta['comp'];
		}
		if ( isset( $meta['shared'] ) && $meta['shared'] >= 0 && 'serp' === ( $meta['why'] ?? '' ) ) {
			$tip[] = 'نتایج مشترک با سر کلاستر: ' . qtb_num( $meta['shared'] );
		}
	}
	$ai = $meta['ai'] ?? null;
	$flag = '';
	if ( $ai && 'keep' !== $ai['action'] ) {
		$to = 'move' === $ai['action'] ? ( $names[ (int) preg_replace( '/\D/', '', (string) $ai['to'] ) ] ?? (string) $ai['to'] ) : ( 'new' === $ai['action'] ? (string) $ai['to'] : '' );
		$txt = ( 'ai' === ( $meta['why'] ?? '' ) ? 'هوش مصنوعی جابه‌جا کرد' : 'پیشنهاد هوش مصنوعی (اعمال نشد)' ) . ': ' . ( $act_l[ $ai['action'] ] ?? '' ) . ( '' !== $to ? ' «' . $to . '»' : '' ) . ' — ' . $ai['reason'];
		$tip[] = $txt;
		$flag  = '<b class="qtb-ai-flag' . ( 'ai' === ( $meta['why'] ?? '' ) ? ' is-done' : '' ) . '" title="' . esc_attr( $txt ) . '">AI</b>';
	} elseif ( $ai && ! empty( $ai['reason'] ) ) {
		$tip[] = 'هوش مصنوعی: ' . $ai['reason'];
	}
	?>
	<li draggable="true" data-kw="<?php echo esc_attr( $i ); ?>" data-v="<?php echo esc_attr( (int) $kw['v'] ); ?>" data-k="<?php echo esc_attr( $kw['key'] ); ?>" class="is-c-<?php echo esc_attr( $conf ); ?><?php echo $c && $n >= $TAIL ? ' is-tail' : ''; ?><?php echo ! empty( $meta['review'] ) ? ' is-rev' : ''; ?>" title=""<?php echo esc_attr( implode( "\n", array_filter( $tip ) ) ); ?>">
		<?php if ( $c ) : ?><i class="qtb-conf is-<?php echo esc_attr( $conf ); ?>"></i><?php endif; ?>
		<span><?php echo esc_html( $kw['k'] ); ?></span><?php echo $flag; // phpcs:ignore ?><?php echo ! empty( $kw['added'] ) && strtotime( $kw['added'] ) > time() - 30 * DAY_IN_SECONDS ? '<b class="qtb-new-tag" title="' . esc_attr( 'اضافه‌شده در ' . qtb_jdate( $kw['added'] ) ) . '">جدید</b>' : ''; ?><em><?php echo esc_html( qtb_num( $kw['v'] ) ); ?></em>
		<span class="qtb-kw-acts">
			<button type="button" class="qtb-icon-btn" data-qtb-ev="<?php echo esc_attr( $i ); ?>" title="شواهد گوگل"><?php echo qtb_icon( 'research', 13 ); // phpcs:ignore ?></button>
			<button type="button" class="qtb-icon-btn" data-qtb-wsmove="<?php echo esc_attr( $i ); ?>" title="انتقال"><?php echo qtb_icon( 'undo', 13 ); // phpcs:ignore ?></button>
			<?php if ( $c ) : ?>
				<button type="button" class="qtb-icon-btn" data-qtb-to="park" data-kw="<?php echo esc_attr( $i ); ?>" title="پارکینگ"><?php echo qtb_icon( 'clock', 13 ); // phpcs:ignore ?></button>
				<button type="button" class="qtb-icon-btn is-del" data-qtb-to="remove" data-kw="<?php echo esc_attr( $i ); ?>" title="حذف"><?php echo qtb_icon( 'x', 13 ); // phpcs:ignore ?></button>
			<?php endif; ?>
		</span>
	</li>
	<?php
};
?>
<div class="qtb-board" data-qtb-board="<?php echo esc_attr( $project['id'] ); ?>" data-tid="<?php echo esc_attr( $tid ); ?>" data-clusters="<?php echo esc_attr( wp_json_encode( $cl_js, JSON_UNESCAPED_UNICODE ) ); ?>" data-topics="<?php echo esc_attr( wp_json_encode( $tp_js, JSON_UNESCAPED_UNICODE ) ); ?>" data-all="<?php echo esc_attr( wp_json_encode( $all_js, JSON_UNESCAPED_UNICODE ) ); ?>">
	<?php if ( ! empty( $ws['finalized'] ) && ! empty( $ws['dirty'] ) && qtb_can() ) : ?>
		<div class="qtb-note is-warn qtb-dirty"><?php echo qtb_icon( 'refresh', 16 ); // phpcs:ignore ?><span>تغییرات تازه هنوز در فایل خروجی نیست. با دانلود یا نوشتن در گوگل شیت خودکار اعمال می‌شود، یا همین حالا:</span> <button type="button" class="qtb-btn is-small is-primary" data-qtb-publish>به‌روزرسانی خروجی</button></div>
	<?php endif; ?>

	<div class="qtb-panel qtb-bd-head">
		<div class="qtb-panel-head" style="margin:0">
			<div>
				<h2 style="margin:0"><a class="qtb-muted" href="<?php echo esc_url( $url( 'topics' ) ); ?>">موضوع‌ها</a> / <?php echo esc_html( $t['name'] ); ?> <span class="qtb-chip is-ts-<?php echo esc_attr( $t['status'] ); ?>"><?php echo esc_html( $states[ $t['status'] ] ?? '' ); ?></span></h2>
				<p class="qtb-small qtb-muted" style="margin:4px 0 0">
					<?php echo esc_html( sprintf( '%s کلاستر · %s کلمه · %s با اطمینان کم · %s پیشنهاد اعمال‌نشده هوش مصنوعی', qtb_num( count( $t['clusters'] ) ), qtb_num( array_sum( array_map( 'count', array_column( $t['clusters'], 'kws' ) ) ) ), qtb_num( $low ), qtb_num( $sug ) ) ); ?>
					<?php if ( ! empty( $m['mode'] ) ) : ?> · <?php echo esc_html( sprintf( 'روش %s، %s ثانیه، %s دلار', 'single' === $m['mode'] ? 'تک‌تک' : 'دسته‌ای', qtb_num( $m['seconds'] ), qtb_num( $m['ai_cost'], 3 ) ) ); ?><?php endif; ?>
				</p>
			</div>
			<span class="qtb-inline">
				<select data-qtb-tmode="<?php echo esc_attr( $tid ); ?>" style="width:auto;min-height:34px" title="روش بازبینی هوشمند در پردازش بعدی"><?php qtb_options( array( 'batch' => 'بازبینی دسته‌ای', 'single' => 'بازبینی تک‌تک' ), $t['mode'] ); ?></select>
				<button type="button" class="qtb-btn is-small" data-qtb-wsgo="topic" data-tid="<?php echo esc_attr( $tid ); ?>" title="کلاسترهای قفل‌شده (دست‌خورده) حفظ می‌شوند"><?php echo qtb_icon( 'refresh', 14 ); // phpcs:ignore ?> پردازش دوباره</button>
				<?php if ( 'facet' === ( $project['cfg']['engine'] ?? 'facet' ) && $t['clusters'] ) : ?>
					<button type="button" class="qtb-btn is-small is-gold" data-qtb-wsgo="topic" data-tid="<?php echo esc_attr( $tid ); ?>" data-fresh="1" data-confirm="همه کلاسترهای این موضوع از نو با موتور ساختار ساخته شوند؟ قفل‌ها (حتی قفل تأیید موضوع) باز می‌شوند؛ فقط کلاسترهای فایل خودتان دست نمی‌خورند. اصلاح‌های قبلی شما در حافظه هوش مصنوعی می‌ماند." title="قفل همه کلاسترها (جز کلاسترهای فایل شما) باز می‌شود و درخت صفحات از نو ساخته می‌شود"><?php echo qtb_icon( 'layers', 14 ); // phpcs:ignore ?> از نو با موتور ساختار</button>
				<?php endif; ?>
				<?php if ( 'approved' === $t['status'] ) : ?>
					<button type="button" class="qtb-btn is-small" data-qtb-wsop="reopen"><?php echo qtb_icon( 'edit', 14 ); // phpcs:ignore ?> باز کردن برای ویرایش</button>
				<?php else : ?>
					<button type="button" class="qtb-btn is-small is-primary" data-qtb-wsop="approve"><?php echo qtb_icon( 'check', 14 ); // phpcs:ignore ?> تأیید این موضوع</button>
				<?php endif; ?>
				<?php if ( $next ) : ?>
					<a class="qtb-btn is-small is-ghost" href="<?php echo esc_url( $url( 'ready' === $next['status'] ? 'topic' : 'topics', array( 'tid' => $next['id'] ) ) ); ?>">موضوع بعد: <?php echo esc_html( $next['name'] ); ?></a>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<?php if ( 'running' === $t['status'] ) : ?>
		<p class="qtb-note is-warn"><?php echo qtb_icon( 'clock', 16 ); // phpcs:ignore ?><span>این موضوع در حال پردازش است؛ تغییرات را بعد از پایان انجام دهید.</span></p>
	<?php endif; ?>
	<?php if ( $facet && qtb_fx_stale( $project, $t ) ) : ?>
		<p class="qtb-note is-warn"><?php echo qtb_icon( 'refresh', 16 ); // phpcs:ignore ?><span>فرهنگ ویژگی‌ها بعد از پردازش این موضوع تغییر کرده است. «پردازش دوباره» بزنید تا درخت صفحات با نقش‌های تازه ساخته شود (کلاسترهای قفل‌شده می‌مانند).</span></p>
	<?php endif; ?>
	<?php if ( $facet && $rev ) : ?>
		<div class="qtb-bd-evidence">
			<i></i>
			<p><b><?php echo esc_html( sprintf( '%s زیرگروه بدون شاهد گوگل ادغام شد.', qtb_num( count( array_merge( ...array_map( function ( $c ) { return (array) ( $c['fx']['review'] ?? array() ); }, $t['clusters'] ) ?: array( array() ) ) ) ) ) ); ?></b>
			<?php echo esc_html( qtb_serp_ready() ? 'این‌ها زیر آستانه بودند و نتیجه گوگل نداشتند. اگر صفحه جدا لازم است، در کارت روی «جدا کن» بزنید.' : 'سرویس نتایج گوگل وصل نیست، پس موارد مرزی فقط با آستانه تصمیم گرفته شد. اتصال Serper را کامل کنید و «پردازش دوباره» بزنید؛ یا در کارت‌ها روی «جدا کن» بزنید.' ); ?></p>
			<?php if ( ! qtb_serp_ready() && qtb_can() ) : ?><a class="qtb-btn is-small" href="<?php echo esc_url( qtb_url( 'settings' ) ); ?>">اتصال Serper</a><?php endif; ?>
			<button type="button" class="qtb-btn is-small is-ghost" data-qtb-bdreview>نمایش موارد بازبینی</button>
		</div>
	<?php endif; ?>

	<div class="qtb-bd-tools">
		<div class="qtb-seg" data-qtb-bdfilter>
			<a href="#" class="is-active" data-f="all">همه</a>
			<a href="#" data-f="low">اطمینان کم/متوسط</a>
			<a href="#" data-f="ai">پیشنهادهای هوش مصنوعی</a>
			<?php if ( $facet ) : ?><a href="#" data-f="review">نیاز به بازبینی<?php echo $rev ? ' (' . esc_html( qtb_num( $rev ) ) . ')' : ''; ?></a><?php endif; ?>
		</div>
		<input type="search" data-qtb-bdsearch placeholder="جستجوی کلمه در این موضوع…" style="max-width:240px">
		<button type="button" class="qtb-btn is-small" data-qtb-bdmerge hidden><?php echo qtb_icon( 'layers', 14 ); // phpcs:ignore ?> ادغام <b data-n>۰</b> کلاستر</button>
		<select data-qtb-bdbulksec hidden title="بردن کلاسترهای انتخاب‌شده به یک بخش" style="width:auto;min-height:34px"><option value="">انتقال انتخاب‌شده‌ها به بخش…</option></select>
		<span class="qtb-small qtb-muted">کلمه را بکشید و روی کلاستر دیگر یا خانه‌های پایین رها کنید. نقطه رنگی = اطمینان: <i class="qtb-conf is-high"></i> زیاد <i class="qtb-conf is-mid"></i> متوسط <i class="qtb-conf is-low"></i> کم</span>
	</div>

	<div class="qtb-drops">
		<div class="qtb-drop" data-drop="new"><?php echo qtb_icon( 'plus', 16 ); // phpcs:ignore ?> رها کنید: کلاستر جدید</div>
		<div class="qtb-drop is-park" data-drop="park"><?php echo qtb_icon( 'clock', 16 ); // phpcs:ignore ?> پارکینگ <small>(بعداً تصمیم می‌گیرم)</small></div>
		<div class="qtb-drop is-remove" data-drop="remove"><?php echo qtb_icon( 'trash', 16 ); // phpcs:ignore ?> حذف <small>(بی‌ربط / بی‌ارزش)</small></div>
	</div>

	<?php
	// Clusters by section (the biggest section first); «بدون بخش» last.
	$secs = array();
	foreach ( $t['clusters'] as $c ) {
		$sn = trim( (string) ( $c['section'] ?? '' ) );
		if ( ! isset( $secs[ $sn ] ) ) {
			$secs[ $sn ] = array( 'vol' => 0, 'cls' => array() );
		}
		$secs[ $sn ]['vol']   += (int) $c['vol'];
		$secs[ $sn ]['cls'][]  = $c;
	}
	$skel = qtb_fx_skeleton( $project );
	// Main page first; blog, landings and «no section» last; the rest by demand.
	$rank = function ( $n ) use ( $skel ) {
		return '' === $n ? 4 : ( $n === $skel['main'] ? 0 : ( $n === $skel['blog'] ? 3 : ( $n === $skel['landing'] ? 2 : 1 ) ) );
	};
	uksort( $secs, function ( $a, $b ) use ( $secs, $rank ) {
		return $rank( $a ) <=> $rank( $b ) ?: $secs[ $b ]['vol'] <=> $secs[ $a ]['vol'];
	} );
	$sec_names = array_values( array_filter( array_keys( $secs ), 'strlen' ) );
	if ( $facet ) {
		foreach ( qtb_fx_section_names( $project ) as $sn0 ) {
			if ( ! in_array( qtb_key( $sn0 ), array_map( 'qtb_key', $sec_names ), true ) ) {
				$sec_names[] = $sn0;
			}
		}
	}
	?>
	<div class="qtb-bd-secs" data-sections="<?php echo esc_attr( wp_json_encode( $sec_names, JSON_UNESCAPED_UNICODE ) ); ?>">
	<?php foreach ( $secs as $sn => $sec ) : ?>
	<section class="qtb-bd-sec" data-secdrop="<?php echo esc_attr( $sn ); ?>">
		<div class="qtb-bd-sec-h">
			<i></i><b><?php echo esc_html( '' !== $sn ? $sn : 'بدون بخش' ); ?></b>
			<span class="qtb-muted qtb-small"><?php echo esc_html( qtb_num( count( $sec['cls'] ) ) . ' کلاستر · ' . qtb_num( $sec['vol'] ) ); ?></span>
			<?php if ( '' !== $sn ) : ?><button type="button" class="qtb-icon-btn" data-qtb-secrename="<?php echo esc_attr( $sn ); ?>" title="تغییر نام این بخش"><?php echo qtb_icon( 'edit', 14 ); // phpcs:ignore ?></button><?php endif; ?>
		</div>
	<div class="qtb-clusters qtb-bd-grid">
		<?php foreach ( $sec['cls'] as $c ) : ?>
			<div class="qtb-cl qtb-bd-cl<?php echo ! empty( $c['locked'] ) ? ' is-locked' : ''; ?><?php echo 'c:' === ( $c['fx']['key'] ?? '' ) ? ' is-root' : ''; ?>" data-cl="<?php echo esc_attr( $c['id'] ); ?>" data-drop="<?php echo esc_attr( $c['id'] ); ?>">
				<div class="qtb-cl-h is-<?php echo esc_attr( $c['page'] ); ?>" draggable="true" data-cldrag="<?php echo esc_attr( $c['id'] ); ?>" title="برای بردن به بخش دیگر، سرتیتر کارت را بکشید">
					<div style="min-width:0">
						<h4><?php echo esc_html( $c['name'] ); ?></h4>
						<small><?php echo esc_html( qtb_intents()[ $c['intent'] ] ?? '' ); ?></small>
					</div>
					<div class="qtb-inline" style="flex-wrap:nowrap">
						<button type="button" class="qtb-icon-btn" data-qtb-clmenu="<?php echo esc_attr( $c['id'] ); ?>" title="انتقال به پیلار دیگر یا ادغام با کلاستری در هر پیلار"><?php echo qtb_icon( 'layers', 15 ); // phpcs:ignore ?></button>
						<button type="button" class="qtb-icon-btn" data-qtb-wsrename="<?php echo esc_attr( $c['id'] ); ?>" title="تغییر نام"><?php echo qtb_icon( 'edit', 15 ); // phpcs:ignore ?></button>
						<button type="button" class="qtb-icon-btn qtb-lock" data-qtb-wslock="<?php echo esc_attr( $c['id'] ); ?>" data-on="<?php echo ! empty( $c['locked'] ) ? 1 : 0; ?>" title="<?php echo ! empty( $c['locked'] ) ? 'قفل است: پردازش دوباره آن را تغییر نمی‌دهد (برای باز کردن بزنید)' : 'قفل کردن: در پردازش دوباره دست نخورد'; ?>"><?php echo qtb_icon( 'shield', 15 ); // phpcs:ignore ?></button>
						<input type="checkbox" data-qtb-pick="<?php echo esc_attr( $c['id'] ); ?>" title="انتخاب برای ادغام">
					</div>
				</div>
				<div class="qtb-cl-meta">
					<select data-qtb-wspage="<?php echo esc_attr( $c['id'] ); ?>" style="min-height:24px;padding:0 8px 0 24px;width:auto;font-size:11.5px;border-radius:99px;background-position:left 7px center">
						<?php qtb_options( qtb_page_options(), qtb_page_value( $c ) ); ?>
					</select>
					<select data-qtb-wssec="<?php echo esc_attr( $c['id'] ); ?>" title="بخش" style="min-height:24px;padding:0 8px 0 24px;width:auto;max-width:150px;font-size:11.5px;border-radius:99px;background-position:left 7px center">
						<?php if ( '' === trim( (string) ( $c['section'] ?? '' ) ) ) : ?><option value="" selected>بدون بخش</option><?php endif; ?>
						<?php foreach ( $sec_names as $o ) : ?><option value="<?php echo esc_attr( $o ); ?>" <?php selected( trim( (string) ( $c['section'] ?? '' ) ), $o ); ?>><?php echo esc_html( $o ); ?></option><?php endforeach; ?>
						<option value="__new">+ بخش جدید…</option>
					</select>
					<?php if ( ! empty( $c['own'] ) ) : ?><span class="qtb-chip is-own" title="از فایل شما؛ قفل است و دوباره خوشه‌بندی نمی‌شود. برای خوشه‌بندی دوباره، قفل را باز کنید و «پردازش دوباره» بزنید.">کلاستر شما</span><?php endif; ?>
					<?php if ( ! empty( $c['page_ai'] ) ) : ?><span class="qtb-chip is-ai" title="<?php echo esc_attr( 'پیشنهاد اولیه قانون‌محور: ' . ( qtb_page_options()[ $c['page_ai']['from'] ] ?? $c['page_ai']['from'] ) ); ?>">نوع صفحه با هوش مصنوعی</span><?php endif; ?>
					<?php if ( ! empty( $c['top3'] ) ) : ?><span class="qtb-chip is-serp" title="نوع ۳ نتیجه اول گوگل؛ ماهیت صفحه با این تعیین می‌شود"><?php echo esc_html( '۳ نتیجه اول: ' . $c['top3'] ); ?></span><?php endif; ?>
					<?php if ( $c['serp'] ) : ?>
						<span class="qtb-chip is-serp" title="<?php echo esc_attr( 'نوع نتایج: ' . implode( '، ', array_map( function ( $k, $v ) { return $k . ' ' . round( 100 * $v ) . '٪'; }, array_keys( (array) $c['serp']['types'] ), (array) $c['serp']['types'] ) ) ); ?>">گوگل ✓<?php echo ! empty( $c['serp']['own_pos'] ) ? ' · جایگاه ' . esc_html( qtb_num( $c['serp']['own_pos'] ) ) : ''; ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $c['store_match'] ) ) : ?><span class="qtb-chip"><?php echo esc_html( qtb_num( $c['store_match'] ) ); ?> محصول</span><?php endif; ?>
				</div>
				<?php if ( ! empty( $c['fx'] ) ) : ?>
					<div class="qtb-cl-fx">
						<?php foreach ( (array) $c['fx']['facets'] as $ft => $fty ) : ?>
							<span class="qtb-fxchip is-<?php echo esc_attr( isset( qtb_fx_reserved()[ $fty ] ) ? $fty : 'own' ); ?>" title="<?php echo esc_attr( $ftypes[ $fty ]['label'] ?? '' ); ?>"><i><?php echo esc_html( $ftypes[ $fty ]['label'] ?? '' ); ?></i><?php echo esc_html( $ft ); ?></span>
						<?php endforeach; ?>
						<?php if ( ! empty( $fwhy[ $c['fx']['why'] ?? '' ] ) && 'root' !== $c['fx']['why'] ) : ?>
							<span class="qtb-fxwhy is-<?php echo esc_attr( $c['fx']['why'] ); ?>"<?php echo ! empty( $c['fx']['ev']['note'] ) ? ' title="' . esc_attr( $c['fx']['ev']['note'] ) . '"' : ''; ?>><?php echo esc_html( $fwhy[ $c['fx']['why'] ] . ( isset( $c['fx']['ev']['shared'] ) && $c['fx']['ev']['shared'] >= 0 ? ' (' . qtb_num( $c['fx']['ev']['shared'] ) . ' نتیجه مشترک)' : '' ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $c['page_reason'] ) ) : ?><div class="qtb-cl-why"><?php echo esc_html( $c['page_reason'] ); ?></div><?php endif; ?>
				<?php if ( ! empty( $c['page_hint'] ) ) : ?><div class="qtb-cl-target">صفحه موجود: <?php echo qtb_link( $c['page_hint'], 40 ); // phpcs:ignore ?></div><?php endif; ?>
				<?php $subs = ! empty( $c['fx'] ) ? qtb_fx_subgroups( $c, $kws ) : array(); ?>
				<?php if ( $subs ) : ?>
					<details class="qtb-cl-subs"<?php echo array_filter( array_column( $subs, 'review' ) ) ? ' open' : ''; ?>>
						<summary><?php echo esc_html( sprintf( '%s زیرگروه داخل این صفحه', qtb_num( count( $subs ) ) ) ); ?><?php $nr = count( array_filter( array_column( $subs, 'review' ) ) ); echo $nr ? ' <b>' . esc_html( qtb_num( $nr ) . ' بازبینی' ) . '</b>' : ''; // phpcs:ignore ?></summary>
						<ul>
							<?php foreach ( array_slice( $subs, 0, 8, true ) as $nk => $sg ) : ?>
								<li class="<?php echo $sg['review'] ? 'is-rev' : ''; ?><?php echo $sg['kept'] ? ' is-kept' : ''; ?>" title=""<?php echo esc_attr( ( $why_l[ $sg['why'] ] ?? '' ) . ( $sg['shared'] >= 0 ? ' · ' . qtb_num( $sg['shared'] ) . ' نتیجه مشترک' : '' ) ); ?>">
									<i class="qtb-sg-dot is-<?php echo esc_attr( $sg['why'] ); ?>"></i>
									<span><?php echo esc_html( $kws[ $sg['head'] ]['k'] ?? '' ); ?></span>
									<em><?php echo esc_html( qtb_num( count( $sg['kws'] ) ) . ' کلمه · ' . qtb_num( $sg['vol'] ) ); ?></em>
									<span class="qtb-sg-acts">
										<?php if ( $sg['kept'] ) : ?>
											<b class="qtb-sg-ok" title="شما تأیید کردید که در همین صفحه بماند">تأیید شد</b>
										<?php else : ?>
											<button type="button" class="qtb-sg-btn is-keep" data-qtb-node="node_keep" data-node="<?php echo esc_attr( $nk ); ?>" data-cid="<?php echo esc_attr( $c['id'] ); ?>" title="درست است؛ در همین صفحه بماند">نگه دار</button>
										<?php endif; ?>
										<button type="button" class="qtb-sg-btn is-split" data-qtb-split="<?php echo esc_attr( $nk ); ?>" data-cid="<?php echo esc_attr( $c['id'] ); ?>" title="این زیرگروه (و کوچک‌ترهای زیرش) صفحه جدا شود">جدا کن</button>
										<button type="button" class="qtb-sg-btn is-park" data-qtb-node="node_park" data-node="<?php echo esc_attr( $nk ); ?>" data-cid="<?php echo esc_attr( $c['id'] ); ?>" title="فعلاً با این کلمات کاری نداریم؛ به پارکینگ برود تا بعد از صفحات اولویت‌دار">پارک</button>
									</span>
								</li>
							<?php endforeach; ?>
							<?php if ( count( $subs ) > 8 ) : ?><li class="qtb-sg-more"><?php echo esc_html( sprintf( 'و %s زیرگروه کوچک‌تر؛ کلمه‌هایشان در فهرست زیر است.', qtb_num( count( $subs ) - 8 ) ) ); ?></li><?php endif; ?>
						</ul>
					</details>
				<?php endif; ?>
				<ul>
					<?php
					foreach ( $c['kws'] as $n => $i ) {
						$chip( $i, $c, $n );
					}
					?>
				</ul>
				<?php if ( count( $c['kws'] ) > $TAIL ) : ?>
					<button type="button" class="qtb-cl-more" data-qtb-more><span data-closed><?php echo esc_html( sprintf( '+ %s کلمه دیگر', qtb_num( count( $c['kws'] ) - $TAIL ) ) ); ?></span><span data-open>بستن</span></button>
				<?php endif; ?>
				<div class="qtb-cl-foot"><span>جمع <b data-vol><?php echo esc_html( qtb_num( $c['vol'] ) ); ?></b> · <span data-n><?php echo esc_html( qtb_num( count( $c['kws'] ) ) ); ?></span> کلمه</span></div>
			</div>
		<?php endforeach; ?>
	</div>
	</section>
	<?php endforeach; ?>
	</div>
	<?php if ( ! $t['clusters'] ) : ?>
		<p class="qtb-muted">این موضوع هنوز پردازش نشده است.</p>
	<?php endif; ?>

	<div class="qtb-grid-2" style="gap:16px;margin-top:16px;align-items:start">
		<div class="qtb-panel">
			<h2><?php echo qtb_icon( 'clock', 18 ); // phpcs:ignore ?> کنار گذاشته‌های این موضوع <small>برای برگرداندن، کلمه را روی یک کلاستر بکشید</small></h2>
			<?php foreach ( array( 'park' => 'پارکینگ', 'remove' => 'حذف‌شده' ) as $k => $l ) : ?>
				<h3 class="qtb-small" style="margin:10px 0 6px"><?php echo esc_html( $l . ' (' . qtb_num( count( $aside[ $k ] ) ) . ')' ); ?></h3>
				<ul class="qtb-aside is-<?php echo esc_attr( $k ); ?>" data-drop="<?php echo esc_attr( $k ); ?>">
					<?php
					foreach ( array_slice( $aside[ $k ], 0, 300 ) as $i ) {
						$chip( $i, null );
					}
					?>
					<?php if ( ! $aside[ $k ] ) : ?><li class="qtb-muted is-empty">خالی</li><?php endif; ?>
				</ul>
			<?php endforeach; ?>
		</div>

		<div class="qtb-panel" data-qtb-comment>
			<h2><?php echo qtb_icon( 'bolt', 18 ); // phpcs:ignore ?> نظر شما → قانون پروژه <small>نظرتان را آزاد بنویسید؛ هوش مصنوعی آن را به قانون‌هایی تبدیل می‌کند که روی همین موضوع (با پردازش دوباره) و موضوع‌های بعدی اعمال می‌شود</small></h2>
			<?php if ( $t['comments'] ) : ?>
				<ul class="qtb-comments">
					<?php foreach ( array_slice( $t['comments'], -5 ) as $cm ) : ?>
						<li><b><?php echo esc_html( $cm['by'] ); ?></b> <small class="qtb-muted"><?php echo esc_html( qtb_jdate( $cm['at'] ) ); ?></small><br><?php echo esc_html( $cm['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<textarea rows="3" data-qtb-comment-text placeholder="مثلاً: کلمات «تعمیر» را حذف کن چون خدمات نداریم؛ «چسب کفش» با محصولات کم صفحه دسته باشد نه لندینگ؛ شهرها را جدا نکن."></textarea>
			<div class="qtb-inline" style="margin-top:8px">
				<button type="button" class="qtb-btn is-small is-primary" data-qtb-comment-go><?php echo qtb_icon( 'bolt', 14 ); // phpcs:ignore ?> تبدیل به قانون</button>
			</div>
			<div data-qtb-comment-out hidden>
				<p class="qtb-small" data-qtb-comment-reply></p>
				<ul class="qtb-rules is-proposed" data-qtb-comment-rules></ul>
				<div class="qtb-inline">
					<button type="button" class="qtb-btn is-small" data-qtb-comment-save><?php echo qtb_icon( 'check', 14 ); // phpcs:ignore ?> ذخیره قوانین انتخاب‌شده</button>
					<button type="button" class="qtb-btn is-small is-ghost" data-qtb-wsgo="topic" data-tid="<?php echo esc_attr( $tid ); ?>" hidden data-qtb-after-rules><?php echo qtb_icon( 'refresh', 14 ); // phpcs:ignore ?> پردازش دوباره با قوانین جدید</button>
				</div>
			</div>
		</div>
	</div>

	<aside class="qtb-drawer" data-qtb-drawer hidden>
		<div class="qtb-drawer-h"><h3 data-qtb-drawer-title></h3><button type="button" class="qtb-icon-btn" data-qtb-drawer-close><?php echo qtb_icon( 'x', 16 ); // phpcs:ignore ?></button></div>
		<div data-qtb-drawer-body></div>
	</aside>
</div>
