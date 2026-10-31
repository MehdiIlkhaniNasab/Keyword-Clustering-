<?php
/**
 * Stop / resume / skip / cancel buttons of a running job.
 *
 * @package Ghotbnama
 * @var array|null $st Job status.
 */

defined( 'ABSPATH' ) || exit;

$live   = $st && ! $st['done'] && ! $st['error'];
$paused = $live && ! empty( $st['paused'] );
?>
<div class="qtb-jobctl" data-qtb-ctl <?php echo $live ? '' : 'hidden'; ?>>
	<p class="qtb-jobctl-msg" data-qtb-ctl-msg <?php echo $paused ? '' : 'hidden'; ?>><?php echo esc_html( $paused ? $st['paused']['msg'] : '' ); ?></p>
	<button type="button" class="qtb-btn is-small" data-act="pause" <?php echo $paused ? 'hidden' : ''; ?>><?php echo qtb_icon( 'clock', 14 ); // phpcs:ignore ?> توقف</button>
	<button type="button" class="qtb-btn is-small is-gold" data-act="resume" <?php echo $paused ? '' : 'hidden'; ?>><?php echo qtb_icon( 'play', 14 ); // phpcs:ignore ?> ادامه همین مرحله</button>
	<button type="button" class="qtb-btn is-small" data-act="skip" <?php echo $paused && 'serp' === $st['paused']['why'] ? '' : 'hidden'; ?> title="کلماتی که نتیجه گوگل نگرفتند فقط با معنا و هوش مصنوعی خوشه‌بندی می‌شوند">ادامه بدون نتایج گوگل</button>
	<button type="button" class="qtb-btn is-small is-ghost" data-act="cancel" style="color:#F3C9C7!important"><?php echo qtb_icon( 'x', 14 ); // phpcs:ignore ?> لغو</button>
</div>
