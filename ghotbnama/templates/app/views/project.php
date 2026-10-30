<?php
/**
 * Project screen: header, tabs and the tab view.
 *
 * @package Ghotbnama
 * @var array  $project
 * @var string $tab
 */

defined( 'ABSPATH' ) || exit;

$tabs   = qtb_project_tabs();
if ( ! qtb_can() ) {
	$tabs = array_intersect_key( $tabs, array( 'workshop' => 1 ) );
}
$result = 'settings' === $tab ? array() : qtb_result( $project );
$counts = array(
	'clusters' => count( $result['clusters'] ?? array() ),
	'removed'  => count( $result['removed'] ?? array() ),
	'calendar' => count( $result['calendar']['articles'] ?? array() ),
);
?>
<div class="qtb-head">
	<div>
		<p class="qtb-eyebrow"><?php echo qtb_icon( 'compass', 14 ); // phpcs:ignore ?> پروژه</p>
		<h1><?php echo esc_html( $project['name'] ); ?></h1>
		<p class="qtb-muted qtb-small"><span class="qtb-ltr"><?php echo esc_html( qtb_host( $project['site_url'] ) ); ?></span>
			<?php if ( ! empty( $result['meta']['built'] ) ) : ?>
				· آخرین اجرا <?php echo esc_html( qtb_jdate( $result['meta']['built'] ) ); ?>
			<?php endif; ?>
		</p>
	</div>
	<div class="qtb-actions" <?php echo qtb_can() ? '' : 'hidden'; ?>>
		<?php if ( ! empty( $result['clusters'] ) ) : ?>
			<a class="qtb-btn" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'ghotbnama/v1/download/' . $project['id'] . '/research' ) ) ); ?>"><?php echo qtb_icon( 'download', 16 ); // phpcs:ignore ?> کیورد ریسرچ (اکسل)</a>
		<?php endif; ?>
		<a class="qtb-btn is-primary" href="<?php echo esc_url( qtb_project_url( $project['id'], 'overview', array( 'run' => 1 ) ) ); ?>#run"><?php echo qtb_icon( 'play', 16 ); // phpcs:ignore ?> اجرای خوشه‌بندی</a>
	</div>
</div>
<nav class="qtb-tabs">
	<?php foreach ( $tabs as $key => $t ) : ?>
		<a class="<?php echo $key === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( qtb_project_url( $project['id'], $key ) ); ?>"><?php echo qtb_icon( $t[1], 16 ); // phpcs:ignore ?><?php echo esc_html( $t[0] ); ?><?php echo ! empty( $counts[ $key ] ) ? '<em>' . esc_html( qtb_num( $counts[ $key ] ) ) . '</em>' : ''; ?></a>
	<?php endforeach; ?>
</nav>
<?php
if ( 'settings' === $tab ) {
	qtb_view( 'project-form', array( 'project' => $project ) );
} elseif ( ! in_array( $tab, array( 'overview', 'export', 'workshop' ), true ) && empty( $result['clusters'] ) ) {
	?>
	<div class="qtb-empty">
		<?php echo qtb_icon( 'compass', 40 ); // phpcs:ignore ?>
		<h2>هنوز خوشه‌بندی اجرا نشده</h2>
		<p>از «نمای کلی» کلمات را از گوگل شیت بخوانید یا فایل بارگذاری کنید و اجرای خوشه‌بندی را بزنید.</p>
		<a class="qtb-btn is-primary" href="<?php echo esc_url( qtb_project_url( $project['id'] ) ); ?>">رفتن به نمای کلی</a>
	</div>
	<?php
} else {
	qtb_view( 'project-' . $tab, array( 'project' => $project, 'R' => $result ) );
}
