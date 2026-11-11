<?php
/**
 * New keywords for a running project.
 *
 * @package Ghotbnama
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

if ( ! qtb_can() ) {
	return;
}
?>
<div class="qtb-panel" id="add">
	<h2><?php echo qtb_icon( 'plus', 18 ); // phpcs:ignore ?> افزودن کلمات جدید به پروژه <small>کلمات تکراری کنار می‌روند؛ فقط کلمات جدید غربال می‌شوند و به موضوع خودشان می‌روند. کلاسترهای بازبینی‌شده قفل می‌مانند.</small></h2>
	<form class="qtb-wsadd" data-qtb-wsadd="<?php echo esc_attr( $project['id'] ); ?>">
		<textarea name="text" rows="4" placeholder="هر خط یک کلمه؛ حجم بعد از تب یا کاما. مثال: قیمت اسکوتر برقی دو نفره, 90"></textarea>
		<div class="qtb-inline">
			<label class="qtb-upload is-light" style="margin:0;flex:1 1 260px"><?php echo qtb_icon( 'upload', 16 ); // phpcs:ignore ?> <span class="qtb-small">یا فایل (XLSX/CSV؛ ستون کلاستر و پیلار هم اختیاری)</span> <input type="file" name="file" accept=".xlsx,.csv,.tsv,.txt"></label>
			<button class="qtb-btn is-primary" type="submit"><?php echo qtb_icon( 'plus', 16 ); // phpcs:ignore ?> افزودن و موضوع‌بندی</button>
		</div>
	</form>
</div>
