<?php
/**
 * 404.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="qtb-empty">
	<?php echo qtb_icon( 'research', 36 ); // phpcs:ignore ?>
	<h2>پیدا نشد</h2>
	<p>این صفحه یا پروژه وجود ندارد.</p>
	<a class="qtb-btn is-primary" href="<?php echo esc_url( qtb_url() ); ?>">بازگشت به داشبورد</a>
</div>
