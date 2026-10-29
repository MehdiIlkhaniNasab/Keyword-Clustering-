<?php
/**
 * App document: sidebar + main area.
 *
 * @package Manzoomeh
 * @var string     $view
 * @var string     $title
 * @var string     $nav
 * @var array      $vars
 * @var array|null $project
 */

defined( 'ABSPATH' ) || exit;

$user     = wp_get_current_user();
$projects = mzm_projects();
$cur_pid  = $project ? $project['id'] : 0;
$link     = function ( $key, $url, $icon, $label ) use ( $nav ) {
	printf( '<a class="%s" href="%s">%s<span>%s</span></a>', $key === $nav ? 'is-active' : '', esc_url( $url ), mzm_icon( $icon, 18 ), esc_html( $label ) ); // phpcs:ignore
};
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' · منظومه' ); ?></title>
	<?php mzm_app_assets(); ?>
</head>
<body class="mzm-app view-<?php echo esc_attr( $view ); ?>">
<div class="mzm-shell">
	<aside class="mzm-side" id="mzm-side">
		<a class="mzm-brand" href="<?php echo esc_url( mzm_url() ); ?>">
			<span class="mzm-brand-mark"><?php echo mzm_icon( 'orbit', 24 ); // phpcs:ignore ?></span>
			<span><b>منظومه</b><small>کیورد کلاسترینگ هوشمند · رونق</small></span>
		</a>
		<nav class="mzm-nav">
			<?php $link( 'dashboard', mzm_url(), 'grid', 'داشبورد' ); ?>
			<p class="mzm-nav-h">پروژه‌ها</p>
			<?php foreach ( $projects as $p ) : ?>
				<a class="<?php echo $cur_pid === $p['id'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( mzm_project_url( $p['id'] ) ); ?>">
					<i class="mzm-dot <?php echo esc_attr( mzm_status_class( $p['status'] ) ); ?>"></i><span><?php echo esc_html( $p['name'] ); ?></span>
				</a>
			<?php endforeach; ?>
			<a class="mzm-nav-add <?php echo 'new' === $nav ? 'is-active' : ''; ?>" href="<?php echo esc_url( mzm_url( 'new' ) ); ?>"><?php echo mzm_icon( 'plus', 16 ); // phpcs:ignore ?><span>پروژه جدید</span></a>
			<p class="mzm-nav-h">ابزار</p>
			<?php $link( 'settings', mzm_url( 'settings' ), 'settings', 'تنظیمات و اتصال‌ها' ); ?>
			<?php $link( 'guide', mzm_url( 'guide' ), 'help', 'راهنما و روش کار' ); ?>
			<?php if ( defined( 'SAP_VERSION' ) && function_exists( 'sap_url' ) ) : ?>
				<a href="<?php echo esc_url( sap_url() ); ?>"><?php echo mzm_icon( 'target', 18 ); // phpcs:ignore ?><span>سُکان (اکشن پلن)</span></a>
			<?php endif; ?>
		</nav>
		<div class="mzm-side-foot">
			<span class="mzm-user"><i><?php echo esc_html( mb_substr( $user->display_name, 0, 1 ) ); ?></i><?php echo esc_html( $user->display_name ); ?></span>
			<span class="mzm-side-links">
				<a href="<?php echo esc_url( admin_url() ); ?>">وردپرس</a>
				<a href="<?php echo esc_url( wp_logout_url( mzm_url() ) ); ?>">خروج</a>
			</span>
		</div>
	</aside>
	<div class="mzm-backdrop" data-mzm-close-nav></div>
	<main class="mzm-main">
		<div class="mzm-topbar">
			<button type="button" class="mzm-menu-btn" data-mzm-open-nav aria-label="منو"><?php echo mzm_icon( 'list', 22 ); // phpcs:ignore ?></button>
			<a href="<?php echo esc_url( mzm_url() ); ?>">منظومه</a>
		</div>
		<div class="mzm-wrap">
			<?php
			mzm_flash();
			mzm_view( $view, $vars );
			?>
		</div>
	</main>
</div>
<div class="mzm-toast" id="mzm-toast" role="status"></div>
</body>
</html>
