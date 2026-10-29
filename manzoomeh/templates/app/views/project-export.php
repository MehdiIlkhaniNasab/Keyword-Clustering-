<?php
/**
 * Exports: Excel files, Google Sheets, CSV.
 *
 * @package Manzoomeh
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$has   = ! empty( $R['clusters'] );
$dl    = function ( $kind ) use ( $project ) {
	return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'manzoomeh/v1/download/' . $project['id'] . '/' . $kind ) );
};
$gs    = mzm_data_get( $project['id'], 'gsheet', array() );
$target = mzm_gsheet_target( $project );
?>
<?php if ( ! $has ) : ?>
	<div class="mzm-empty"><?php echo mzm_icon( 'download', 40 ); // phpcs:ignore ?><h2>هنوز خروجی‌ای نیست</h2><p>اول خوشه‌بندی را اجرا کنید.</p></div>
	<?php
	return;
endif;
?>
<div class="mzm-cards">
	<div class="mzm-card">
		<h3><?php echo mzm_icon( 'sheet', 20 ); // phpcs:ignore ?> فایل کیورد ریسرچ</h3>
		<p class="mzm-small mzm-muted" style="margin:0">داشبورد، یک شیت برای هر پیلار به سبک کیورد ریسرچ دلنا (سبز: لندینگ اصلی، آبی: لندینگ دسته/محصول، زرد: بلاگ)، نقشه کلمات، کلاسترها، شکاف محتوایی، کنیبالیزیشن، رقبا، ایده‌های بیشتر، دسته‌بندی و حذف‌شده‌ها.</p>
		<a class="mzm-btn is-primary" href="<?php echo esc_url( $dl( 'research' ) ); ?>"><?php echo mzm_icon( 'download', 16 ); // phpcs:ignore ?> دانلود اکسل</a>
	</div>
	<div class="mzm-card">
		<h3><?php echo mzm_icon( 'calendar', 20 ); // phpcs:ignore ?> فایل تقویم محتوا</h3>
		<p class="mzm-small mzm-muted" style="margin:0">ساختار تقویم آژانس: M1، M2… با Writing Guide، پنج Out Link و پنج In Link، Status، Publish Date؛ به‌علاوه Ideas، B keyword و بریف هر مقاله.</p>
		<a class="mzm-btn is-primary" href="<?php echo esc_url( $dl( 'calendar' ) ); ?>"><?php echo mzm_icon( 'download', 16 ); // phpcs:ignore ?> دانلود اکسل</a>
	</div>
	<div class="mzm-card">
		<h3><?php echo mzm_icon( 'list', 20 ); // phpcs:ignore ?> CSV نقشه کلمات</h3>
		<p class="mzm-small mzm-muted" style="margin:0">هر کلمه با پیلار، بخش، کلاستر، نوع صفحه، اولویت و صفحه هدف — برای ورود به ابزارهای دیگر (و سکان در آینده).</p>
		<a class="mzm-btn" href="<?php echo esc_url( $dl( 'csv' ) ); ?>"><?php echo mzm_icon( 'download', 16 ); // phpcs:ignore ?> دانلود CSV</a>
	</div>
</div>

<div class="mzm-panel" style="margin-top:16px" data-mzm-gsheet="<?php echo esc_attr( $project['id'] ); ?>">
	<h2><?php echo mzm_icon( 'sheet', 18 ); // phpcs:ignore ?> نوشتن در گوگل شیت</h2>
	<?php if ( '' === $target ) : ?>
		<p class="mzm-note is-warn"><?php echo mzm_icon( 'alert', 16 ); // phpcs:ignore ?><span>لینک گوگل شیت (ورودی یا خروجی) در تنظیمات پروژه وارد نشده است.</span></p>
	<?php elseif ( ! mzm_google_connected() ) : ?>
		<p class="mzm-note is-warn"><?php echo mzm_icon( 'alert', 16 ); // phpcs:ignore ?><span>اتصال گوگل تنظیم نشده است؛ از «تنظیمات و اتصال‌ها» سرویس اکانت را وارد کنید یا اتصال سکان را فعال کنید.</span></p>
	<?php else : ?>
		<p class="mzm-muted mzm-small">هر دو فایل با همان رنگ‌بندی، ادغام سلول‌ها، فیلتر، کشوی وضعیت و چک‌باکس در تب‌های جدید شیت نوشته می‌شوند. تب‌هایی که قبلاً منظومه نوشته جایگزین می‌شوند؛ تب‌های تیم دست نمی‌خورند.</p>
		<div class="mzm-inline">
			<button type="button" class="mzm-btn is-primary" data-mzm-gsheet-go><?php echo mzm_icon( 'upload', 16 ); // phpcs:ignore ?> نوشتن در گوگل شیت</button>
			<a class="mzm-btn is-ghost" href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . $target . '/edit' ); ?>" target="_blank" rel="noopener">باز کردن شیت</a>
			<span class="mzm-small mzm-muted" data-mzm-gsheet-msg><?php echo $gs ? esc_html( 'آخرین نوشتن: ' . mzm_jdate( $gs['at'] ?? '' ) . ' (' . mzm_num( count( (array) ( $gs['tabs'] ?? array() ) ) ) . ' تب)' ) : ''; ?></span>
		</div>
		<div class="mzm-bar" style="margin-top:10px" hidden data-mzm-gsheet-bar><i style="width:0"></i></div>
	<?php endif; ?>
</div>
