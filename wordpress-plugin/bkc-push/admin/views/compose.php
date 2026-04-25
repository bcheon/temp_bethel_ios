<?php
/**
 * Admin view: Compose push notification.
 *
 * Variables available from BKC_Admin::render_compose():
 *   $idempotency_token string  Fresh UUID for this form load.
 *   $message           string  Success message (empty if none).
 *   $error             string  Error message (empty if none).
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( '새 공지 작성', 'bkc-push' ); ?></h1>

	<?php if ( ! empty( $message ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $error ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $error ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="" id="bkc-compose-form">
		<?php wp_nonce_field( 'bkc_compose' ); ?>
		<input type="hidden" name="idempotency_token" value="<?php echo esc_attr( $idempotency_token ); ?>">

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="bkc-title"><?php esc_html_e( '제목', 'bkc-push' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="bkc-title"
							name="title"
							class="regular-text"
							maxlength="255"
							required
							placeholder="<?php esc_attr_e( '예: 이번 주 주일 설교 안내', 'bkc-push' ); ?>"
						>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bkc-body"><?php esc_html_e( '내용', 'bkc-push' ); ?></label>
					</th>
					<td>
						<textarea
							id="bkc-body"
							name="body"
							rows="4"
							class="large-text"
							required
							placeholder="<?php esc_attr_e( '푸쉬 알림 본문을 입력하세요.', 'bkc-push' ); ?>"
						></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bkc-deep-link"><?php esc_html_e( '딥링크 URL', 'bkc-push' ); ?></label>
					</th>
					<td>
						<input
							type="url"
							id="bkc-deep-link"
							name="deep_link"
							class="regular-text"
							placeholder="https://bkc.org/sermon/..."
						>
						<p class="description"><?php esc_html_e( '선택사항. 알림 탭 시 이동할 bkc.org URL.', 'bkc-push' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '수신 그룹', 'bkc-push' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<?php esc_html_e( '수신 그룹 선택', 'bkc-push' ); ?>
							</legend>
							<label>
								<input
									type="checkbox"
									name="groups[]"
									value="all"
									id="bkc-group-all"
									checked
								>
								<?php esc_html_e( '전체 (all) — 모든 구독자', 'bkc-push' ); ?>
							</label>
							<br>
							<label>
								<input
									type="checkbox"
									name="groups[]"
									value="youth"
									id="bkc-group-youth"
								>
								<?php esc_html_e( '청년부 (youth)', 'bkc-push' ); ?>
							</label>
							<br>
							<label>
								<input
									type="checkbox"
									name="groups[]"
									value="newfamily"
									id="bkc-group-newfamily"
								>
								<?php esc_html_e( '새가족 (newfamily)', 'bkc-push' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( '"전체"를 선택하면 다른 그룹은 무시됩니다. 특정 그룹만 선택하면 해당 구독자에게만 발송됩니다.', 'bkc-push' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>

		<div id="bkc-preview-pane" style="display:none; background:#f0f0f0; border:1px solid #ccc; padding:12px; margin:16px 0; max-width:380px; border-radius:8px;">
			<strong><?php esc_html_e( '미리보기', 'bkc-push' ); ?></strong>
			<p style="margin:6px 0 2px; font-weight:bold;" id="bkc-preview-title"></p>
			<p style="margin:0; color:#333;" id="bkc-preview-body"></p>
		</div>

		<p class="submit">
			<button
				type="submit"
				id="bkc-send"
				class="button button-primary"
			><?php esc_html_e( '발송하기', 'bkc-push' ); ?></button>
		</p>
	</form>
</div>
