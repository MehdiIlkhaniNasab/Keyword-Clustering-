<?php
/**
 * Removed keywords with the reason; any of them can be brought back.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$reasons = qtb_remove_reasons();
$filter  = sanitize_key( wp_unslash( $_GET['r'] ?? '' ) ); // phpcs:ignore
$counts  = array_count_values( array_column( $R['removed'], 'r' ) );
$list    = array();
foreach ( $R['removed'] as $i => $x ) {
	if ( '' === $filter || $x['r'] === $filter ) {
		$list[ $i ] = $x;
	}
}
uasort( $list, function ( $a, $b ) {
	return $b['v'] <=> $a['v'];
} );
$per   = 300;
$pages = (int) ceil( count( $list ) / $per );
$cur   = max( 1, min( max( 1, $pages ), absint( $_GET['pg'] ?? 1 ) ) ); // phpcs:ignore
?>
<div class="qtb-toolbar">
	<div class="qtb-seg">
		<a class="<?php echo '' === $filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( qtb_project_url( $project['id'], 'removed' ) ); ?>">همه (<?php echo esc_html( qtb_num( count( $R['removed'] ) ) ); ?>)</a>
		<?php foreach ( $counts as $r => $n ) : ?>
			<a class="<?php echo $filter === $r ? 'is-active' : ''; ?>" href="<?php echo esc_url( qtb_project_url( $project['id'], 'removed', array( 'r' => $r ) ) ); ?>"><?php echo esc_html( ( $reasons[ $r ] ?? $r ) . ' (' . qtb_num( $n ) . ')' ); ?></a>
		<?php endforeach; ?>
	</div>
</div>
<p class="qtb-note"><?php echo qtb_icon( 'shield', 16 ); // phpcs:ignore ?><span>هیچ کلمه‌ای بی‌صدا پاک نمی‌شود. اگر کلمه‌ای اشتباه حذف شده، «برگرداندن» را بزنید تا به یک کلاستر جدید (یا کلاستر دلخواه) اضافه شود.</span></p>
<div class="qtb-panel" style="padding:8px 10px" data-qtb-editor="<?php echo esc_attr( $project['id'] ); ?>">
	<div class="qtb-table-wrap"><table class="qtb-table is-compact">
		<thead><tr><th>کلمه</th><th class="num">حجم</th><th>دلیل</th><th>جزئیات</th><th>تشخیص</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( array_slice( $list, ( $cur - 1 ) * $per, $per, true ) as $i => $x ) : ?>
			<tr>
				<td><?php echo esc_html( $x['k'] ); ?></td>
				<td class="num"><?php echo esc_html( qtb_num( $x['v'] ) ); ?></td>
				<td><?php echo esc_html( $reasons[ $x['r'] ] ?? $x['r'] ); ?></td>
				<td class="qtb-small qtb-muted"><?php echo esc_html( (string) ( $x['d'] ?? '' ) ); ?></td>
				<td class="qtb-small"><?php echo ! empty( $x['ai'] ) ? 'هوش مصنوعی' : ( 'manual' === $x['r'] ? 'دستی' : 'قانون' ); ?></td>
				<td><button type="button" class="qtb-btn is-small" data-qtb-restore="<?php echo esc_attr( $i ); ?>"><?php echo qtb_icon( 'undo', 13 ); // phpcs:ignore ?> برگرداندن</button></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
</div>
<?php qtb_pager( $pages, $cur, $filter ? array( 'r' => $filter ) : array() ); ?>
