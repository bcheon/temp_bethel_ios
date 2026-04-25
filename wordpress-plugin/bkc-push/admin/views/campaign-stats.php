<?php
/**
 * Admin view: Per-campaign statistics.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

$uuid = isset( $_GET['uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) : '';

if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
	wp_die( esc_html__( '올바르지 않은 캠페인 UUID입니다.', 'bkc-push' ) );
}

global $wpdb;

$campaign = BKC_Campaigns::find_by_uuid( $uuid );
if ( ! $campaign ) {
	wp_die( esc_html__( '캠페인을 찾을 수 없습니다.', 'bkc-push' ) );
}

$stats = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM `{$wpdb->prefix}bkc_campaign_stats` WHERE campaign_uuid = %s",
		$uuid
	)
);

$targeted   = $stats ? max( 1, (int) $stats->subscribers_targeted ) : 1;
$delivered  = $stats ? (int) $stats->delivered_count : 0;
$opened     = $stats ? (int) $stats->opened_count : 0;
$deeplinked = $stats ? (int) $stats->deeplinked_count : 0;

$delivery_rate  = round( $delivered / $targeted * 100, 1 );
$open_rate      = $delivered > 0 ? round( $opened / $delivered * 100, 1 ) : 0;
$deeplink_ctr   = $opened > 0 ? round( $deeplinked / $opened * 100, 1 ) : 0;

// Sparkline data: events over time (last 7 days).
$sparkline_data = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT DATE(occurred_at) AS day, event_type, COUNT(DISTINCT device_id) AS cnt
		FROM `{$wpdb->prefix}bkc_campaign_events`
		WHERE campaign_uuid = %s AND occurred_at >= NOW() - INTERVAL 7 DAY
		GROUP BY DATE(occurred_at), event_type
		ORDER BY day ASC",
		$uuid
	),
	ARRAY_A
);

// Organise sparkline by day.
$days_data = [];
foreach ( $sparkline_data as $row ) {
	$days_data[ $row['day'] ][ $row['event_type'] ] = (int) $row['cnt'];
}
ksort( $days_data );

$max_val = 1;
foreach ( $days_data as $day_counts ) {
	foreach ( $day_counts as $cnt ) {
		$max_val = max( $max_val, $cnt );
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( '캠페인 통계', 'bkc-push' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push-campaigns' ) ); ?>">
			&larr; <?php esc_html_e( '발송 이력으로 돌아가기', 'bkc-push' ); ?>
		</a>
	</p>

	<h2><?php echo esc_html( $campaign->title ); ?></h2>
	<p>
		<strong><?php esc_html_e( '발송일시:', 'bkc-push' ); ?></strong>
		<?php echo esc_html( $campaign->sent_at ?? __( '미발송', 'bkc-push' ) ); ?>
		&nbsp;|&nbsp;
		<strong><?php esc_html_e( '상태:', 'bkc-push' ); ?></strong>
		<?php echo esc_html( $campaign->status ); ?>
		&nbsp;|&nbsp;
		<strong>UUID:</strong> <code><?php echo esc_html( $uuid ); ?></code>
	</p>

	<table class="wp-list-table widefat fixed" style="max-width:600px;">
		<thead>
			<tr>
				<th><?php esc_html_e( '지표', 'bkc-push' ); ?></th>
				<th><?php esc_html_e( '건수', 'bkc-push' ); ?></th>
				<th><?php esc_html_e( '비율', 'bkc-push' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( '발송 대상 구독자', 'bkc-push' ); ?></td>
				<td><?php echo esc_html( number_format( $targeted ) ); ?></td>
				<td>—</td>
			</tr>
			<tr>
				<td><?php esc_html_e( '전달 (delivered)', 'bkc-push' ); ?></td>
				<td><?php echo esc_html( number_format( $delivered ) ); ?></td>
				<td><?php echo esc_html( $delivery_rate ); ?>%</td>
			</tr>
			<tr>
				<td><?php esc_html_e( '열람 (opened)', 'bkc-push' ); ?></td>
				<td><?php echo esc_html( number_format( $opened ) ); ?></td>
				<td><?php echo esc_html( $open_rate ); ?>%
					<small><?php esc_html_e( '(전달 대비)', 'bkc-push' ); ?></small>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( '딥링크 클릭 (deeplinked)', 'bkc-push' ); ?></td>
				<td><?php echo esc_html( number_format( $deeplinked ) ); ?></td>
				<td><?php echo esc_html( $deeplink_ctr ); ?>%
					<small><?php esc_html_e( '(열람 대비)', 'bkc-push' ); ?></small>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( ! empty( $days_data ) ) : ?>
		<h3><?php esc_html_e( '최근 7일 이벤트 추이', 'bkc-push' ); ?></h3>
		<svg width="400" height="80" viewBox="0 0 400 80" xmlns="http://www.w3.org/2000/svg"
			aria-label="<?php esc_attr_e( '이벤트 스파크라인', 'bkc-push' ); ?>" role="img"
			style="border:1px solid #ddd; background:#fafafa; display:block;">
			<?php
			$num_days = count( $days_data );
			$day_keys = array_keys( $days_data );
			$col_w    = $num_days > 1 ? 400 / $num_days : 400;
			$colors   = [
				'delivered'  => '#0073aa',
				'opened'     => '#00a32a',
				'deeplinked' => '#d63638',
			];
			$bar_h   = 60;
			$bar_top = 10;

			foreach ( $day_keys as $idx => $day ) {
				$day_counts = $days_data[ $day ];
				$x_base     = $idx * $col_w;
				$sub_w      = $col_w / 3;

				foreach ( array_values( [ 'delivered', 'opened', 'deeplinked' ] ) as $ei => $etype ) {
					$cnt  = $day_counts[ $etype ] ?? 0;
					$h    = $cnt > 0 ? max( 2, (int) round( $cnt / $max_val * $bar_h ) ) : 0;
					$x    = $x_base + $ei * $sub_w;
					$y    = $bar_top + $bar_h - $h;
					$fill = $colors[ $etype ];
					printf(
						'<rect x="%.1f" y="%.1f" width="%.1f" height="%d" fill="%s" opacity="0.8"><title>%s %s: %d</title></rect>',
						$x + 1,
						$y,
						max( 1, $sub_w - 2 ),
						$h,
						esc_attr( $fill ),
						esc_attr( $day ),
						esc_attr( $etype ),
						$cnt
					);
				}

				// Day label.
				printf(
					'<text x="%.1f" y="78" font-size="8" fill="#666" text-anchor="middle">%s</text>',
					$x_base + $col_w / 2,
					esc_html( substr( $day, 5 ) ) // MM-DD
				);
			}
			?>
		</svg>
		<p>
			<span style="color:#0073aa;">&#9632;</span> <?php esc_html_e( '전달', 'bkc-push' ); ?>&nbsp;&nbsp;
			<span style="color:#00a32a;">&#9632;</span> <?php esc_html_e( '열람', 'bkc-push' ); ?>&nbsp;&nbsp;
			<span style="color:#d63638;">&#9632;</span> <?php esc_html_e( '딥링크', 'bkc-push' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $stats && $stats->last_rolled_up ) : ?>
		<p style="color:#666; font-size:12px;">
			<?php
			printf(
				/* translators: %s: datetime of last rollup */
				esc_html__( '마지막 집계: %s', 'bkc-push' ),
				esc_html( $stats->last_rolled_up )
			);
			?>
		</p>
	<?php endif; ?>
</div>
