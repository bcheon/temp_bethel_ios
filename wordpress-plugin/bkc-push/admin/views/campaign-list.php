<?php
/**
 * Admin view: Campaign list.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

$campaigns = BKC_Campaigns::list( 50, 0 );
?>
<div class="wrap">
	<h1><?php esc_html_e( '발송 이력', 'bkc-push' ); ?></h1>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push-compose' ) ); ?>" class="button button-primary" style="margin-bottom:16px;">
		<?php esc_html_e( '새 공지 작성', 'bkc-push' ); ?>
	</a>

	<?php if ( empty( $campaigns ) ) : ?>
		<p><?php esc_html_e( '아직 발송 이력이 없습니다.', 'bkc-push' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '발송일시', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '제목', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '대상 그룹', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '상태', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( 'FCM Message ID', 'bkc-push' ); ?></th>
					<th><?php esc_html_e( '작업', 'bkc-push' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $campaigns as $campaign ) : ?>
					<?php
					$groups        = json_decode( $campaign->target_groups, true ) ?? [];
					$fcm_ids_raw   = $campaign->fcm_message_ids ? json_decode( $campaign->fcm_message_ids, true ) : [];
					$fcm_id_snippet = '';
					if ( ! empty( $fcm_ids_raw['message_id'] ) ) {
						$full_id        = $fcm_ids_raw['message_id'];
						$fcm_id_snippet = substr( $full_id, -12 );
					}
					?>
					<tr data-uuid="<?php echo esc_attr( $campaign->uuid ); ?>">
						<td><?php echo esc_html( $campaign->created_at ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push&action=stats&uuid=' . urlencode( $campaign->uuid ) ) ); ?>">
								<?php echo esc_html( $campaign->title ); ?>
							</a>
						</td>
						<td><?php echo esc_html( implode( ', ', $groups ) ); ?></td>
						<td>
							<span class="bkc-status bkc-status--<?php echo esc_attr( $campaign->status ); ?>">
								<?php echo esc_html( $campaign->status ); ?>
							</span>
						</td>
						<td>
							<?php if ( $fcm_id_snippet ) : ?>
								<code title="<?php echo esc_attr( $fcm_ids_raw['message_id'] ?? '' ); ?>">
									…<?php echo esc_html( $fcm_id_snippet ); ?>
								</code>
							<?php elseif ( ! empty( $campaign->error_message ) ) : ?>
								<span style="color:red;" title="<?php echo esc_attr( $campaign->error_message ); ?>">
									<?php echo esc_html( substr( $campaign->error_message, 0, 40 ) ); ?>…
								</span>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'queued' === $campaign->status ) : ?>
								<button
									type="button"
									class="button bkc-cancel-btn"
									data-uuid="<?php echo esc_attr( $campaign->uuid ); ?>"
								><?php esc_html_e( '취소', 'bkc-push' ); ?></button>
							<?php endif; ?>
							<?php if ( 'failed' === $campaign->status ) : ?>
								<a
									href="<?php echo esc_url( admin_url( 'admin.php?page=bkc-push-compose&retry=' . urlencode( $campaign->uuid ) ) ); ?>"
									class="button"
								><?php esc_html_e( '재시도', 'bkc-push' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
