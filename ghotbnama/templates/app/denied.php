<?php
/**
 * No access.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>دسترسی ندارید · قطب‌نما</title>
	<?php qtb_app_assets(); ?>
</head>
<body class="qtb-app">
<div class="qtb-login">
	<div class="qtb-login-box">
		<span class="qtb-brand-mark is-lg"><?php echo qtb_icon( 'shield', 30 ); // phpcs:ignore ?></span>
		<h1>دسترسی ندارید</h1>
		<p class="qtb-muted">این ابزار فقط برای مدیران سئو فعال است.</p>
		<p style="margin-top:16px"><a class="qtb-btn" href="<?php echo esc_url( wp_logout_url( qtb_url() ) ); ?>">ورود با حساب دیگر</a></p>
	</div>
</div>
</body>
</html>
