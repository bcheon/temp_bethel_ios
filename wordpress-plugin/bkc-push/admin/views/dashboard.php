<?php
/**
 * Admin view: Main dashboard.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

// Subscriber counts per group.
$group_counts = [];
foreach ( BKC_Groups::WHITELIST as $group ) {
	$group_counts[ $group ] = BKC_Subscriptions::count_targeted( [ $group ] );
}

// Last 5 sent campaigns with mini-stats.
$recent_campaigns = BKC_Campaigns::list( 5, 0, 'sent' );

global $wpdb;
$stats_table = $wpdb->prefix . 'bkc_campaign_stats';

// Weekly subscriber trend (new/active devices per day for 7 days).
$sub_trend = $wpdb->get_results(
	"SELECT DATE(last_seen) AS day, COUNT(*) AS cnt
	FROM `{$wpdb->prefix}bkc_subscriptions`
	WHERE last_seen >= NOW() - INTERVAL 7 DAY
	GROUP BY DATE(last_seen)
	ORDER BY day ASC",
	ARRAY_A
);

$group_labels = [
	'all'       => __( '전체', 'bkc-push' ),
	'youth'     => __( '청년부', 'bkc-push' ),
	'newfamily' => __( '새가족', 'bkc-push' ),
];
?>
<div class="wrap">
	<h1><?php esc_html_e( '푸쉬 공지 대시보드', 'bkc-push' ); ?></h1>

	<div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:24px;">

		<!-- Subscriber counts -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; min-width:260px;">
			<h2 style="margin-top:0;"><?php esc_html_e( '구독자 현황', 'bkc-push' ); ?></h2>
			<table style="width:100%; border-collapse:collapse;">
				<tbody>
				<?php foreach ( $group_counts as $group => $count ) : ?>
					<tr>
						<td style="padding:4px 8px 4px 0;">
							<?php echo esc_html( $group_labels[ $group ] ?? $group ); ?>
						</td>
						<td style="padding:4px 0; font-weight:bold; text-align:right;">
							<?php echo esc_html( number_format( $count ) ); ?><?php esc_html_e( '명', 'bkc-push' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $sub_trend ) ) : ?>
				<p style="margin:12px 0 4px; font-size:12px; color:#666;">
					<?php esc_html_e( '7일 활성 추이', 'bkc-push' ); ?>
				</p>
				<?php
				$max_trend = max( array_column( $sub_trend, 'cnt' ) );
				$max_trend = max( 1, (int) $max_trend );
				$sparkw    = 200;
				$sparkh    = 40;
				$ndays     = count( $sub_trend );
				$barw      = $ndays > 0 ? $sparkw / $ndays : $sparkw;
				?>
				<svg width="<?php echo esc_attr( $sparkw ); ?>" height="<?php echo esc_attr( $sparkh ); ?>"
					viewBox="0 0 <?php echo esc_attr( $sparkw ); ?> <?php echo esc_attr( $sparkh ); ?>"
					xmlns="http://www.w3.org/2000/svg" style="display:block;">
					<?php foreach ( $sub_trend as $i => $row ) : ?>
						<?php
						$h = max( 1, (int) round( (int) $row['cnt'] / $max_trend * ( $sparkh - 4 ) ) );
						$x = $i * $barw;
						$y = $sparkh - $h;
						?>
						<rect
							x="<?php echo esc_attr( $x + 1 ); ?>"
							y="<?php echo esc_attr( $y ); ?>"
							width="<?php echo esc_attr( max( 1, $barw - 2 ) ); ?>"
							height="<?php echo esc_attr( $h ); ?>"
							fill="#0073aa" opacity="0.7"
						><title><?php echo esc_attr( $row['day'] . ': ' . $row['cnt'] ); ?></title></rect>
					<?php endforeach; ?>
				</svg>
			<?php endif; ?>
		</div>

		<!-- Quick actions -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; min-width:200px;">
			<h2 style="margin-top:0;"><?php esc_html_e( '빠른 작업', 'bkc-push' ); ?></h2>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push-compose' ) ); ?>"
				class="button button-primary" style="display:block; margin-bottom:8px; text-align:center;">
				<?php esc_html_e( '새 공지 작성', 'bkc-push' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push-campaigns' ) ); ?>"
				class="button" style="display:block; text-align:center;">
				<?php esc_html_e( '발송 이력 보기', 'bkc-push' ); ?>
			</a>
		</div>
	</div>

	<!-- Recent campaigns mini-stats -->
	<h2><?php esc_html_e( '최근 캠페인', 'bkc-push' ); ?></h2>

	<?php if ( empty( $recent_campaigns ) ) : ?>
		<p><?php esc_html_e( '발송된 캠페인이 없습니다.', 'bkc-push' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '발송일시', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '제목', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '대상 그룹', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '발송 대상', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '전달', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '열람', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '딥링크', 'bkc-push' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recent_campaigns as $campaign ) : ?>
					<?php
					$groups = json_decode( $campaign->target_groups, true ) ?? [];

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$s = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM `{$stats_table}` WHERE campaign_uuid = %s",
							$campaign->uuid
						)
					);

					$targeted   = $s ? (int) $s->subscribers_targeted : 0;
					$delivered  = $s ? (int) $s->delivered_count : 0;
					$opened     = $s ? (int) $s->opened_count : 0;
					$deeplinked = $s ? (int) $s->deeplinked_count : 0;
					$del_rate   = $targeted > 0 ? round( $delivered / $targeted * 100 ) . '%' : '—';
					$opn_rate   = $delivered > 0 ? round( $opened / $delivered * 100 ) . '%' : '—';
					$dlk_rate   = $opened > 0 ? round( $deeplinked / $opened * 100 ) . '%' : '—';
					?>
					<tr>
						<td><?php echo esc_html( $campaign->sent_at ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push&action=stats&uuid=' . urlencode( $campaign->uuid ) ) ); ?>">
								<?php echo esc_html( $campaign->title ); ?>
							</a>
						</td>
						<td><?php echo esc_html( implode( ', ', $groups ) ); ?></td>
						<td><?php echo esc_html( number_format( $targeted ) ); ?></td>
						<td><?php echo esc_html( number_format( $delivered ) ); ?> <small>(<?php echo esc_html( $del_rate ); ?>)</small></td>
						<td><?php echo esc_html( number_format( $opened ) ); ?> <small>(<?php echo esc_html( $opn_rate ); ?>)</small></td>
						<td><?php echo esc_html( number_format( $deeplinked ) ); ?> <small>(<?php echo esc_html( $dlk_rate ); ?>)</small></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
