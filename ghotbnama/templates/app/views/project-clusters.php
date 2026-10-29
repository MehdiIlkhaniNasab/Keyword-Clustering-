<?php
/**
 * Pillars → sections → cluster cards, with manual editing.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$kws   = $R['keywords'];
$by_id = $R['by_id'];
$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore
$pi    = isset( $_GET['pl'] ) ? absint( $_GET['pl'] ) : 0; // phpcs:ignore
$pillar = $R['pillars'][ $pi ] ?? ( $R['pillars'][0] ?? null );
// Search across all pillars.
$found = array();
if ( '' !== $q ) {
	$nq = qtb_key( $q );
	foreach ( $R['clusters'] as $c ) {
		foreach ( $c['kws'] as $m ) {
			if ( false !== mb_strpos( $kws[ $m ]['key'] ?? qtb_key( $kws[ $m ]['k'] ), $nq ) ) {
				$found[] = $c['id'];
				break;
			}
		}
	}
}
$all = array();
foreach ( $R['clusters'] as $c ) {
	$all[] = array( $c['id'], $c['name'], $R['pillars'][ $c['pillar'] ]['name'] ?? '' );
}
?>
<div class="qtb-toolbar">
	<form class="qtb-filters" method="get" action="<?php echo esc_url( qtb_project_url( $project['id'], 'clusters' ) ); ?>">
		<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
			<input type="hidden" name="qtb_app" value="1"><input type="hidden" name="qtb_route" value="<?php echo esc_attr( 'project/' . $project['id'] . '/clusters' ); ?>">
		<?php endif; ?>
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجوی کلمه در همه کلاسترها…" style="min-width:260px">
		<button class="qtb-btn is-small" type="submit"><?php echo qtb_icon( 'research', 15 ); // phpcs:ignore ?> جستجو</button>
	</form>
	<span class="qtb-muted qtb-small">برای جابه‌جایی کلمه، روی آن بروید و آیکون فلش را بزنید؛ برای ادغام، کلاسترها را تیک بزنید.</span>
</div>

<div class="qtb-pillar-nav">
	<?php foreach ( $R['pillars'] as $p ) : ?>
		<a class="<?php echo ( '' === $q && $pillar && $p['id'] === $pillar['id'] ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( qtb_project_url( $project['id'], 'clusters', array( 'pl' => $p['id'] ) ) ); ?>"><?php echo esc_html( $p['name'] ); ?><small><?php echo esc_html( qtb_num( $p['vol'] ) ); ?></small></a>
	<?php endforeach; ?>
</div>

<div data-qtb-editor="<?php echo esc_attr( $project['id'] ); ?>" data-clusters="<?php echo esc_attr( wp_json_encode( $all, JSON_UNESCAPED_UNICODE ) ); ?>">
<?php
$card = function ( $c ) use ( $kws, $R ) {
	$sv = $c['serp'];
	?>
	<div class="qtb-cl" data-cl="<?php echo esc_attr( $c['id'] ); ?>">
		<div class="qtb-cl-h is-<?php echo esc_attr( $c['page'] ); ?>">
			<div style="min-width:0">
				<h4><?php echo esc_html( $c['name'] ); ?></h4>
				<small><?php echo esc_html( qtb_intents()[ $c['intent'] ] ?? '' ); ?> · <?php echo esc_html( $R['pillars'][ $c['pillar'] ]['name'] ?? '' ); ?></small>
			</div>
			<div class="qtb-inline" style="flex-wrap:nowrap">
				<button type="button" class="qtb-icon-btn" data-qtb-rename="<?php echo esc_attr( $c['id'] ); ?>" title="تغییر نام"><?php echo qtb_icon( 'edit', 15 ); // phpcs:ignore ?></button>
				<input type="checkbox" data-qtb-pick="<?php echo esc_attr( $c['id'] ); ?>" title="انتخاب برای ادغام">
			</div>
		</div>
		<div class="qtb-cl-meta">
			<?php echo qtb_chip_prio( $c['priority'] ); // phpcs:ignore ?>
			<?php echo qtb_chip_cov( $c['coverage'] ); // phpcs:ignore ?>
			<?php echo ! empty( $c['quick_win'] ) ? '<span class="qtb-chip is-quick">برد سریع</span>' : ''; ?>
			<?php echo $sv ? '<span class="qtb-chip is-serp" title="سختی ' . esc_attr( qtb_num( $c['diff'] ) ) . '">گوگل ✓' . ( $sv['own_pos'] ? ' · جایگاه ' . esc_html( qtb_num( $sv['own_pos'] ) ) : '' ) . '</span>' : ''; ?>
			<?php echo ! empty( $c['issues'] ) ? '<span class="qtb-chip is-warn" title="' . esc_attr( implode( ' | ', array_column( $c['issues'], 'text' ) ) ) . '">هشدار</span>' : ''; ?>
			<select data-qtb-page="<?php echo esc_attr( $c['id'] ); ?>" style="min-height:24px;padding:0 8px 0 24px;width:auto;font-size:11.5px;border-radius:99px;background-position:left 7px center">
				<?php qtb_options( array_map( function ( $t ) { return $t[0]; }, qtb_page_types() ), $c['page'] ); ?>
			</select>
		</div>
		<?php if ( $c['target'] ) : ?>
			<div class="qtb-cl-target">صفحه هدف: <?php echo qtb_link( $c['target']['url'], 40 ); // phpcs:ignore ?></div>
		<?php endif; ?>
		<ul>
			<?php foreach ( $c['kws'] as $m ) : ?>
				<li data-kw="<?php echo esc_attr( $m ); ?>"><span title="<?php echo esc_attr( $kws[ $m ]['k'] ); ?>"><?php echo esc_html( $kws[ $m ]['k'] ); ?></span><em><?php echo esc_html( qtb_num( $kws[ $m ]['v'] ) ); ?></em>
					<span class="qtb-kw-acts">
						<button type="button" class="qtb-icon-btn" data-qtb-move="<?php echo esc_attr( $m ); ?>" title="انتقال به کلاستر دیگر"><?php echo qtb_icon( 'undo', 13 ); // phpcs:ignore ?></button>
						<button type="button" class="qtb-icon-btn is-del" data-qtb-remove="<?php echo esc_attr( $m ); ?>" title="حذف کلمه"><?php echo qtb_icon( 'x', 13 ); // phpcs:ignore ?></button>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="qtb-cl-foot"><span>جمع <b><?php echo esc_html( qtb_num( $c['vol'] ) ); ?></b> · <?php echo esc_html( qtb_num( count( $c['kws'] ) ) ); ?> کلمه</span><?php echo qtb_score_badge( $c['score'] ); // phpcs:ignore ?></div>
	</div>
	<?php
};

if ( '' !== $q ) :
	?>
	<h2 class="qtb-section-title"><?php echo qtb_icon( 'research', 18 ); // phpcs:ignore ?> نتیجه جستجوی «<?php echo esc_html( $q ); ?>» <span class="qtb-muted qtb-small"><?php echo esc_html( qtb_num( count( $found ) ) ); ?> کلاستر</span></h2>
	<div class="qtb-clusters">
		<?php
		foreach ( $found as $cid ) {
			$card( $by_id[ $cid ] );
		}
		?>
	</div>
	<?php
elseif ( $pillar ) :
	?>
	<div class="qtb-sections">
		<?php foreach ( $pillar['sections'] as $s ) : ?>
			<section>
				<div class="qtb-sec-h"><i class="is-<?php echo esc_attr( $s['kind'] ); ?>"></i><?php echo esc_html( $s['name'] ); ?> <span class="qtb-muted qtb-small"><?php echo esc_html( qtb_num( count( $s['clusters'] ) ) ); ?> کلاستر · <?php echo esc_html( qtb_num( $s['vol'] ) ); ?></span></div>
				<div class="qtb-clusters">
					<?php
					foreach ( $s['clusters'] as $cid ) {
						if ( isset( $by_id[ $cid ] ) ) {
							$card( $by_id[ $cid ] );
						}
					}
					?>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
	<div class="qtb-bulkbar" data-qtb-bulk hidden>
		<span><b data-qtb-bulk-n>۰</b> کلاستر انتخاب شد</span>
		<span class="qtb-inline">
			<button type="button" class="qtb-btn is-small is-gold" data-qtb-merge><?php echo qtb_icon( 'layers', 14 ); // phpcs:ignore ?> ادغام در اولین انتخاب</button>
			<button type="button" class="qtb-btn is-small" data-qtb-unpick>لغو</button>
		</span>
	</div>
</div>
