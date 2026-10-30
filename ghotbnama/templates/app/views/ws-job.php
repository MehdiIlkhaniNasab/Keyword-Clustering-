<?php
/**
 * Progress of the running workshop job.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $job
 */

defined( 'ABSPATH' ) || exit;

$running = $job && empty( $job['done'] ) && empty( $job['error'] );
$st      = $job ? qtb_ws_job_status( $job ) : null;
$names   = array( 'triage' => 'غربال کلمات', 'topics' => 'تقسیم موضوعی', 'topic' => 'پردازش موضوع' );
?>
<div class="qtb-panel is-hero qtb-wsjob<?php echo $st && $st['done'] ? ' is-done' : ''; ?>" data-qtb-wsjob="<?php echo esc_attr( $project['id'] ); ?>" data-running="<?php echo $running ? 1 : 0; ?>" <?php echo $st && ( $running || $st['error'] || ( $st['done'] && ! empty( $job['started'] ) && strtotime( $job['started'] ) > current_time( 'timestamp' ) - 600 ) ) ? '' : 'hidden'; // phpcs:ignore ?>>
	<div class="qtb-run">
		<div>
			<h2><?php echo qtb_icon( 'play', 18 ); // phpcs:ignore ?> <span data-qtb-wsjob-title><?php echo esc_html( $st ? ( $names[ $st['action'] ] ?? '' ) : '' ); ?></span></h2>
			<div class="qtb-progress"><i style="width:<?php echo esc_attr( $st ? $st['pct'] : 0 ); ?>%"></i></div>
			<p class="qtb-small" data-qtb-label style="margin:0;color:#E9F5EF"><?php echo esc_html( $st ? ( $st['error'] ? 'خطا: ' . $st['error'] : ( $st['done'] ? 'تمام شد.' : $st['label'] . '…' ) ) : '' ); ?></p>
		</div>
		<div>
			<ul class="qtb-steps" data-qtb-steps>
				<?php foreach ( $st ? $st['steps'] : array() as $s ) : ?>
					<li class="is-<?php echo esc_attr( $s['state'] ); ?>"><i></i><?php echo esc_html( $s['label'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<ul class="qtb-log" data-qtb-log <?php echo $st && $st['log'] ? '' : 'hidden'; ?>>
				<?php foreach ( $st ? $st['log'] : array() as $l ) : ?>
					<li class="is-<?php echo esc_attr( $l['l'] ); ?>"><b><?php echo esc_html( qtb_fa( $l['t'] ) ); ?></b><?php echo esc_html( $l['m'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</div>
