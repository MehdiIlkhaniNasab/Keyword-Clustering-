<?php
/**
 * Dashboard: connections and all projects.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

$projects = qtb_projects();
$ai       = qtb_ai_conf();
$serp     = qtb_serp_conf();
$google   = qtb_google_conf();
?>
<div class="qtb-head">
	<div>
		<p class="qtb-eyebrow"><?php echo qtb_icon( 'compass', 14 ); // phpcs:ignore ?> قطب‌نما</p>
		<h1>کیورد کلاسترینگ فروش‌محور</h1>
		<p class="qtb-muted">کلمات را بدهید؛ پیلار، کلاستر، نوع صفحه، اولویت فروش، شکاف محتوایی و تقویم محتوا تحویل بگیرید.</p>
	</div>
	<div class="qtb-actions">
		<a class="qtb-btn is-primary" href="<?php echo esc_url( qtb_url( 'new' ) ); ?>"><?php echo qtb_icon( 'plus', 16 ); // phpcs:ignore ?> پروژه جدید</a>
	</div>
</div>

<div class="qtb-status-row">
	<?php
	qtb_conn( 'هوش مصنوعی' . ( qtb_ai_connected() ? ' (' . $ai['model_light'] . ')' : '' ), qtb_ai_connected() ? ( 'sokan' === $ai['source'] ? 'sokan' : true ) : false );
	qtb_conn( 'نتایج گوگل' . ( qtb_serp_ready() ? ' (' . $serp['provider'] . ')' : ' — حالت رایگان' ), qtb_serp_ready() ? ( 'sokan' === $serp['source'] ? 'sokan' : true ) : false );
	qtb_conn( 'گوگل شیت', 'off' !== $google['source'] ? ( 'sokan' === $google['source'] ? 'sokan' : true ) : false );
	?>
	<a class="qtb-small" href="<?php echo esc_url( qtb_url( 'settings' ) ); ?>">تنظیمات اتصال‌ها</a>
</div>

<?php if ( ! $projects ) : ?>
	<div class="qtb-panel is-hero">
		<h2><?php echo qtb_icon( 'spark', 20 ); // phpcs:ignore ?> از یک فهرست کلمه تا نقشه کامل فروش</h2>
		<p>در گوگل شیت پروژه یک تب «کلمات کلیدی» با دو ستون بسازید (کلمه کلیدی، حجم جستجو) — یا همان فایل خروجی Keyword Planner / سئو سیگنال را بارگذاری کنید. قطب‌نما کلمات بی‌ارزش و برند رقبا را حذف می‌کند، ۱۰ نتیجه اول گوگل را بررسی می‌کند، کلمات هم‌کانسپت را در یک صفحه جمع می‌کند و بر اساس ارزش فروش اولویت می‌دهد.</p>
		<p><a class="qtb-btn is-gold" href="<?php echo esc_url( qtb_url( 'new' ) ); ?>"><?php echo qtb_icon( 'plus', 16 ); // phpcs:ignore ?> ساخت اولین پروژه</a></p>
	</div>
<?php else : ?>
	<div class="qtb-cards">
		<?php
		foreach ( $projects as $p ) :
			$meta = qtb_data_get( $p['id'], 'meta', array() );
			?>
			<a class="qtb-card" href="<?php echo esc_url( qtb_project_url( $p['id'] ) ); ?>">
				<div>
					<h3><i class="qtb-dot <?php echo esc_attr( qtb_status_class( $p['status'] ) ); ?>" style="display:inline-block"></i> <?php echo esc_html( $p['name'] ); ?></h3>
					<small class="qtb-ltr"><?php echo esc_html( qtb_host( $p['site_url'] ) ); ?></small>
				</div>
				<?php if ( $meta ) : ?>
					<div class="qtb-mini">
						<span>کلمات<b><?php echo esc_html( qtb_num( $meta['keywords'] ?? 0 ) ); ?></b></span>
						<span>کلاستر<b><?php echo esc_html( qtb_num( $meta['clusters'] ?? 0 ) ); ?></b></span>
						<span>پیلار<b><?php echo esc_html( qtb_num( $meta['pillars'] ?? 0 ) ); ?></b></span>
						<span>صفحه جدید<b><?php echo esc_html( qtb_num( $meta['coverage']['new'] ?? 0 ) ); ?></b></span>
					</div>
					<small>آخرین اجرا: <?php echo esc_html( qtb_jdate( $meta['built'] ?? '' ) ); ?></small>
				<?php else : ?>
					<p class="qtb-muted qtb-small" style="margin:0">هنوز اجرا نشده؛ وارد پروژه شوید و «اجرای خوشه‌بندی» را بزنید.</p>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
		<a class="qtb-card is-add" href="<?php echo esc_url( qtb_url( 'new' ) ); ?>"><?php echo qtb_icon( 'plus', 26 ); // phpcs:ignore ?><b>پروژه جدید</b></a>
	</div>
<?php endif; ?>
