<?php
/**
 * Login screen of the app.
 *
 * @package Manzoomeh
 * @var string $redirect
 */

defined( 'ABSPATH' ) || exit;
$failed = isset( $_GET['mzm_login'] ); // phpcs:ignore
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>ورود · منظومه</title>
	<?php mzm_app_assets(); ?>
</head>
<body class="mzm-app">
<div class="mzm-login">
	<div class="mzm-login-box">
		<span class="mzm-brand-mark is-lg"><?php echo mzm_icon( 'orbit', 32 ); // phpcs:ignore ?></span>
		<h1>منظومه</h1>
		<p class="mzm-muted">کیورد کلاسترینگ هوشمند و فروش‌محور · رونق</p>
		<?php if ( $failed ) : ?>
			<div class="mzm-flash is-err" style="margin-top:14px">نام کاربری یا رمز عبور اشتباه است.</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
			<label>نام کاربری یا ایمیل<input type="text" name="log" autocomplete="username" required dir="ltr"></label>
			<label>رمز عبور<input type="password" name="pwd" autocomplete="current-password" required dir="ltr"></label>
			<label class="mzm-check"><input type="checkbox" name="rememberme" value="forever" checked> مرا به خاطر بسپار</label>
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
			<input type="hidden" name="mzm_login" value="1">
			<button class="mzm-btn is-primary is-block" type="submit">ورود</button>
		</form>
	</div>
</div>
</body>
</html>
