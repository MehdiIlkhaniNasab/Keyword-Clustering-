<?php
/**
 * Workshop step 4: turn approved topics into the research and the content calendar.
 *
 * @package Ghotbnama
 * @var array    $project
 * @var array    $ws
 * @var callable $url
 */

defined( 'ABSPATH' ) || exit;

$by = array_count_values( array_column( $ws['topics'], 'status' ) );
?>
<div class="qtb-panel is-attention" data-qtb-final="<?php echo esc_attr( $project['id'] ); ?>">
	<h2><?php echo qtb_icon( 'check', 18 ); // phpcs:ignore ?> ساخت خروجی نهایی</h2>
	<p><?php echo esc_html( sprintf( '%s موضوع تأییدشده، %s موضوع آماده بازبینی، %s موضوع پردازش‌نشده.', qtb_num( $by['approved'] ?? 0 ), qtb_num( $by['ready'] ?? 0 ), qtb_num( $by['new'] ?? 0 ) ) ); ?></p>
	<p class="qtb-small qtb-muted">کلاسترهای تأییدشده با همان نام، نوع صفحه و بخشی که شما تعیین کرده‌اید وارد کیورد ریسرچ می‌شوند؛ بعد امتیاز فروش، نگاشت به صفحات سایت، کنیبالیزیشن، تحلیل شکاف و تقویم محتوا ساخته می‌شود و خروجی اکسل و گوگل شیت آماده است. این کار نتیجه قبلی تب‌های پروژه را جایگزین می‌کند.</p>
	<?php if ( $ws['finalized'] ) : ?><p class="qtb-small">آخرین ساخت: <?php echo esc_html( qtb_jdate( $ws['finalized'] ) ); ?></p><?php endif; ?>
	<?php if ( qtb_can() ) : ?>
		<label class="qtb-check"><input type="checkbox" data-qtb-include-ready> موضوع‌های «آماده بازبینی» هم (بدون تأیید) وارد شوند</label>
		<button type="button" class="qtb-btn is-primary" data-qtb-finalize data-url="<?php echo esc_url( qtb_project_url( $project['id'], 'overview', array( 'run' => 1 ) ) ); ?>"><?php echo qtb_icon( 'play', 16 ); // phpcs:ignore ?> ساخت کیورد ریسرچ و تقویم</button>
	<?php else : ?>
		<p class="qtb-note"><?php echo qtb_icon( 'shield', 16 ); // phpcs:ignore ?><span>ساخت خروجی نهایی با مدیر سئو است.</span></p>
	<?php endif; ?>
</div>
