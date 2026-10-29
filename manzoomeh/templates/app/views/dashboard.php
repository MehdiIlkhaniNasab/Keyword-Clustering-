<?php
/**
 * Dashboard: connections and all projects.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

$projects = mzm_projects();
$ai       = mzm_ai_conf();
$serp     = mzm_serp_conf();
$google   = mzm_google_conf();
?>
<div class="mzm-head">
	<div>
		<p class="mzm-eyebrow"><?php echo mzm_icon( 'orbit', 14 ); // phpcs:ignore ?> منظومه</p>
		<h1>کیورد کلاسترینگ فروش‌محور</h1>
		<p class="mzm-muted">کلمات را بدهید؛ پیلار، کلاستر، نوع صفحه، اولویت فروش، شکاف محتوایی و تقویم محتوا تحویل بگیرید.</p>
	</div>
	<div class="mzm-actions">
		<a class="mzm-btn is-primary" href="<?php echo esc_url( mzm_url( 'new' ) ); ?>"><?php echo mzm_icon( 'plus', 16 ); // phpcs:ignore ?> پروژه جدید</a>
	</div>
</div>

<div class="mzm-status-row">
	<?php
	mzm_conn( 'هوش مصنوعی' . ( mzm_ai_connected() ? ' (' . $ai['model_light'] . ')' : '' ), mzm_ai_connected() ? ( 'sokan' === $ai['source'] ? 'sokan' : true ) : false );
	mzm_conn( 'نتایج گوگل' . ( mzm_serp_ready() ? ' (' . $serp['provider'] . ')' : ' — حالت رایگان' ), mzm_serp_ready() ? ( 'sokan' === $serp['source'] ? 'sokan' : true ) : false );
	mzm_conn( 'گوگل شیت', 'off' !== $google['source'] ? ( 'sokan' === $google['source'] ? 'sokan' : true ) : false );
	?>
	<a class="mzm-small" href="<?php echo esc_url( mzm_url( 'settings' ) ); ?>">تنظیمات اتصال‌ها</a>
</div>

<?php if ( ! $projects ) : ?>
	<div class="mzm-panel is-hero">
		<h2><?php echo mzm_icon( 'spark', 20 ); // phpcs:ignore ?> از یک فهرست کلمه تا نقشه کامل فروش</h2>
		<p>در گوگل شیت پروژه یک تب «کلمات کلیدی» با دو ستون بسازید (کلمه کلیدی، حجم جستجو) — یا همان فایل خروجی Keyword Planner / سئو سیگنال را بارگذاری کنید. منظومه کلمات بی‌ارزش و برند رقبا را حذف می‌کند، ۱۰ نتیجه اول گوگل را بررسی می‌کند، کلمات هم‌کانسپت را در یک صفحه جمع می‌کند و بر اساس ارزش فروش اولویت می‌دهد.</p>
		<p><a class="mzm-btn is-gold" href="<?php echo esc_url( mzm_url( 'new' ) ); ?>"><?php echo mzm_icon( 'plus', 16 ); // phpcs:ignore ?> ساخت اولین پروژه</a></p>
	</div>
<?php else : ?>
	<div class="mzm-cards">
		<?php
		foreach ( $projects as $p ) :
			$meta = mzm_data_get( $p['id'], 'meta', array() );
			?>
			<a class="mzm-card" href="<?php echo esc_url( mzm_project_url( $p['id'] ) ); ?>">
				<div>
					<h3><i class="mzm-dot <?php echo esc_attr( mzm_status_class( $p['status'] ) ); ?>" style="display:inline-block"></i> <?php echo esc_html( $p['name'] ); ?></h3>
					<small class="mzm-ltr"><?php echo esc_html( mzm_host( $p['site_url'] ) ); ?></small>
				</div>
				<?php if ( $meta ) : ?>
					<div class="mzm-mini">
						<span>کلمات<b><?php echo esc_html( mzm_num( $meta['keywords'] ?? 0 ) ); ?></b></span>
						<span>کلاستر<b><?php echo esc_html( mzm_num( $meta['clusters'] ?? 0 ) ); ?></b></span>
						<span>پیلار<b><?php echo esc_html( mzm_num( $meta['pillars'] ?? 0 ) ); ?></b></span>
						<span>صفحه جدید<b><?php echo esc_html( mzm_num( $meta['coverage']['new'] ?? 0 ) ); ?></b></span>
					</div>
					<small>آخرین اجرا: <?php echo esc_html( mzm_jdate( $meta['built'] ?? '' ) ); ?></small>
				<?php else : ?>
					<p class="mzm-muted mzm-small" style="margin:0">هنوز اجرا نشده؛ وارد پروژه شوید و «اجرای خوشه‌بندی» را بزنید.</p>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
		<a class="mzm-card is-add" href="<?php echo esc_url( mzm_url( 'new' ) ); ?>"><?php echo mzm_icon( 'plus', 26 ); // phpcs:ignore ?><b>پروژه جدید</b></a>
	</div>
<?php endif; ?>
