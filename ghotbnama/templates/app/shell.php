<?php
/**
 * App document: sidebar + main area.
 *
 * @package Ghotbnama
 * @var string     $view
 * @var string     $title
 * @var string     $nav
 * @var array      $vars
 * @var array|null $project
 */

defined( 'ABSPATH' ) || exit;

$user     = wp_get_current_user();
$projects = qtb_projects();
$cur_pid  = $project ? $project['id'] : 0;
$link     = function ( $key, $url, $icon, $label ) use ( $nav ) {
	printf( '<a class="%s" href="%s">%s<span>%s</span></a>', $key === $nav ? 'is-active' : '', esc_url( $url ), qtb_icon( $icon, 18 ), esc_html( $label ) ); // phpcs:ignore
};
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' · قطب‌نما' ); ?></title>
	<?php qtb_app_assets(); ?>
</head>
<body class="qtb-app view-<?php echo esc_attr( $view ); ?>">
<div class="qtb-shell">
	<aside class="qtb-side" id="qtb-side">
		<a class="qtb-brand" href="<?php echo esc_url( qtb_url() ); ?>">
			<span class="qtb-brand-mark"><?php echo qtb_icon( 'compass', 24 ); // phpcs:ignore ?></span>
			<span><b>قطب‌نما</b><small>کیورد کلاسترینگ هوشمند · رونق</small></span>
		</a>
		<nav class="qtb-nav">
			<?php $link( 'dashboard', qtb_url(), 'grid', 'داشبورد' ); ?>
			<p class="qtb-nav-h">پروژه‌ها</p>
			<?php foreach ( $projects as $p ) : ?>
				<a class="<?php echo $cur_pid === $p['id'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( qtb_project_url( $p['id'] ) ); ?>">
					<i class="qtb-dot <?php echo esc_attr( qtb_status_class( $p['status'] ) ); ?>"></i><span><?php echo esc_html( $p['name'] ); ?></span>
				</a>
			<?php endforeach; ?>
			<?php if ( qtb_can() ) : ?>
			<a class="qtb-nav-add <?php echo 'new' === $nav ? 'is-active' : ''; ?>" href="<?php echo esc_url( qtb_url( 'new' ) ); ?>"><?php echo qtb_icon( 'plus', 16 ); // phpcs:ignore ?><span>پروژه جدید</span></a>
			<?php endif; ?>
			<p class="qtb-nav-h">ابزار</p>
			<?php
			if ( qtb_can() ) {
				$link( 'settings', qtb_url( 'settings' ), 'settings', 'تنظیمات و اتصال‌ها' );
			}
			?>
			<?php $link( 'guide', qtb_url( 'guide' ), 'help', 'راهنما و روش کار' ); ?>
			<?php if ( defined( 'SAP_VERSION' ) && function_exists( 'sap_url' ) ) : ?>
				<a href="<?php echo esc_url( sap_url() ); ?>"><?php echo qtb_icon( 'target', 18 ); // phpcs:ignore ?><span>سُکان (اکشن پلن)</span></a>
			<?php endif; ?>
		</nav>
		<div class="qtb-side-foot">
			<span class="qtb-user"><i><?php echo esc_html( mb_substr( $user->display_name, 0, 1 ) ); ?></i><?php echo esc_html( $user->display_name ); ?></span>
			<span class="qtb-side-links">
				<a href="<?php echo esc_url( admin_url() ); ?>">وردپرس</a>
				<a href="<?php echo esc_url( wp_logout_url( qtb_url() ) ); ?>">خروج</a>
			</span>
		</div>
	</aside>
	<div class="qtb-backdrop" data-qtb-close-nav></div>
	<main class="qtb-main">
		<div class="qtb-topbar">
			<button type="button" class="qtb-menu-btn" data-qtb-open-nav aria-label="منو"><?php echo qtb_icon( 'list', 22 ); // phpcs:ignore ?></button>
			<a href="<?php echo esc_url( qtb_url() ); ?>">قطب‌نما</a>
		</div>
		<div class="qtb-wrap">
			<?php
			qtb_flash();
			qtb_view( $view, $vars );
			?>
		</div>
	</main>
</div>
<div class="qtb-toast" id="qtb-toast" role="status"></div>
</body>
</html>
