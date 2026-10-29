<?php
/**
 * Project screen: header, tabs and the tab view.
 *
 * @package Manzoomeh
 * @var array  $project
 * @var string $tab
 */

defined( 'ABSPATH' ) || exit;

$tabs   = mzm_project_tabs();
$result = 'settings' === $tab ? array() : mzm_result( $project );
$counts = array(
	'clusters' => count( $result['clusters'] ?? array() ),
	'removed'  => count( $result['removed'] ?? array() ),
	'calendar' => count( $result['calendar']['articles'] ?? array() ),
);
?>
<div class="mzm-head">
	<div>
		<p class="mzm-eyebrow"><?php echo mzm_icon( 'orbit', 14 ); // phpcs:ignore ?> پروژه</p>
		<h1><?php echo esc_html( $project['name'] ); ?></h1>
		<p class="mzm-muted mzm-small"><span class="mzm-ltr"><?php echo esc_html( mzm_host( $project['site_url'] ) ); ?></span>
			<?php if ( ! empty( $result['meta']['built'] ) ) : ?>
				· آخرین اجرا <?php echo esc_html( mzm_jdate( $result['meta']['built'] ) ); ?>
			<?php endif; ?>
		</p>
	</div>
	<div class="mzm-actions">
		<?php if ( ! empty( $result['clusters'] ) ) : ?>
			<a class="mzm-btn" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'manzoomeh/v1/download/' . $project['id'] . '/research' ) ) ); ?>"><?php echo mzm_icon( 'download', 16 ); // phpcs:ignore ?> کیورد ریسرچ (اکسل)</a>
		<?php endif; ?>
		<a class="mzm-btn is-primary" href="<?php echo esc_url( mzm_project_url( $project['id'], 'overview', array( 'run' => 1 ) ) ); ?>#run"><?php echo mzm_icon( 'play', 16 ); // phpcs:ignore ?> اجرای خوشه‌بندی</a>
	</div>
</div>
<nav class="mzm-tabs">
	<?php foreach ( $tabs as $key => $t ) : ?>
		<a class="<?php echo $key === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( mzm_project_url( $project['id'], $key ) ); ?>"><?php echo mzm_icon( $t[1], 16 ); // phpcs:ignore ?><?php echo esc_html( $t[0] ); ?><?php echo ! empty( $counts[ $key ] ) ? '<em>' . esc_html( mzm_num( $counts[ $key ] ) ) . '</em>' : ''; ?></a>
	<?php endforeach; ?>
</nav>
<?php
if ( 'settings' === $tab ) {
	mzm_view( 'project-form', array( 'project' => $project ) );
} elseif ( 'overview' !== $tab && 'export' !== $tab && empty( $result['clusters'] ) ) {
	?>
	<div class="mzm-empty">
		<?php echo mzm_icon( 'orbit', 40 ); // phpcs:ignore ?>
		<h2>هنوز خوشه‌بندی اجرا نشده</h2>
		<p>از «نمای کلی» کلمات را از گوگل شیت بخوانید یا فایل بارگذاری کنید و اجرای خوشه‌بندی را بزنید.</p>
		<a class="mzm-btn is-primary" href="<?php echo esc_url( mzm_project_url( $project['id'] ) ); ?>">رفتن به نمای کلی</a>
	</div>
	<?php
} else {
	mzm_view( 'project-' . $tab, array( 'project' => $project, 'R' => $result ) );
}
