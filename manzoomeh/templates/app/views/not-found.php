<?php
/**
 * 404.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mzm-empty">
	<?php echo mzm_icon( 'research', 36 ); // phpcs:ignore ?>
	<h2>پیدا نشد</h2>
	<p>این صفحه یا پروژه وجود ندارد.</p>
	<a class="mzm-btn is-primary" href="<?php echo esc_url( mzm_url() ); ?>">بازگشت به داشبورد</a>
</div>
