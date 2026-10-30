<?php
/**
 * Workshop step 3: topics — process, review, approve; compare the two AI review modes.
 *
 * @package Ghotbnama
 * @var array    $project
 * @var array    $ws
 * @var array    $kws
 * @var bool     $has
 * @var callable $url
 */

defined( 'ABSPATH' ) || exit;

if ( ! $ws['topics'] ) {
	echo '<div class="qtb-empty">' . qtb_icon( 'layers', 40 ) . '<h2>هنوز موضوعی ساخته نشده</h2><p>بعد از غربال، «ساخت موضوع‌ها» را بزنید تا کلمات بر اساس آنچه مشتری می‌خرد یا یاد می‌گیرد تقسیم شوند.</p><a class="qtb-btn is-primary" href="' . esc_url( $url( 'triage' ) ) . '">رفتن به غربال</a></div>'; // phpcs:ignore
	return;
}
$states = qtb_ws_topic_states();
$stats  = qtb_ws_mode_stats( $ws );
$vol    = function ( $t ) use ( $kws ) {
	$v = 0;
	foreach ( $t['kws'] as $i ) {
		$v += (int) ( $kws[ $i ]['v'] ?? 0 );
	}
	return $v;
};
?>
<div class="qtb-panel" data-qtb-topics="<?php echo esc_attr( $project['id'] ); ?>">
	<div class="qtb-panel-head">
		<h2><?php echo qtb_icon( 'layers', 18 ); // phpcs:ignore ?> موضوع‌ها <small>هر موضوع جدا پردازش و بازبینی می‌شود؛ اصلاح‌ها و قوانین هر موضوع روی موضوع‌های بعدی اثر می‌گذارد</small></h2>
		<span class="qtb-inline">
			<button type="button" class="qtb-btn is-small" data-qtb-topicmerge hidden><?php echo qtb_icon( 'layers', 14 ); // phpcs:ignore ?> ادغام موضوع‌های انتخاب‌شده</button>
		</span>
	</div>
	<div class="qtb-table-wrap"><table class="qtb-table">
		<thead><tr><th></th><th>موضوع</th><th class="num">کلمه</th><th class="num">حجم</th><th>وضعیت</th><th>روش بازبینی</th><th>آخرین پردازش</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $ws['topics'] as $t ) : $m = $t['metrics']; ?>
			<tr>
				<td><input type="checkbox" data-qtb-tpick="<?php echo esc_attr( $t['id'] ); ?>"></td>
				<td><b><?php echo esc_html( $t['name'] ); ?></b> <button type="button" class="qtb-icon-btn" data-qtb-trename="<?php echo esc_attr( $t['id'] ); ?>" data-name="<?php echo esc_attr( $t['name'] ); ?>" title="تغییر نام"><?php echo qtb_icon( 'edit', 13 ); // phpcs:ignore ?></button>
					<?php if ( $t['clusters'] ) : ?><br><span class="qtb-small qtb-muted"><?php echo esc_html( qtb_num( count( $t['clusters'] ) ) ); ?> کلاستر</span><?php endif; ?></td>
				<td class="num"><?php echo esc_html( qtb_num( count( $t['kws'] ) ) ); ?></td>
				<td class="num"><?php echo esc_html( qtb_num( $vol( $t ) ) ); ?></td>
				<td><span class="qtb-chip is-ts-<?php echo esc_attr( $t['status'] ); ?>"><?php echo esc_html( $states[ $t['status'] ] ?? $t['status'] ); ?></span></td>
				<td><select data-qtb-tmode="<?php echo esc_attr( $t['id'] ); ?>" style="width:auto;min-height:32px;padding:2px 8px 2px 26px"><?php qtb_options( array( 'batch' => 'دسته‌ای', 'single' => 'تک‌تک' ), $t['mode'] ); ?></select></td>
				<td class="qtb-small"><?php echo $m ? esc_html( sprintf( '%s · %s ثانیه · %s دلار · هوش مصنوعی %s تغییر · شما %s تغییر', 'single' === $m['mode'] ? 'تک‌تک' : 'دسته‌ای', qtb_num( $m['seconds'] ), qtb_num( $m['ai_cost'], 3 ), qtb_num( $m['ai_changes'] ), qtb_num( $m['user_changes'] ?? 0 ) ) ) : '—'; ?></td>
				<td class="qtb-nowrap">
					<?php if ( 'approved' !== $t['status'] ) : ?>
						<button type="button" class="qtb-btn is-small <?php echo 'new' === $t['status'] ? 'is-primary' : ''; ?>" data-qtb-wsgo="topic" data-tid="<?php echo esc_attr( $t['id'] ); ?>"><?php echo qtb_icon( 'play', 13 ); // phpcs:ignore ?> <?php echo 'new' === $t['status'] ? 'پردازش' : 'پردازش دوباره'; ?></button>
					<?php endif; ?>
					<?php if ( $t['clusters'] ) : ?>
						<a class="qtb-btn is-small <?php echo 'ready' === $t['status'] ? 'is-gold' : ''; ?>" href="<?php echo esc_url( $url( 'topic', array( 'tid' => $t['id'] ) ) ); ?>"><?php echo qtb_icon( 'research', 13 ); // phpcs:ignore ?> بازبینی</a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
</div>

<div class="qtb-grid-2" style="gap:16px;align-items:start">
	<div class="qtb-panel">
		<h2><?php echo qtb_icon( 'chart', 18 ); // phpcs:ignore ?> مقایسه دو روش بازبینی هوشمند</h2>
		<?php if ( ! $stats ) : ?>
			<p class="qtb-muted qtb-small">بعد از پردازش چند موضوع (یا یک موضوع با هر دو روش) مقایسه اینجا دیده می‌شود. معیار اصلی «تغییرات شما»ست: هرچه کمتر، روش دقیق‌تر بوده.</p>
		<?php else : ?>
			<table class="qtb-table is-compact">
				<thead><tr><th>روش</th><th class="num">کلمه</th><th class="num">هزینه هر ۱۰۰۰ کلمه</th><th class="num">زمان هر ۱۰۰ کلمه</th><th class="num">تغییر هوش مصنوعی</th><th class="num">اصلاح شما</th></tr></thead>
				<tbody>
				<?php foreach ( $stats as $mode => $x ) : ?>
					<tr>
						<td><b><?php echo 'single' === $mode ? 'تک‌تک' : 'دسته‌ای'; ?></b> <span class="qtb-muted qtb-small">(<?php echo esc_html( qtb_num( $x['runs'] ) ); ?> اجرا)</span></td>
						<td class="num"><?php echo esc_html( qtb_num( $x['kws'] ) ); ?></td>
						<td class="num"><?php echo esc_html( qtb_num( 1000 * $x['cost'] / max( 1, $x['kws'] ), 2 ) ); ?> $</td>
						<td class="num"><?php echo esc_html( qtb_num( 100 * $x['seconds'] / max( 1, $x['kws'] ) ) ); ?> ث</td>
						<td class="num"><?php echo esc_html( qtb_pct( $x['ai_changes'] / max( 1, $x['kws'] ), 1 ) ); ?></td>
						<td class="num"><b><?php echo esc_html( qtb_pct( $x['user_changes'] / max( 1, $x['kws'] ), 1 ) ); ?></b></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="qtb-small qtb-muted" style="margin:8px 0 0">برای مقایسه دقیق، یک موضوع را با یک روش پردازش و بازبینی کنید، بعد روش را عوض کنید و «پردازش دوباره» بزنید (کلاسترهایی که دست زده‌اید قفل می‌مانند).</p>
		<?php endif; ?>
	</div>
	<?php qtb_view( 'ws-rules', array( 'project' => $project, 'ws' => $ws ) ); ?>
</div>
