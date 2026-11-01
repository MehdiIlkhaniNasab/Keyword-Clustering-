<?php
/**
 * Clustering workshop: profile → triage → topics → topic review → final.
 *
 * @package Ghotbnama
 * @var array $project
 * @var array $R
 */

defined( 'ABSPATH' ) || exit;

$ws    = qtb_ws_get( $project['id'] );
$job   = qtb_job_settle( $project['id'], 'ws_job' );
$kws   = qtb_data_get( $project['id'], 'keywords', array() );
// A topic left «running» by a job that is no longer alive goes back to its last state.
$live_tid = $job && empty( $job['done'] ) && empty( $job['error'] ) && 'topic' === ( $job['action'] ?? '' ) ? (int) $job['tid'] : 0;
$fixed    = false;
foreach ( $ws['topics'] as &$wt ) {
	if ( 'running' === $wt['status'] && (int) $wt['id'] !== $live_tid ) {
		$wt['status'] = $wt['clusters'] ? 'ready' : 'new';
		$fixed        = true;
	}
}
unset( $wt );
if ( $fixed ) {
	qtb_ws_put( $project['id'], $ws );
}
$has   = $kws && isset( reset( $kws )['ws'] );
$step  = sanitize_key( wp_unslash( $_GET['ws'] ?? '' ) ); // phpcs:ignore
$tid   = absint( $_GET['tid'] ?? 0 ); // phpcs:ignore
if ( '' === $step ) {
	$step = ! $has ? ( '' === trim( (string) $project['cfg']['sells'] ) && qtb_can() ? 'profile' : 'triage' ) : ( 'triage' === $ws['stage'] ? 'triage' : 'topics' );
}
if ( 'topic' === $step && null === qtb_ws_topic_index( $ws, $tid ) ) {
	$step = 'topics';
}
$approved = count( array_filter( $ws['topics'], function ( $t ) {
	return 'approved' === $t['status'];
} ) );
$steps = array(
	'profile' => array( '۱', 'پروفایل کسب‌وکار' ),
	'triage'  => array( '۲', 'غربال کلمات' ),
	'topics'  => array( '۳', 'موضوع‌ها و بازبینی' ),
	'final'   => array( '۴', 'خروجی نهایی' ),
);
$url = function ( $s, $args = array() ) use ( $project ) {
	return qtb_project_url( $project['id'], 'workshop', array_merge( array( 'ws' => $s ), $args ) );
};
?>
<nav class="qtb-stepper">
	<?php foreach ( $steps as $k => $s ) : ?>
		<a class="<?php echo $k === $step || ( 'topic' === $step && 'topics' === $k ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url( $k ) ); ?>"><i><?php echo esc_html( $s[0] ); ?></i><?php echo esc_html( $s[1] ); ?>
			<?php if ( 'topics' === $k && $ws['topics'] ) : ?><em><?php echo esc_html( qtb_num( $approved ) . '/' . qtb_num( count( $ws['topics'] ) ) ); ?></em><?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php
qtb_view( 'ws-job', array( 'project' => $project, 'job' => $job ) );
qtb_view( 'ws-' . $step, array( 'project' => $project, 'ws' => $ws, 'kws' => $kws, 'has' => $has, 'tid' => $tid, 'url' => $url, 'job' => $job ) );
