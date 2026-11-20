<?php
/**
 * Step-by-step Serper connection: account → API key → test (server and browser) →
 * optional Cloudflare Worker relay (account → worker → code → address) → done.
 * Each step opens only after the previous one is confirmed.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

$st    = qtb_serp_setup_state();
$route = array( 'direct' => 'مستقیم از سرور', 'worker' => 'Worker کلادفلر', 'browser' => 'مرورگر شما' );
$steps = array(
	'account'    => array( 'حساب Serper', 'ساخت حساب رایگان' ),
	'key'        => array( 'کلید API', 'کپی کلید از داشبورد Serper' ),
	'test'       => array( 'تست اتصال', 'از سرور و از مرورگر' ),
	'cf_account' => array( 'حساب کلادفلر', 'فقط اگر لازم شد' ),
	'cf_worker'  => array( 'ساخت Worker', 'یک برنامه کوچک رایگان' ),
	'cf_code'    => array( 'جای‌گذاری کد', 'کد آماده قطب‌نما' ),
	'cf_url'     => array( 'آدرس Worker', 'ذخیره و تست' ),
);
?>
<section class="qtb-wz" data-qtb-wz data-state="<?php echo esc_attr( wp_json_encode( $st, JSON_UNESCAPED_UNICODE ) ); ?>">
	<header class="qtb-wz-head">
		<div>
			<h3>اتصال Serper، قدم‌به‌قدم</h3>
			<p>هر قدم را انجام دهید و تأیید کنید تا قدم بعد باز شود. سه مسیر امتحان می‌شود و هر کدام کار کرد همان استفاده می‌شود: مستقیم از سرور، از مرورگر خودتان، یا از طریق Worker رایگان کلادفلر.</p>
		</div>
		<div class="qtb-wz-status" data-qtb-wz-status>
			<span class="qtb-wz-route"><i></i><b data-qtb-wz-route><?php echo esc_html( $st['has_key'] && in_array( 'test', $st['done'], true ) ? 'وصل از مسیر: ' . ( $route[ $st['route'] ] ?? '' ) : 'هنوز وصل نیست' ); ?></b></span>
			<button type="button" class="qtb-btn is-small is-ghost" data-qtb-wz-reset>شروع دوباره راهنما</button>
		</div>
	</header>

	<ol class="qtb-wz-rail">
		<?php
		$n = 0;
		foreach ( $steps as $id => $s ) :
			++$n;
			$cf = 0 === strpos( $id, 'cf_' );
			?>
			<li class="qtb-wz-step<?php echo $cf ? ' is-cf' : ''; ?>" data-step="<?php echo esc_attr( $id ); ?>">
				<div class="qtb-wz-mark" aria-hidden="true"><span><?php echo esc_html( qtb_fa( $n ) ); ?></span><?php echo qtb_icon( 'check', 14 ); // phpcs:ignore ?></div>
				<div class="qtb-wz-card">
					<button type="button" class="qtb-wz-title" data-qtb-wz-open="<?php echo esc_attr( $id ); ?>">
						<b><?php echo esc_html( $s[0] ); ?></b><small><?php echo esc_html( $s[1] ); ?></small>
						<em class="qtb-wz-lock"><?php echo qtb_icon( 'shield', 13 ); // phpcs:ignore ?> بعد از قدم قبل</em>
					</button>
					<div class="qtb-wz-body">
						<?php if ( 'account' === $id ) : ?>
							<ol class="qtb-wz-todo">
								<li>سایت Serper را باز کنید: <a href="https://serper.dev/signup" target="_blank" rel="noopener" dir="ltr">serper.dev/signup</a></li>
								<li>با حساب گوگل (Sign up with Google) یا ایمیل ثبت‌نام کنید. اگر ایمیل داده‌اید، لینک تأیید را از صندوق ایمیل بزنید.</li>
								<li>بعد از ورود، داشبورد Serper باز می‌شود. حساب تازه ۲٬۵۰۰ جستجوی رایگان دارد؛ یک پروژه با موتور ساختار قطب‌نما معمولاً ۳۰۰ تا ۹۰۰ جستجو مصرف می‌کند.</li>
							</ol>
							<p class="qtb-wz-tip">اگر سایت Serper باز نشد، فیلترشکن را روشن کنید. فقط برای ثبت‌نام لازم است.</p>
							<div class="qtb-wz-acts"><button type="button" class="qtb-btn is-primary" data-qtb-wz-done="account" data-next="key">حساب را ساختم</button></div>
						<?php elseif ( 'key' === $id ) : ?>
							<ol class="qtb-wz-todo">
								<li>در داشبورد Serper، از منوی کنار صفحه «API Key» را بزنید.</li>
								<li>کنار کلید، دکمه کپی را بزنید. کلید حدود ۴۰ حرف و عدد انگلیسی است.</li>
								<li>کلید را در کادر زیر جای‌گذاری کنید و «ذخیره کلید» را بزنید.</li>
							</ol>
							<div class="qtb-wz-field">
								<input type="password" dir="ltr" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $st['has_key'] ? $st['key_mask'] : 'کلید API را اینجا جای‌گذاری کنید' ); ?>" data-qtb-wz-key>
								<button type="button" class="qtb-btn is-primary" data-qtb-wz-savekey>ذخیره کلید</button>
							</div>
							<?php if ( $st['has_key'] ) : ?><p class="qtb-wz-tip">یک کلید ذخیره شده است (<?php echo esc_html( 'sokan' === $st['key_from'] ? 'از سکان' : 'قطب‌نما' ); ?>). اگر همان را می‌خواهید، <button type="button" class="qtb-linkbtn" data-qtb-wz-done="key" data-next="test">با همین کلید ادامه دهید</button>.</p><?php endif; ?>
						<?php elseif ( 'test' === $id ) : ?>
							<p>قطب‌نما یک جستجوی نمونه را از دو مسیر امتحان می‌کند:</p>
							<ul class="qtb-wz-tests">
								<li data-qtb-wz-test="direct"><i></i><div><b>مستقیم از سرور سایت</b><small data-msg>هنوز تست نشده</small></div><button type="button" class="qtb-btn is-small" data-qtb-wz-use="direct" hidden>استفاده از این مسیر</button></li>
								<li data-qtb-wz-test="browser"><i></i><div><b>از مرورگر شما</b><small data-msg>هنوز تست نشده</small></div><button type="button" class="qtb-btn is-small" data-qtb-wz-use="browser" hidden>استفاده از این مسیر</button></li>
							</ul>
							<div class="qtb-wz-acts"><button type="button" class="qtb-btn is-primary" data-qtb-wz-test-go>تست هر دو مسیر</button></div>
							<p class="qtb-wz-tip" data-qtb-wz-advice hidden></p>
							<p class="qtb-wz-tip">مسیر مرورگر یعنی هنگام پردازش موضوع‌ها این صفحه باید باز بماند. اگر مسیر مستقیم کار نکرد و نمی‌خواهید صفحه باز بماند، <button type="button" class="qtb-linkbtn" data-qtb-wz-done="test" data-next="cf_account">Worker کلادفلر بسازید</button>.</p>
						<?php elseif ( 'cf_account' === $id ) : ?>
							<ol class="qtb-wz-todo">
								<li>صفحه ثبت‌نام کلادفلر را باز کنید: <a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener" dir="ltr">dash.cloudflare.com/sign-up</a></li>
								<li>ایمیل و یک رمز عبور بنویسید و «Sign up» را بزنید. دامنه لازم نیست و هزینه‌ای ندارد.</li>
								<li>لینک تأیید را از ایمیل بزنید. اگر پرسید «چه کاری می‌خواهید انجام دهید»، از آن رد شوید (Skip).</li>
							</ol>
							<p class="qtb-wz-tip">ممکن است کلادفلر از ایران فقط با فیلترشکن باز شود. فقط برای همین چند قدم لازم است؛ بعدش سرور شما خودش وصل می‌شود.</p>
							<div class="qtb-wz-acts"><button type="button" class="qtb-btn is-primary" data-qtb-wz-done="cf_account" data-next="cf_worker">حساب کلادفلر را ساختم</button></div>
						<?php elseif ( 'cf_worker' === $id ) : ?>
							<ol class="qtb-wz-todo">
								<li>در داشبورد کلادفلر، از منوی کنار صفحه «Workers & Pages» را باز کنید (معمولاً زیر «Compute»).</li>
								<li>دکمه «Create» و بعد «Create Worker» یا «Start with Hello World» را بزنید.</li>
								<li>در کادر نام بنویسید: <code dir="ltr" data-qtb-copy title="کپی">ghotbnama-serp</code> و «Deploy» را بزنید.</li>
							</ol>
							<div class="qtb-wz-acts"><button type="button" class="qtb-btn is-primary" data-qtb-wz-done="cf_worker" data-next="cf_code">Worker را ساختم</button></div>
						<?php elseif ( 'cf_code' === $id ) : ?>
							<ol class="qtb-wz-todo">
								<li>در صفحه Worker دکمه «Edit code» را بزنید.</li>
								<li>همه کد موجود در ویرایشگر را انتخاب و پاک کنید (Ctrl+A و بعد Delete).</li>
								<li>کد زیر را با دکمه «کپی کد» بردارید، در ویرایشگر جای‌گذاری کنید و «Deploy» را بزنید.</li>
							</ol>
							<div class="qtb-wz-code">
								<div class="qtb-wz-code-h"><span>کد Worker (رمز مخصوص سایت شما داخل آن است؛ آن را جایی منتشر نکنید)</span><button type="button" class="qtb-btn is-small" data-qtb-wz-copycode>کپی کد</button></div>
								<pre dir="ltr" data-qtb-wz-code>…</pre>
							</div>
							<div class="qtb-wz-acts"><button type="button" class="qtb-btn is-primary" data-qtb-wz-done="cf_code" data-next="cf_url">کد را جای‌گذاری و Deploy کردم</button></div>
						<?php elseif ( 'cf_url' === $id ) : ?>
							<ol class="qtb-wz-todo">
								<li>در صفحه Worker، آدرسی که با <code dir="ltr">workers.dev</code> تمام می‌شود را پیدا و کپی کنید. مثل: <code dir="ltr">https://ghotbnama-serp.name.workers.dev</code></li>
								<li>آدرس را اینجا جای‌گذاری کنید و «ذخیره و تست» را بزنید. اگر تست موفق شد، این مسیر خودکار انتخاب می‌شود.</li>
							</ol>
							<div class="qtb-wz-field">
								<input type="url" dir="ltr" placeholder="https://ghotbnama-serp.….workers.dev" value="<?php echo esc_attr( $st['worker_url'] ); ?>" data-qtb-wz-wurl>
								<button type="button" class="qtb-btn is-primary" data-qtb-wz-savew>ذخیره و تست</button>
							</div>
							<p class="qtb-wz-tip" data-qtb-wz-wmsg hidden></p>
						<?php endif; ?>
					</div>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>

	<footer class="qtb-wz-done" data-qtb-wz-final hidden>
		<div><?php echo qtb_icon( 'check', 18 ); // phpcs:ignore ?><b>Serper وصل است.</b> <span>مسیر فعلی:</span></div>
		<div class="qtb-seg" data-qtb-wz-routes>
			<?php foreach ( $route as $k => $l ) : ?>
				<button type="button" data-qtb-wz-use="<?php echo esc_attr( $k ); ?>"<?php echo $k === $st['route'] ? ' class="is-active"' : ''; ?>><?php echo esc_html( $l ); ?></button>
			<?php endforeach; ?>
		</div>
		<button type="button" class="qtb-btn is-small" data-qtb-wz-retest>تست دوباره</button>
	</footer>
</section>
