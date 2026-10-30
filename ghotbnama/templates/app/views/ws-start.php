<?php
/**
 * Start (or restart) the workshop: keywords from Google Sheets or a file.
 *
 * @package Ghotbnama
 * @var array $project
 * @var bool  $has
 */

defined( 'ABSPATH' ) || exit;

if ( ! qtb_can() ) {
	echo '<p class="qtb-note">' . qtb_icon( 'shield', 16 ) . '<span>دریافت و غربال کلمات با مدیر سئو است.</span></p>'; // phpcs:ignore
	return;
}
$sheet = '' !== qtb_sheet_id( $project['cfg']['sheet_url'] );
$input = qtb_data_get( $project['id'], 'input', array() );
?>
<div class="qtb-panel" data-qtb-wsstart="<?php echo esc_attr( $project['id'] ); ?>">
	<h2><?php echo qtb_icon( 'filter', 18 ); // phpcs:ignore ?> <?php echo $has ? 'شروع دوباره غربال' : 'شروع: دریافت و غربال کلمات'; ?></h2>
	<?php if ( $has ) : ?>
		<p class="qtb-note is-warn"><?php echo qtb_icon( 'alert', 16 ); // phpcs:ignore ?><span>شروع دوباره، فهرست کلمات و موضوع‌ها را از نو می‌سازد (قوانین پروژه و اصلاح‌های شما می‌مانند).</span></p>
	<?php endif; ?>
	<div class="qtb-inline">
		<?php if ( $sheet ) : ?>
			<button type="button" class="qtb-btn is-primary" data-qtb-wsgo="triage" data-source="sheet"<?php echo $has ? ' data-confirm="فهرست کلمات و موضوع‌ها از نو ساخته می‌شوند. ادامه؟"' : ''; ?>><?php echo qtb_icon( 'sheet', 16 ); // phpcs:ignore ?> از گوگل شیت</button>
		<?php endif; ?>
		<?php if ( $input ) : ?>
			<button type="button" class="qtb-btn" data-qtb-wsgo="triage" data-source="upload"<?php echo $has ? ' data-confirm="فهرست کلمات و موضوع‌ها از نو ساخته می‌شوند. ادامه؟"' : ''; ?>><?php echo qtb_icon( 'refresh', 16 ); // phpcs:ignore ?> با <?php echo esc_html( qtb_num( count( $input ) ) ); ?> کلمه فعلی</button>
		<?php endif; ?>
	</div>
	<form class="qtb-upload is-light" data-qtb-wsupload="<?php echo esc_attr( $project['id'] ); ?>">
		<?php echo qtb_icon( 'upload', 18 ); // phpcs:ignore ?>
		<input type="file" name="file" accept=".xlsx,.csv,.tsv,.txt" required>
		<button class="qtb-btn is-small" type="submit">بارگذاری فایل و شروع</button>
	</form>
</div>
