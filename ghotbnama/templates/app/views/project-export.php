<?php
/**
 * Exports: Excel files, Google Sheets, CSV.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$has   = ! empty( $R['clusters'] );
$dl    = function ( $kind ) use ( $project ) {
	return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'ghotbnama/v1/download/' . $project['id'] . '/' . $kind ) );
};
$gs    = qtb_data_get( $project['id'], 'gsheet', array() );
$target = qtb_gsheet_target( $project );
?>
<?php if ( ! $has ) : ?>
	<div class="qtb-empty"><?php echo qtb_icon( 'download', 40 ); // phpcs:ignore ?><h2>هنوز خروجی‌ای نیست</h2><p>اول خوشه‌بندی را اجرا کنید.</p></div>
	<?php
	return;
endif;
?>
<div class="qtb-cards">
	<div class="qtb-card">
		<h3><?php echo qtb_icon( 'sheet', 20 ); // phpcs:ignore ?> فایل کیورد ریسرچ</h3>
		<p class="qtb-small qtb-muted" style="margin:0">خلاصه، یک شیت برای هر پیلار به سبک کیورد ریسرچ (بخش‌ها، هر صفحه با کلماتش و جمع حجم؛ سبز لندینگ، آبی آرشیو، نارنجی محصول، زرد مقاله)، نقشه صفحات (هر صفحه و همه کلماتش) و کلمات حذف‌شده و پارکینگ.</p>
		<a class="qtb-btn is-primary" href="<?php echo esc_url( $dl( 'research' ) ); ?>"><?php echo qtb_icon( 'download', 16 ); // phpcs:ignore ?> دانلود اکسل</a>
	</div>
	<div class="qtb-card">
		<h3><?php echo qtb_icon( 'list', 20 ); // phpcs:ignore ?> CSV نقشه کلمات</h3>
		<p class="qtb-small qtb-muted" style="margin:0">هر کلمه با پیلار، بخش، کلاستر، نوع صفحه، اولویت و صفحه هدف — برای ورود به ابزارهای دیگر (و سکان در آینده).</p>
		<a class="qtb-btn" href="<?php echo esc_url( $dl( 'csv' ) ); ?>"><?php echo qtb_icon( 'download', 16 ); // phpcs:ignore ?> دانلود CSV</a>
	</div>
</div>

<div class="qtb-panel" style="margin-top:16px" data-qtb-gsheet="<?php echo esc_attr( $project['id'] ); ?>">
	<h2><?php echo qtb_icon( 'sheet', 18 ); // phpcs:ignore ?> نوشتن در گوگل شیت</h2>
	<?php if ( '' === $target ) : ?>
		<p class="qtb-note is-warn"><?php echo qtb_icon( 'alert', 16 ); // phpcs:ignore ?><span>لینک گوگل شیت (ورودی یا خروجی) در تنظیمات پروژه وارد نشده است.</span></p>
	<?php elseif ( ! qtb_google_connected() ) : ?>
		<p class="qtb-note is-warn"><?php echo qtb_icon( 'alert', 16 ); // phpcs:ignore ?><span>اتصال گوگل تنظیم نشده است؛ از «تنظیمات و اتصال‌ها» سرویس اکانت را وارد کنید یا اتصال سکان را فعال کنید.</span></p>
	<?php else : ?>
		<p class="qtb-muted qtb-small">کیورد ریسرچ با همان طراحی، ادغام سلول‌ها و فیلترها در تب‌های جدید شیت نوشته می‌شود. تب‌هایی که قبلاً قطب‌نما نوشته جایگزین می‌شوند؛ تب‌های تیم دست نمی‌خورند.</p>
		<div class="qtb-inline">
			<button type="button" class="qtb-btn is-primary" data-qtb-gsheet-go><?php echo qtb_icon( 'upload', 16 ); // phpcs:ignore ?> نوشتن در گوگل شیت</button>
			<a class="qtb-btn is-ghost" href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . $target . '/edit' ); ?>" target="_blank" rel="noopener">باز کردن شیت</a>
			<span class="qtb-small qtb-muted" data-qtb-gsheet-msg><?php echo $gs ? esc_html( 'آخرین نوشتن: ' . qtb_jdate( $gs['at'] ?? '' ) . ' (' . qtb_num( count( (array) ( $gs['tabs'] ?? array() ) ) ) . ' تب)' ) : ''; ?></span>
		</div>
		<div class="qtb-bar" style="margin-top:10px" hidden data-qtb-gsheet-bar><i style="width:0"></i></div>
	<?php endif; ?>
</div>
