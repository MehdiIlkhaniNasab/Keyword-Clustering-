<?php
/**
 * Keyword map: every keyword with its cluster, page, priority and target.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$kws = $R['keywords'];
$f   = array(
	'p'    => isset( $_GET['pl'] ) && '' !== $_GET['pl'] ? absint( $_GET['pl'] ) : '', // phpcs:ignore
	'page' => sanitize_key( wp_unslash( $_GET['page_type'] ?? '' ) ), // phpcs:ignore
	'prio' => sanitize_text_field( wp_unslash( $_GET['prio'] ?? '' ) ), // phpcs:ignore
	'cov'  => sanitize_key( wp_unslash( $_GET['cov'] ?? '' ) ), // phpcs:ignore
	'q'    => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ), // phpcs:ignore
);
$rows = array();
foreach ( $R['clusters'] as $c ) {
	if ( ( '' !== $f['p'] && $c['pillar'] !== $f['p'] ) || ( $f['page'] && $c['page'] !== $f['page'] ) || ( $f['prio'] && $c['priority'] !== $f['prio'] ) || ( $f['cov'] && $c['coverage'] !== $f['cov'] ) ) {
		continue;
	}
	foreach ( $c['kws'] as $j => $m ) {
		if ( '' !== $f['q'] && false === mb_strpos( qtb_key( $kws[ $m ]['k'] ), qtb_key( $f['q'] ) ) ) {
			continue;
		}
		$rows[] = array( $c, $m, $j );
	}
}
$per   = 200;
$pages = (int) ceil( count( $rows ) / $per );
$cur   = max( 1, min( max( 1, $pages ), absint( $_GET['pg'] ?? 1 ) ) ); // phpcs:ignore
$args  = array_filter( array( 'pl' => $f['p'], 'page_type' => $f['page'], 'prio' => $f['prio'], 'cov' => $f['cov'], 'q' => $f['q'] ), 'strlen' );
?>
<form class="qtb-toolbar" method="get" action="<?php echo esc_url( qtb_project_url( $project['id'], 'map' ) ); ?>">
	<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
		<input type="hidden" name="qtb_app" value="1"><input type="hidden" name="qtb_route" value="<?php echo esc_attr( 'project/' . $project['id'] . '/map' ); ?>">
	<?php endif; ?>
	<div class="qtb-filters">
		<select name="pl"><option value="">همه پیلارها</option><?php qtb_options( array_column( $R['pillars'], 'name', 'id' ), $f['p'] ); ?></select>
		<select name="page_type"><option value="">همه نوع صفحه‌ها</option><?php qtb_options( array_map( function ( $t ) { return $t[0]; }, qtb_page_types() ), $f['page'] ); ?></select>
		<select name="prio"><option value="">همه اولویت‌ها</option><?php qtb_options( array( 'P1' => 'P1', 'P2' => 'P2', 'P3' => 'P3' ), $f['prio'] ); ?></select>
		<select name="cov"><option value="">همه وضعیت‌ها</option><?php qtb_options( array( 'new' => 'صفحه جدید لازم', 'optimize' => 'بهینه‌سازی صفحه موجود', 'ok' => 'رتبه در صفحه اول' ), $f['cov'] ); ?></select>
		<input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="جستجوی کلمه" style="width:180px">
		<button class="qtb-btn is-small" type="submit">اعمال</button>
	</div>
	<span class="qtb-muted qtb-small"><?php echo esc_html( qtb_num( count( $rows ) ) ); ?> کلمه</span>
</form>
<div class="qtb-panel" style="padding:8px 10px">
	<div class="qtb-table-wrap">
		<table class="qtb-table is-compact">
			<thead><tr><th>کلمه کلیدی</th><th class="num">حجم</th><th>کلاستر (صفحه)</th><th>پیلار / بخش</th><th>نوع صفحه</th><th>نیت</th><th>اولویت</th><th>صفحه هدف</th><th>وضعیت</th></tr></thead>
			<tbody>
			<?php
			foreach ( array_slice( $rows, ( $cur - 1 ) * $per, $per ) as $row ) :
				list( $c, $m, $j ) = $row;
				?>
				<tr>
					<td><?php echo 0 === $j ? '<b>' . esc_html( $kws[ $m ]['k'] ) . '</b>' : esc_html( $kws[ $m ]['k'] ); ?></td>
					<td class="num"><?php echo esc_html( qtb_num( $kws[ $m ]['v'] ) ); ?></td>
					<td><?php echo esc_html( $c['name'] ); ?></td>
					<td class="qtb-small"><?php echo esc_html( ( $R['pillars'][ $c['pillar'] ]['name'] ?? '' ) . ' › ' . ( $c['section'] ?? '' ) ); ?></td>
					<td><?php echo qtb_chip_page( $c['page'] ); // phpcs:ignore ?></td>
					<td class="qtb-small"><?php echo esc_html( qtb_intents()[ $c['intent'] ] ?? '' ); ?></td>
					<td><?php echo qtb_chip_prio( $c['priority'] ); // phpcs:ignore ?></td>
					<td class="qtb-small"><?php echo $c['target'] ? qtb_link( $c['target']['url'], 34 ) : '—'; // phpcs:ignore ?></td>
					<td><?php echo qtb_chip_cov( $c['coverage'] ); // phpcs:ignore ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php qtb_pager( $pages, $cur, $args ); ?>
