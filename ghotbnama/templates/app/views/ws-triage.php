<?php
/**
 * Workshop step 2: triage — keep / remove / park, in bulk, with rules.
 *
 * @package Ghotbnama
 * @var array    $project
 * @var array    $ws
 * @var array    $kws
 * @var bool     $has
 * @var callable $url
 */

defined( 'ABSPATH' ) || exit;

if ( ! $has ) {
	echo '<p class="qtb-note">' . qtb_icon( 'help', 16 ) . '<span>اول کلمات را دریافت کنید. بعد از پاک‌سازی قانونی (تکراری‌ها، بازارگاه‌ها، کلمات بی‌ارزش)، هوش مصنوعی با پروفایل کسب‌وکار هر کلمه را «نگه دار»، «حذف» یا «نیاز به تصمیم شما» برچسب می‌زند.</span></p>'; // phpcs:ignore
	qtb_view( 'ws-start', array( 'project' => $project, 'has' => $has ) );
	return;
}
$reasons = qtb_triage_reasons();
$states  = array( 'unsure' => 'نیاز به تصمیم', 'keep' => 'نگه‌داشته', 'remove' => 'حذف', 'park' => 'پارکینگ' );
$st      = sanitize_key( wp_unslash( $_GET['st'] ?? '' ) ); // phpcs:ignore
$tr      = sanitize_key( wp_unslash( $_GET['tr'] ?? '' ) ); // phpcs:ignore
$q       = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ); // phpcs:ignore
$count   = array_count_values( array_map( function ( $kw ) {
	return $kw['ws'] ?? 'keep';
}, $kws ) );
if ( '' === $st ) {
	$st = ! empty( $count['unsure'] ) ? 'unsure' : 'remove';
}
$list = array_filter( $kws, function ( $kw ) use ( $st, $tr, $q ) {
	return ( 'all' === $st || ( $kw['ws'] ?? 'keep' ) === $st ) && ( '' === $tr || ( $kw['tr'] ?? '' ) === $tr ) && ( '' === $q || false !== mb_strpos( $kw['key'], qtb_key( $q ) ) );
} );
uasort( $list, function ( $a, $b ) {
	return $b['v'] <=> $a['v'];
} );
$rc = array_count_values( array_map( function ( $kw ) {
	return (string) ( $kw['tr'] ?? '' );
}, array_filter( $kws, function ( $kw ) use ( $st ) {
	return 'all' === $st || ( $kw['ws'] ?? 'keep' ) === $st;
} ) ) );
// Frequent words of this list (for one-click rules).
$soft = qtb_lex_set( 'soft' );
$stop = qtb_lex_set( 'stop' );
$freq = array();
foreach ( $list as $kw ) {
	foreach ( array_unique( explode( ' ', $kw['nk'] ) ) as $t ) {
		if ( mb_strlen( $t ) > 1 && ! isset( $soft[ $t ] ) && ! isset( $stop[ $t ] ) ) {
			$freq[ $t ] = ( $freq[ $t ] ?? 0 ) + 1;
		}
	}
}
arsort( $freq );
$per   = 250;
$pages = (int) ceil( count( $list ) / $per );
$cur   = max( 1, min( max( 1, $pages ), absint( $_GET['pg'] ?? 1 ) ) ); // phpcs:ignore
$args  = array_filter( array( 'ws' => 'triage', 'st' => $st, 'tr' => $tr, 'q' => $q ), 'strlen' );
?>
<div class="qtb-toolbar">
	<div class="qtb-seg">
		<?php foreach ( $states as $k => $l ) : ?>
			<a class="<?php echo $st === $k ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url( 'triage', array( 'st' => $k ) ) ); ?>"><?php echo esc_html( $l . ' (' . qtb_num( $count[ $k ] ?? 0 ) . ')' ); ?></a>
		<?php endforeach; ?>
		<a class="<?php echo 'all' === $st ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url( 'triage', array( 'st' => 'all' ) ) ); ?>">همه (<?php echo esc_html( qtb_num( count( $kws ) ) ); ?>)</a>
	</div>
	<form class="qtb-filters" method="get" action="<?php echo esc_url( qtb_project_url( $project['id'], 'workshop' ) ); ?>">
		<input type="hidden" name="ws" value="triage"><input type="hidden" name="st" value="<?php echo esc_attr( $st ); ?>">
		<select name="tr"><option value="">همه دلیل‌ها</option><?php foreach ( $rc as $k => $n ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $tr, $k ); ?>><?php echo esc_html( ( $reasons[ $k ] ?? $k ) . ' (' . qtb_num( $n ) . ')' ); ?></option><?php endforeach; ?></select>
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجو">
		<button class="qtb-btn is-small" type="submit">اعمال</button>
	</form>
</div>

<div class="qtb-ws-triage" data-qtb-triage="<?php echo esc_attr( $project['id'] ); ?>">
	<div class="qtb-panel" style="padding:8px 10px">
		<div class="qtb-table-wrap"><table class="qtb-table is-compact">
			<thead><tr><th><input type="checkbox" data-qtb-checkall></th><th>کلمه</th><th class="num">حجم</th><th>وضعیت</th><th>دلیل</th><th>برند</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $list, ( $cur - 1 ) * $per, $per, true ) as $i => $kw ) : ?>
				<tr>
					<td><input type="checkbox" data-qtb-kw="<?php echo esc_attr( $i ); ?>"></td>
					<td><?php echo esc_html( $kw['k'] ); ?></td>
					<td class="num"><?php echo esc_html( qtb_num( $kw['v'] ) ); ?></td>
					<td><span class="qtb-chip is-st-<?php echo esc_attr( $kw['ws'] ?? 'keep' ); ?>"><?php echo esc_html( $states[ $kw['ws'] ?? 'keep' ] ?? '' ); ?></span></td>
					<td class="qtb-small"><?php echo esc_html( $reasons[ $kw['tr'] ?? '' ] ?? '' ); ?><?php echo ! empty( $kw['tn'] ) ? '<span class="qtb-muted"> — ' . esc_html( $kw['tn'] ) . '</span>' : ''; ?></td>
					<td class="qtb-small"><?php echo esc_html( (string) ( $kw['brand_tok'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $list ) : ?><tr><td colspan="6" class="qtb-muted">کلمه‌ای در این فهرست نیست.</td></tr><?php endif; ?>
			</tbody>
		</table></div>
	</div>
	<?php qtb_pager( $pages, $cur, $args ); ?>
	<div class="qtb-bulkbar" data-qtb-bulk hidden>
		<span><b data-qtb-bulk-n>۰</b> کلمه انتخاب شد</span>
		<span class="qtb-inline">
			<button type="button" class="qtb-btn is-small" data-qtb-set="keep"><?php echo qtb_icon( 'check', 14 ); // phpcs:ignore ?> نگه دار</button>
			<button type="button" class="qtb-btn is-small" data-qtb-set="park"><?php echo qtb_icon( 'clock', 14 ); // phpcs:ignore ?> پارکینگ</button>
			<button type="button" class="qtb-btn is-small is-danger" data-qtb-set="remove"><?php echo qtb_icon( 'trash', 14 ); // phpcs:ignore ?> حذف</button>
		</span>
	</div>

	<div class="qtb-grid-2" style="gap:16px;margin-top:16px;align-items:start">
		<div class="qtb-panel">
			<h2><?php echo qtb_icon( 'bolt', 18 ); // phpcs:ignore ?> تصمیم گروهی با یک کلیک <small>کلمات پرتکرار همین فهرست؛ تصمیم شما قانون پروژه می‌شود و روی همه کلمات (و موضوع‌های بعدی) اعمال می‌شود</small></h2>
			<div class="qtb-freq">
				<?php foreach ( array_slice( $freq, 0, 36, true ) as $t => $n ) : ?>
					<span class="qtb-freq-w"><b><?php echo esc_html( $t ); ?></b> <em><?php echo esc_html( qtb_num( $n ) ); ?></em>
						<button type="button" class="qtb-icon-btn" title="همه کلمات شامل این حذف شوند" data-qtb-rule="remove" data-match="<?php echo esc_attr( $t ); ?>"><?php echo qtb_icon( 'trash', 13 ); // phpcs:ignore ?></button>
						<button type="button" class="qtb-icon-btn" title="همه نگه داشته شوند" data-qtb-rule="keep" data-match="<?php echo esc_attr( $t ); ?>"><?php echo qtb_icon( 'check', 13 ); // phpcs:ignore ?></button>
						<button type="button" class="qtb-icon-btn" title="همه به پارکینگ" data-qtb-rule="park" data-match="<?php echo esc_attr( $t ); ?>"><?php echo qtb_icon( 'clock', 13 ); // phpcs:ignore ?></button>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php qtb_view( 'ws-rules', array( 'project' => $project, 'ws' => $ws ) ); ?>
	</div>

	<div class="qtb-panel is-attention" style="margin-top:16px">
		<div class="qtb-panel-head">
			<h2><?php echo qtb_icon( 'layers', 18 ); // phpcs:ignore ?> مرحله بعد: تقسیم موضوعی</h2>
			<?php if ( qtb_can() ) : ?><button type="button" class="qtb-btn is-primary" data-qtb-wsgo="topics"<?php echo $ws['topics'] ? ' data-confirm="موضوع‌ها از نو ساخته می‌شوند و بازبینی موضوع‌ها از دست می‌رود (قوانین و اصلاح‌ها در حافظه می‌مانند). ادامه؟"' : ''; ?>><?php echo qtb_icon( 'play', 16 ); // phpcs:ignore ?> <?php echo $ws['topics'] ? 'ساخت دوباره موضوع‌ها' : 'ساخت موضوع‌ها'; ?></button><?php endif; ?>
		</div>
		<p class="qtb-small qtb-muted" style="margin:0"><?php echo esc_html( sprintf( '%s کلمه نگه‌داشته و %s کلمه «نیاز به تصمیم» وارد موضوع‌ها می‌شوند؛ حذف‌شده‌ها و پارکینگ نه. بعداً هم می‌توانید از برد بازبینی کلمه را حذف یا پارک کنید.', qtb_num( $count['keep'] ?? 0 ), qtb_num( $count['unsure'] ?? 0 ) ) ); ?></p>
	</div>
</div>
