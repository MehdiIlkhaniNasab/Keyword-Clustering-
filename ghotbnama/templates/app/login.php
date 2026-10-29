<?php
/**
 * Login screen of the app.
 *
 * @package Ghotbnama
 * @var string $redirect
 */

defined( 'ABSPATH' ) || exit;
$failed = isset( $_GET['qtb_login'] ); // phpcs:ignore
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>ورود · قطب‌نما</title>
	<?php qtb_app_assets(); ?>
</head>
<body class="qtb-app">
<div class="qtb-login">
	<div class="qtb-login-box">
		<span class="qtb-brand-mark is-lg"><?php echo qtb_icon( 'compass', 32 ); // phpcs:ignore ?></span>
		<h1>قطب‌نما</h1>
		<p class="qtb-muted">کیورد کلاسترینگ هوشمند و فروش‌محور · رونق</p>
		<?php if ( $failed ) : ?>
			<div class="qtb-flash is-err" style="margin-top:14px">نام کاربری یا رمز عبور اشتباه است.</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
			<label>نام کاربری یا ایمیل<input type="text" name="log" autocomplete="username" required dir="ltr"></label>
			<label>رمز عبور<input type="password" name="pwd" autocomplete="current-password" required dir="ltr"></label>
			<label class="qtb-check"><input type="checkbox" name="rememberme" value="forever" checked> مرا به خاطر بسپار</label>
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
			<input type="hidden" name="qtb_login" value="1">
			<button class="qtb-btn is-primary is-block" type="submit">ورود</button>
		</form>
	</div>
</div>
</body>
</html>
