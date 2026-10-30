<?php
/**
 * Workshop step 1: business profile (the memory every AI decision uses).
 *
 * @package Ghotbnama
 * @var array    $project
 * @var array    $ws
 * @var bool     $has
 * @var callable $url
 */

defined( 'ABSPATH' ) || exit;

$cfg  = $project['cfg'];
$inv  = qtb_data_get( $project['id'], 'inventory', array() );
$cats = array();
foreach ( (array) ( $inv['items'] ?? array() ) as $it ) {
	if ( 'category' === $it['type'] ) {
		$cats[] = $it['title'] . ( ! empty( $it['count'] ) ? ' (' . qtb_num( $it['count'] ) . ')' : '' );
	}
}
$by = array_count_values( array_column( (array) ( $inv['items'] ?? array() ), 'type' ) );
?>
<div class="qtb-grid-2" style="gap:16px;align-items:start">
	<form method="post" class="qtb-panel">
		<?php qtb_form_fields( 'save_profile', array( 'project_id' => $project['id'] ) ); ?>
		<h2><?php echo qtb_icon( 'store', 18 ); // phpcs:ignore ?> پروفایل کسب‌وکار <small>حافظه پایه پروژه؛ هوش مصنوعی همه تصمیم‌ها را با این می‌گیرد</small></h2>
		<label>حوزه کسب‌وکار<textarea name="niche" rows="2"><?php echo esc_textarea( $cfg['niche'] ); ?></textarea></label>
		<label>چه می‌فروشید؟ <small>دسته‌ها، محصولات و برندهایی که دارید. مثال: «چسب کفش (راضی، بیسان)، واکس، بند کفش، کفی طبی»</small><textarea name="sells" rows="3"><?php echo esc_textarea( $cfg['sells'] ); ?></textarea></label>
		<label>چه نمی‌فروشید؟ <small>کلماتشان در غربال حذف می‌شوند. مثال: «کفش، تعمیر کفش، چسب صنعتی»</small><textarea name="not_sells" rows="2"><?php echo esc_textarea( $cfg['not_sells'] ); ?></textarea></label>
		<div class="qtb-grid-2">
			<label>خدماتی که ارائه می‌دهید <small>هر کدام یک خط؛ جستجوی این خدمات لندینگ می‌گیرد</small><textarea name="services" rows="3"><?php echo esc_textarea( implode( "\n", (array) $cfg['services'] ) ); ?></textarea></label>
			<label>شهرهایی که شعبه حضوری دارید <small>خالی = فقط فروش آنلاین</small><textarea name="locations" rows="3"><?php echo esc_textarea( implode( "\n", (array) $cfg['locations'] ) ); ?></textarea></label>
			<label>برند خودمان<textarea name="brands" rows="2"><?php echo esc_textarea( implode( "\n", (array) $cfg['brands'] ) ); ?></textarea></label>
			<label>برندها و فروشگاه‌های رقیب<textarea name="competitors" rows="2"><?php echo esc_textarea( implode( "\n", (array) $cfg['competitors'] ) ); ?></textarea></label>
		</div>
		<label>اولویت‌های فروش <small>دسته‌هایی که سود یا اهمیت بیشتری دارند</small><input type="text" name="priorities" value="<?php echo esc_attr( $cfg['priorities'] ); ?>"></label>
		<label>روش بازبینی هوشمند پیش‌فرض</label>
		<div class="qtb-choice">
			<label><input type="radio" name="review_mode" value="batch" <?php checked( $cfg['review_mode'], 'batch' ); ?>><span><b>دسته‌ای (پیشنهادی)</b><small>هر درخواست حدود ۲۵ کلمه با کلاسترهای کاندیدش؛ سریع و ارزان</small></span></label>
			<label><input type="radio" name="review_mode" value="single" <?php checked( $cfg['review_mode'], 'single' ); ?>><span><b>تک‌تک</b><small>هر کلمه یک درخواست با جزئیات بیشتر؛ کندتر و گران‌تر — برای مقایسه</small></span></label>
		</div>
		<div class="qtb-form-foot"><button class="qtb-btn is-primary" type="submit"><?php echo qtb_icon( 'check', 16 ); // phpcs:ignore ?> ذخیره پروفایل</button></div>
	</form>
	<div>
		<div class="qtb-panel">
			<h2><?php echo qtb_icon( 'globe', 18 ); // phpcs:ignore ?> آنچه از سایت می‌دانیم</h2>
			<?php if ( empty( $inv['items'] ) ) : ?>
				<p class="qtb-muted qtb-small">هنوز صفحات سایت خوانده نشده؛ در شروع غربال از افزونه رابط (یا روش‌های دیگر) خوانده می‌شود.</p>
			<?php else : ?>
				<p class="qtb-small">از «<?php echo esc_html( qtb_store_source_label( $inv['source'] ?? '' ) ); ?>»: <?php echo esc_html( sprintf( '%s محصول، %s دسته/برند/برچسب، %s مقاله، %s برگه', qtb_num( $by['product'] ?? 0 ), qtb_num( $by['category'] ?? 0 ), qtb_num( $by['post'] ?? 0 ), qtb_num( $by['page'] ?? 0 ) ) ); ?></p>
				<?php if ( $cats ) : ?><p class="qtb-small qtb-muted" style="margin:0"><?php echo esc_html( implode( '، ', array_slice( $cats, 0, 40 ) ) ); ?></p><?php endif; ?>
			<?php endif; ?>
		</div>
		<?php qtb_view( 'ws-start', array( 'project' => $project, 'has' => $has ) ); ?>
	</div>
</div>
