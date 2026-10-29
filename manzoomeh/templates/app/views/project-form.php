<?php
/**
 * New project / project settings form.
 *
 * @package Manzoomeh
 * @var array|null $project
 */

defined( 'ABSPATH' ) || exit;

$is_new = empty( $project );
$cfg    = $is_new ? mzm_project_defaults() : $project['cfg'];
$mail   = mzm_google_service_email();
$days   = array( 6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه' );
$start  = '' !== (string) $cfg['start_date'] ? mzm_fa( vsprintf( '%04d/%02d/%02d', mzm_g2j( ...array_map( 'intval', explode( '-', $cfg['start_date'] ) ) ) ) ) : '';
if ( $is_new ) :
	?>
	<div class="mzm-head">
		<div>
			<p class="mzm-eyebrow"><?php echo mzm_icon( 'plus', 14 ); // phpcs:ignore ?> پروژه جدید</p>
			<h1>یک سایت، یک منظومه</h1>
			<p class="mzm-muted">فقط نام و آدرس سایت لازم است؛ بقیه را می‌توانید بعداً تنظیم کنید.</p>
		</div>
	</div>
<?php endif; ?>
<form method="post" class="mzm-form">
	<?php mzm_form_fields( 'save_project', array( 'project_id' => $is_new ? 0 : $project['id'] ) ); ?>
	<div class="mzm-panel">
		<h2><?php echo mzm_icon( 'store', 18 ); // phpcs:ignore ?> کسب‌وکار</h2>
		<div class="mzm-grid-2">
			<label>نام پروژه<input type="text" name="name" required value="<?php echo esc_attr( $is_new ? '' : $project['name'] ); ?>" placeholder="مثلاً دلنا"></label>
			<label>آدرس سایت<input type="url" name="site_url" dir="ltr" value="<?php echo esc_attr( $is_new ? '' : $project['site_url'] ); ?>" placeholder="https://example.com"></label>
		</div>
		<label>حوزه کسب‌وکار و محصولات <small>هر چه دقیق‌تر بنویسید، حذف کلمات نامرتبط و نام‌گذاری پیلارها دقیق‌تر است. مثال: «فروشگاه آنلاین گل و گیاه آپارتمانی، گلدان و خاک؛ ارسال به سراسر ایران»</small>
			<textarea name="niche" rows="2"><?php echo esc_textarea( $cfg['niche'] ); ?></textarea>
		</label>
		<div class="mzm-grid-3">
			<label>برند خودمان <small>یک مورد در هر خط (فارسی و انگلیسی)</small><textarea name="brands" rows="3"><?php echo esc_textarea( implode( "\n", (array) $cfg['brands'] ) ); ?></textarea></label>
			<label>برندها و سایت‌های رقیب <small>کلماتی که این نام‌ها را دارند حذف می‌شوند. بازارگاه‌ها (دیجیکالا، ترب، باسلام، دیوار…) خودکار حذف می‌شوند.</small><textarea name="competitors" rows="3"><?php echo esc_textarea( implode( "\n", (array) $cfg['competitors'] ) ); ?></textarea></label>
			<label>کلمات منفی <small>هر کلمه‌ای که این‌ها را داشته باشد حذف می‌شود (مثلاً «رایگان»، «دست دوم»)</small><textarea name="negatives" rows="3"><?php echo esc_textarea( implode( "\n", (array) $cfg['negatives'] ) ); ?></textarea></label>
		</div>
	</div>

	<div class="mzm-panel">
		<h2><?php echo mzm_icon( 'sheet', 18 ); // phpcs:ignore ?> گوگل شیت</h2>
		<div class="mzm-grid-3">
			<label class="mzm-span-2">لینک گوگل شیت ورودی<input type="url" name="sheet_url" dir="ltr" value="<?php echo esc_attr( $cfg['sheet_url'] ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…"></label>
			<label>نام تب کلمات<input type="text" name="input_tab" value="<?php echo esc_attr( $cfg['input_tab'] ); ?>"></label>
		</div>
		<label>لینک گوگل شیت خروجی <small>خالی = خروجی در همان شیت ورودی نوشته می‌شود (تب‌های جدید؛ تب‌های تیم دست نمی‌خورد).</small><input type="url" name="output_url" dir="ltr" value="<?php echo esc_attr( $cfg['output_url'] ); ?>"></label>
		<label class="mzm-check"><input type="checkbox" name="auto_write" value="1" <?php checked( ! empty( $cfg['auto_write'] ) ); ?>> بعد از هر اجرا، خروجی خودکار در گوگل شیت نوشته شود</label>
		<?php if ( $mail ) : ?>
			<p class="mzm-note"><?php echo mzm_icon( 'link', 16 ); // phpcs:ignore ?><span>شیت را با این ایمیل با دسترسی Editor به‌اشتراک بگذارید: <code data-mzm-copy><?php echo esc_html( $mail ); ?></code></span></p>
		<?php elseif ( 'off' === mzm_google_conf()['source'] ) : ?>
			<p class="mzm-note is-warn"><?php echo mzm_icon( 'alert', 16 ); // phpcs:ignore ?><span>اتصال گوگل تنظیم نشده؛ در «تنظیمات» سرویس اکانت را وارد کنید (یا اتصال سکان را فعال کنید). تا آن موقع می‌توانید فایل کلمات را بارگذاری کنید.</span></p>
		<?php endif; ?>
	</div>

	<div class="mzm-panel">
		<h2><?php echo mzm_icon( 'orbit', 18 ); // phpcs:ignore ?> روش خوشه‌بندی</h2>
		<div class="mzm-choice">
			<?php
			$modes = array(
				'auto'    => array( 'خودکار', 'اگر سرویس نتایج گوگل وصل باشد حالت اقتصادی، وگرنه رایگان.' ),
				'free'    => array( 'رایگان', 'زبانی + هوش مصنوعی، بدون هزینه نتایج گوگل.' ),
				'economy' => array( 'اقتصادی (پیشنهادی)', 'فقط سر هر گروه و کلمات مرزی و پرحجم با گوگل بررسی می‌شوند (حدود ۲۵ تا ۳۵٪ کلمات).' ),
				'full'    => array( 'کامل', 'همه کلمات با حجم ۱۰ به بالا با ۱۰ نتیجه اول گوگل بررسی می‌شوند.' ),
			);
			foreach ( $modes as $k => $m ) :
				?>
				<label><input type="radio" name="serp_mode" value="<?php echo esc_attr( $k ); ?>" <?php checked( $cfg['serp_mode'], $k ); ?>><span><b><?php echo esc_html( $m[0] ); ?></b><small><?php echo esc_html( $m[1] ); ?></small></span></label>
			<?php endforeach; ?>
		</div>
		<div class="mzm-grid-3">
			<label>سخت‌گیری ادغام <small>چند URL مشترک در ۱۰ نتیجه اول تا دو کلمه یک صفحه شوند</small>
				<select name="strictness"><?php mzm_options( array( 3 => '۳ — منعطف (صفحات کمتر و جامع‌تر)', 4 => '۴ — متعادل (پیشنهادی)', 5 => '۵ — سخت‌گیر (لندینگ‌های بیشتر)' ), (int) $cfg['strictness'] ); ?></select>
			</label>
			<label>سقف درخواست نتایج گوگل در هر اجرا <small>۰ = سقف تنظیمات کلی (<?php echo esc_html( mzm_num( mzm_setting( 'serp_max_project', 2000 ) ) ); ?>)</small><input type="number" name="serp_cap" min="0" value="<?php echo esc_attr( (int) $cfg['serp_cap'] ); ?>"></label>
			<label>حداقل حجم جستجو <small>کلمات کمتر حذف می‌شوند</small><input type="number" name="min_volume" min="0" value="<?php echo esc_attr( (int) $cfg['min_volume'] ); ?>"></label>
		</div>
	</div>

	<div class="mzm-panel">
		<h2><?php echo mzm_icon( 'coins', 18 ); // phpcs:ignore ?> فروش و بازگشت سرمایه</h2>
		<div class="mzm-grid-3">
			<label>نرخ تبدیل صفحات فروش (٪)<input type="text" name="cr" dir="ltr" value="<?php echo esc_attr( $cfg['cr'] ); ?>"></label>
			<label>میانگین سبد خرید (تومان) <small>۰ = از قیمت محصولات سایت</small><input type="text" name="aov" dir="ltr" value="<?php echo esc_attr( (int) $cfg['aov'] ); ?>"></label>
			<label>اتصال به محصولات و صفحات سایت
				<select name="store_mode" data-mzm-toggle="woo">
					<?php mzm_options( array( 'auto' => 'خودکار (پیشنهادی)', 'local' => 'همین وردپرس (ووکامرس)', 'wp_rest' => 'API عمومی ووکامرس/وردپرس سایت', 'woo_rest' => 'API ووکامرس با کلید', 'sitemap' => 'فقط سایت‌مپ', 'none' => 'بدون اتصال' ), $cfg['store_mode'] ); ?>
				</select>
			</label>
		</div>
		<div class="mzm-grid-2" data-mzm-when="woo">
			<label>Consumer Key ووکامرس <small>اختیاری؛ فقط برای فروشگاه‌هایی که API عمومی را بسته‌اند</small><input type="text" name="woo_key" dir="ltr" value="<?php echo esc_attr( $cfg['woo_key'] ); ?>"></label>
			<label>Consumer Secret<input type="password" name="woo_secret" dir="ltr" value="<?php echo esc_attr( mzm_mask( $cfg['woo_secret'] ) ); ?>" autocomplete="off"></label>
		</div>
		<p class="mzm-note"><?php echo mzm_icon( 'help', 16 ); // phpcs:ignore ?><span>اگر به سایت دسترسی نباشد این بخش بی‌صدا کنار گذاشته می‌شود و بقیه تحلیل کامل انجام می‌شود؛ فقط نگاشت به صفحات موجود و تخمین فروش از روی قیمت محصولات حذف می‌شود.</span></p>
	</div>

	<div class="mzm-panel">
		<h2><?php echo mzm_icon( 'calendar', 18 ); // phpcs:ignore ?> تقویم محتوا</h2>
		<div class="mzm-grid-4">
			<label>تعداد ماه<input type="number" name="months" min="1" max="12" value="<?php echo esc_attr( (int) $cfg['months'] ); ?>"></label>
			<label>مقاله در ماه<input type="number" name="per_month" min="1" max="40" value="<?php echo esc_attr( (int) $cfg['per_month'] ); ?>"></label>
			<label>تاریخ شروع <small>شمسی، مثلاً ۱۴۰۵/۰۸/۱۰ (خالی = شنبه آینده)</small><input type="text" name="start_date" value="<?php echo esc_attr( $start ); ?>" placeholder="۱۴۰۵/۰۸/۱۰"></label>
			<label>تحلیل شکاف برای <small>تعداد کلاستر برتر</small><input type="number" name="gap_limit" min="0" max="200" value="<?php echo esc_attr( (int) $cfg['gap_limit'] ); ?>"></label>
		</div>
		<label>روزهای انتشار</label>
		<div class="mzm-inline" style="margin:-6px 0 12px">
			<?php foreach ( $days as $n => $label ) : ?>
				<label class="mzm-check" style="margin:0 0 0 10px"><input type="checkbox" name="publish_days[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, (array) $cfg['publish_days'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="mzm-form-foot">
		<button class="mzm-btn is-primary" type="submit"><?php echo mzm_icon( 'check', 16 ); // phpcs:ignore ?> <?php echo $is_new ? 'ساخت پروژه' : 'ذخیره تنظیمات'; ?></button>
	</div>
</form>
<?php if ( ! $is_new ) : ?>
	<details class="mzm-panel" style="margin-top:24px">
		<summary class="mzm-btn is-danger is-ghost"><?php echo mzm_icon( 'trash', 16 ); // phpcs:ignore ?> حذف پروژه</summary>
		<form method="post" onsubmit="return confirm('پروژه و همه نتایجش حذف شود؟');">
			<?php mzm_form_fields( 'delete_project', array( 'project_id' => $project['id'] ) ); ?>
			<p class="mzm-muted">همه کلمات، کلاسترها و تقویم این پروژه حذف می‌شود (گوگل شیت دست نمی‌خورد).</p>
			<button class="mzm-btn is-danger" type="submit">حذف قطعی</button>
		</form>
	</details>
<?php endif; ?>
