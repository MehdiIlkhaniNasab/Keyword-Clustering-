<?php
/**
 * No access.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>دسترسی ندارید · منظومه</title>
	<?php mzm_app_assets(); ?>
</head>
<body class="mzm-app">
<div class="mzm-login">
	<div class="mzm-login-box">
		<span class="mzm-brand-mark is-lg"><?php echo mzm_icon( 'shield', 30 ); // phpcs:ignore ?></span>
		<h1>دسترسی ندارید</h1>
		<p class="mzm-muted">این ابزار فقط برای مدیران سئو فعال است.</p>
		<p style="margin-top:16px"><a class="mzm-btn" href="<?php echo esc_url( wp_logout_url( mzm_url() ) ); ?>">ورود با حساب دیگر</a></p>
	</div>
</div>
</body>
</html>
