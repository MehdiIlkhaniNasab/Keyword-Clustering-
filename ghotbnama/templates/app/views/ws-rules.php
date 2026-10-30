<?php
/**
 * Project rules (from bulk decisions and reviewer comments).
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $ws
 */

defined( 'ABSPATH' ) || exit;

$kinds = array( 'remove' => 'حذف', 'keep' => 'نگه‌داشتن', 'park' => 'پارکینگ', 'page' => 'نوع صفحه', 'note' => 'دستور به هوش مصنوعی' );
?>
<div class="qtb-panel" data-qtb-rules="<?php echo esc_attr( $project['id'] ); ?>">
	<h2><?php echo qtb_icon( 'shield', 18 ); // phpcs:ignore ?> قوانین پروژه <small><?php echo esc_html( qtb_num( count( $ws['rules'] ) ) ); ?> قانون؛ در همه تصمیم‌های هوش مصنوعی رعایت می‌شوند</small></h2>
	<ul class="qtb-rules">
		<?php foreach ( array_reverse( $ws['rules'] ) as $r ) : ?>
			<li><span class="qtb-chip"><?php echo esc_html( $kinds[ $r['kind'] ] ?? $r['kind'] ); ?></span> <?php echo esc_html( $r['text'] ); ?> <small class="qtb-muted"><?php echo esc_html( $r['by'] ); ?></small>
				<button type="button" class="qtb-icon-btn is-del" data-qtb-ruledel="<?php echo esc_attr( $r['id'] ); ?>" title="حذف قانون"><?php echo qtb_icon( 'x', 13 ); // phpcs:ignore ?></button></li>
		<?php endforeach; ?>
		<?php if ( ! $ws['rules'] ) : ?><li class="qtb-muted">هنوز قانونی نیست.</li><?php endif; ?>
	</ul>
	<form class="qtb-inline" data-qtb-ruleform>
		<select name="kind" style="width:auto"><?php qtb_options( $kinds, 'remove' ); ?></select>
		<input type="text" name="match" placeholder="کلمه یا عبارت" style="flex:1 1 120px">
		<select name="page" style="width:auto"><option value="">—</option><?php qtb_options( qtb_page_options(), '' ); ?></select>
		<input type="text" name="text" placeholder="توضیح (اختیاری)" style="flex:2 1 160px">
		<button class="qtb-btn is-small" type="submit">افزودن</button>
	</form>
</div>
