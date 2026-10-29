<?php
/**
 * Global settings: AI, SERP, Google Sheets, network, defaults.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

$s      = mzm_settings();
$sokan  = mzm_sokan_settings();
$ai     = mzm_ai_conf();
$serp   = mzm_serp_conf();
$google = mzm_google_conf();
$usage  = mzm_ai_usage();
$sk_ai  = $sokan && ( '' !== (string) ( $sokan['ai_gateway_key'] ?? '' ) || '' !== (string) ( $sokan['anthropic_key'] ?? '' ) );
$sk_sp  = $sokan && '' !== (string) ( $sokan['serp_key'] ?? '' );
$sk_g   = $sokan && ( '' !== (string) ( $sokan['google_refresh_token'] ?? '' ) || '' !== (string) ( $sokan['google_service_account'] ?? '' ) );
?>
<div class="mzm-head">
	<div>
		<p class="mzm-eyebrow"><?php echo mzm_icon( 'settings', 14 ); // phpcs:ignore ?> تنظیمات</p>
		<h1>اتصال‌ها و تنظیمات</h1>
		<p class="mzm-muted"><?php echo mzm_sokan_present() ? 'سکان روی همین سایت پیدا شد؛ منظومه می‌تواند از همان هوش مصنوعی، کلید نتایج گوگل و اتصال گوگل سکان استفاده کند.' : 'سکان روی این سایت پیدا نشد؛ اتصال‌ها را اینجا وارد کنید.'; ?></p>
	</div>
</div>
<form method="post">
	<?php mzm_form_fields( 'save_settings' ); ?>

	<div class="mzm-panel">
		<div class="mzm-panel-head"><h2><?php echo mzm_icon( 'bot', 18 ); // phpcs:ignore ?> هوش مصنوعی</h2><button type="button" class="mzm-btn is-small" data-mzm-test="ai">تست اتصال</button></div>
		<div class="mzm-choice">
			<label><input type="radio" name="ai_source" value="sokan" <?php checked( $s['ai_source'], 'sokan' ); ?> data-mzm-toggle="ai"><span><b>همان مدل سکان</b><small><?php echo $sk_ai ? esc_html( sprintf( '%s · مدل تحلیل %s، مدل سبک %s', 'anthropic' === ( $sokan['ai_provider'] ?? '' ) ? 'Anthropic' : wp_parse_url( (string) ( $sokan['ai_base_url'] ?? '' ), PHP_URL_HOST ), $sokan['ai_model'] ?? '', ( $sokan['ai_model_light'] ?? '' ) ?: ( $sokan['ai_model'] ?? '' ) ) ) : 'سکان کلید هوش مصنوعی ندارد'; ?></small></span></label>
			<label><input type="radio" name="ai_source" value="own" <?php checked( $s['ai_source'], 'own' ); ?> data-mzm-toggle="ai"><span><b>تنظیم جداگانه</b><small>کلید و مدل مخصوص منظومه</small></span></label>
		</div>
		<div data-mzm-when="ai-own">
			<div class="mzm-grid-3">
				<label>سرویس<select name="ai_provider"><?php mzm_options( array( 'gateway' => 'GapGPT / سازگار با OpenAI', 'anthropic' => 'Anthropic مستقیم' ), $s['ai_provider'] ); ?></select></label>
				<label class="mzm-span-2">آدرس API (سازگار با OpenAI)<input type="url" name="ai_base_url" dir="ltr" value="<?php echo esc_attr( $s['ai_base_url'] ); ?>"></label>
			</div>
			<div class="mzm-grid-2">
				<label>کلید API (GapGPT / OpenAI)<input type="password" name="ai_gateway_key" dir="ltr" autocomplete="off" value="<?php echo esc_attr( mzm_mask( $s['ai_gateway_key'] ) ); ?>"></label>
				<label>کلید Anthropic<input type="password" name="anthropic_key" dir="ltr" autocomplete="off" value="<?php echo esc_attr( mzm_mask( $s['anthropic_key'] ) ); ?>"></label>
				<label>مدل تحلیل (ساختار، شکاف محتوایی)<input type="text" name="ai_model" dir="ltr" list="mzm-models" value="<?php echo esc_attr( $s['ai_model'] ); ?>"></label>
				<label>مدل سبک (فیلتر کلمات، بریف‌ها)<input type="text" name="ai_model_light" dir="ltr" list="mzm-models" value="<?php echo esc_attr( $s['ai_model_light'] ); ?>"></label>
			</div>
			<datalist id="mzm-models"><?php foreach ( mzm_ai_models() as $m => $l ) : ?><option value="<?php echo esc_attr( $m ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></datalist>
		</div>
		<div class="mzm-grid-3">
			<label>سقف بودجه ماهانه منظومه (دلار)<input type="number" step="0.5" min="0" name="ai_budget" value="<?php echo esc_attr( $s['ai_budget'] ); ?>"></label>
			<div class="mzm-span-2 mzm-small mzm-muted" style="align-self:center">مصرف این ماه: <?php echo esc_html( mzm_num( $usage['cost'], 3 ) ); ?> دلار از <?php echo esc_html( mzm_num( $usage['budget'], 2 ) ); ?> · <?php echo esc_html( mzm_num( $usage['requests'] ) ); ?> درخواست. هر پروژه ۵۰۰۰ کلمه‌ای با مدل‌های پیش‌فرض حدود ۰٫۱ تا ۰٫۴ دلار.</div>
		</div>
		<pre class="mzm-small" data-mzm-test-out="ai" hidden style="white-space:pre-wrap;background:var(--soft);padding:10px;border-radius:10px"></pre>
	</div>

	<div class="mzm-panel">
		<div class="mzm-panel-head"><h2><?php echo mzm_icon( 'research', 18 ); // phpcs:ignore ?> نتایج گوگل (SERP)</h2><button type="button" class="mzm-btn is-small" data-mzm-test="serp">تست اتصال</button></div>
		<div class="mzm-choice">
			<label><input type="radio" name="serp_source" value="sokan" <?php checked( $s['serp_source'], 'sokan' ); ?> data-mzm-toggle="serp"><span><b>کلید سکان</b><small><?php echo $sk_sp ? esc_html( ( $sokan['serp_provider'] ?? 'serper' ) . ' · ' . mzm_mask( $sokan['serp_key'] ) ) : 'سکان کلید نتایج گوگل ندارد'; ?></small></span></label>
			<label><input type="radio" name="serp_source" value="own" <?php checked( $s['serp_source'], 'own' ); ?> data-mzm-toggle="serp"><span><b>کلید جداگانه</b><small>Serper، DataForSEO یا SerpApi</small></span></label>
			<label><input type="radio" name="serp_source" value="off" <?php checked( $s['serp_source'], 'off' ); ?> data-mzm-toggle="serp"><span><b>رایگان (بدون گوگل)</b><small>خوشه‌بندی زبانی + هوش مصنوعی</small></span></label>
		</div>
		<div data-mzm-when="serp-own">
			<div class="mzm-grid-3">
				<label>سرویس<select name="serp_provider"><?php mzm_options( array( 'serper' => 'Serper.dev (۲۵۰۰ درخواست رایگان، بعد حدود ۱ دلار برای ۱۰۰۰)', 'dataforseo' => 'DataForSEO (حدود ۲ دلار برای ۱۰۰۰، Live)', 'serpapi' => 'SerpApi' ), $s['serp_provider'] ); ?></select></label>
				<label class="mzm-span-2">کلید API (Serper / SerpApi)<input type="password" name="serp_key" dir="ltr" autocomplete="off" value="<?php echo esc_attr( mzm_mask( $s['serp_key'] ) ); ?>"></label>
				<label>DataForSEO Login<input type="text" name="dfs_login" dir="ltr" value="<?php echo esc_attr( $s['dfs_login'] ); ?>"></label>
				<label>DataForSEO Password<input type="password" name="dfs_password" dir="ltr" autocomplete="off" value="<?php echo esc_attr( mzm_mask( $s['dfs_password'] ) ); ?>"></label>
			</div>
		</div>
		<div class="mzm-grid-4">
			<label>کشور (gl)<input type="text" name="serp_gl" dir="ltr" value="<?php echo esc_attr( $s['serp_gl'] ); ?>"></label>
			<label>زبان (hl)<input type="text" name="serp_hl" dir="ltr" value="<?php echo esc_attr( $s['serp_hl'] ); ?>"></label>
			<label>نگهداری کش (روز)<input type="number" name="serp_cache_days" min="1" max="180" value="<?php echo esc_attr( (int) $s['serp_cache_days'] ); ?>"></label>
			<label>سقف درخواست در هر اجرا<input type="number" name="serp_max_project" min="0" value="<?php echo esc_attr( (int) $s['serp_max_project'] ); ?>"></label>
		</div>
		<p class="mzm-note"><?php echo mzm_icon( 'coins', 16 ); // phpcs:ignore ?><span>کش نتایج گوگل بین همه پروژه‌ها مشترک است (الان <?php echo esc_html( mzm_num( mzm_serp_cache_count() ) ); ?> نتیجه). در حالت «اقتصادی» فقط حدود ۲۵ تا ۳۵ درصد کلمات بررسی می‌شوند؛ هر پروژه ۵۰۰۰ کلمه‌ای حدود ۱۲۰۰ تا ۱۸۰۰ درخواست.</span></p>
		<pre class="mzm-small" data-mzm-test-out="serp" hidden style="white-space:pre-wrap;background:var(--soft);padding:10px;border-radius:10px"></pre>
	</div>

	<div class="mzm-panel">
		<div class="mzm-panel-head"><h2><?php echo mzm_icon( 'sheet', 18 ); // phpcs:ignore ?> گوگل شیت</h2><button type="button" class="mzm-btn is-small" data-mzm-test="google">تست اتصال</button></div>
		<div class="mzm-choice">
			<label><input type="radio" name="google_source" value="sokan" <?php checked( $s['google_source'], 'sokan' ); ?> data-mzm-toggle="google"><span><b>اتصال گوگل سکان</b><small><?php echo $sk_g ? esc_html( 'service' === ( $sokan['google_auth_mode'] ?? '' ) ? 'سرویس اکانت سکان' : 'حساب گوگل ' . ( $sokan['google_account_email'] ?? '' ) ) : 'سکان به گوگل متصل نیست'; ?></small></span></label>
			<label><input type="radio" name="google_source" value="own" <?php checked( $s['google_source'], 'own' ); ?> data-mzm-toggle="google"><span><b>سرویس اکانت منظومه</b><small>روش اصلی پیشنهادی</small></span></label>
		</div>
		<div data-mzm-when="google-own">
			<label>JSON سرویس اکانت <small>Google Cloud Console › IAM › Service Accounts › Keys › Add key › JSON. «Google Sheets API» را در همان پروژه Enable کنید.</small>
				<textarea name="google_service_account" rows="4" dir="ltr" placeholder='{"type": "service_account", …}'><?php echo '' !== $s['google_service_account'] ? esc_textarea( mzm_mask( $s['google_service_account'] ) ) : ''; ?></textarea>
			</label>
		</div>
		<?php if ( mzm_google_service_email() ) : ?>
			<div class="mzm-kv"><div><span>ایمیل برای اشتراک شیت‌ها (Editor)</span><code data-mzm-copy><?php echo esc_html( mzm_google_service_email() ); ?></code></div></div>
		<?php endif; ?>
		<pre class="mzm-small" data-mzm-test-out="google" hidden style="white-space:pre-wrap;background:var(--soft);padding:10px;border-radius:10px"></pre>
	</div>

	<div class="mzm-grid-2" style="gap:16px">
		<div class="mzm-panel">
			<h2><?php echo mzm_icon( 'globe', 18 ); // phpcs:ignore ?> شبکه و پروکسی</h2>
			<label>مسیر درخواست‌ها <small><?php echo mzm_proxy_defined() ? 'پروکسی وردپرس: ' . esc_html( WP_PROXY_HOST ) : 'پروکسی در wp-config تعریف نشده (WP_PROXY_HOST)'; ?></small>
				<select name="proxy_mode"><?php mzm_options( array( 'smart' => 'هوشمند: فقط گوگل، سرویس‌های SERP و AI خارجی از پروکسی', 'all' => 'همه درخواست‌ها از پروکسی', 'none' => 'بدون پروکسی' ), $s['proxy_mode'] ); ?></select>
			</label>
		</div>
		<div class="mzm-panel">
			<h2><?php echo mzm_icon( 'calendar', 18 ); // phpcs:ignore ?> پیش‌فرض پروژه‌های جدید</h2>
			<div class="mzm-grid-2">
				<label>ماه‌های تقویم<input type="number" name="def_months" min="1" max="12" value="<?php echo esc_attr( (int) $s['def_months'] ); ?>"></label>
				<label>مقاله در ماه<input type="number" name="def_per_month" min="1" max="40" value="<?php echo esc_attr( (int) $s['def_per_month'] ); ?>"></label>
				<label>نرخ تبدیل (٪)<input type="number" step="0.1" name="def_cr" value="<?php echo esc_attr( $s['def_cr'] ); ?>"></label>
				<label>میانگین سبد (تومان)<input type="number" name="def_aov" value="<?php echo esc_attr( (int) $s['def_aov'] ); ?>"></label>
			</div>
		</div>
	</div>
	<div class="mzm-form-foot"><button class="mzm-btn is-primary" type="submit"><?php echo mzm_icon( 'check', 16 ); // phpcs:ignore ?> ذخیره تنظیمات</button></div>
</form>
