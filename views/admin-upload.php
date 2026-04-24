<?php
/**
 * Admin upload/list page template.
 *
 * @package HTMLPP
 *
 * @var array|null $notice Notice array passed in from HTMLPP_Admin.
 * @var array      $pages  Page list from HTMLPP_Storage::list_pages().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$htmlpp_settings     = HTMLPP_Settings::get_settings();
$htmlpp_example_url  = HTMLPP_Storage::public_page_url( 'your-slug' );
$htmlpp_storage_path = str_replace( ABSPATH, '', HTMLPP_Storage::base_dir() );
$htmlpp_settings_url = admin_url( 'admin.php?page=html-page-publisher-settings' );
?>
<div class="wrap htmlpp-page">

	<div class="htmlpp-hero">
		<div class="htmlpp-hero__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
				<polyline points="13 2 13 9 20 9"/>
				<polyline points="10 13 8 16 10 19"/>
				<polyline points="14 13 16 16 14 19"/>
			</svg>
		</div>
		<div class="htmlpp-hero__body">
			<h1 class="htmlpp-hero__title">
				<?php esc_html_e( 'HTML Page Publisher', 'html-page-publisher' ); ?>
			</h1>
			<p class="htmlpp-hero__subtitle">
				<?php esc_html_e( 'Upload standalone HTML files and publish them as landing pages at a clean URL.', 'html-page-publisher' ); ?>
			</p>
		</div>
		<div class="htmlpp-hero__actions">
			<a href="<?php echo esc_url( $htmlpp_settings_url ); ?>" class="htmlpp-button htmlpp-button-ghost">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="3"/>
					<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.9 2.9l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.9-2.9l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.9-2.9l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.9 2.9l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
				</svg>
				<?php esc_html_e( 'Settings', 'html-page-publisher' ); ?>
			</a>
		</div>
	</div>

	<div class="htmlpp-content">

	<div class="htmlpp-stats">
		<div class="htmlpp-stat">
			<p class="htmlpp-stat__label"><?php esc_html_e( 'Published Pages', 'html-page-publisher' ); ?></p>
			<p class="htmlpp-stat__value"><?php echo (int) count( $pages ); ?></p>
		</div>
		<div class="htmlpp-stat">
			<p class="htmlpp-stat__label"><?php esc_html_e( 'URL Pattern', 'html-page-publisher' ); ?></p>
			<p class="htmlpp-stat__value" style="font-size:15px;"><?php echo esc_html( $htmlpp_example_url ); ?></p>
		</div>
		<div class="htmlpp-stat">
			<p class="htmlpp-stat__label"><?php esc_html_e( 'Storage Location', 'html-page-publisher' ); ?></p>
			<p class="htmlpp-stat__hint" style="margin-top:6px;">/<?php echo esc_html( $htmlpp_storage_path ); ?>/</p>
		</div>
	</div>

	<?php if ( $notice ) : ?>
		<?php $htmlpp_class = 'success' === $notice['type'] ? 'htmlpp-notice--success' : 'htmlpp-notice--error'; ?>
		<div class="htmlpp-notice <?php echo esc_attr( $htmlpp_class ); ?>">
			<span class="htmlpp-notice__icon" aria-hidden="true">
				<?php if ( 'success' === $notice['type'] ) : ?>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<polyline points="20 6 9 17 4 12"/>
					</svg>
				<?php else : ?>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10"/>
						<line x1="12" y1="8" x2="12" y2="12"/>
						<line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
				<?php endif; ?>
			</span>
			<div class="htmlpp-notice__body">
				<?php
				if ( ! empty( $notice['raw_html'] ) ) {
					echo wp_kses_post( $notice['message'] );
				} else {
					echo esc_html( $notice['message'] );
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<div class="htmlpp-grid">

		<div class="htmlpp-card">
			<div class="htmlpp-card__header">
				<div>
					<h2 class="htmlpp-card__title"><?php esc_html_e( 'Upload New Page', 'html-page-publisher' ); ?></h2>
					<p class="htmlpp-card__subtitle"><?php esc_html_e( 'HTML file + assets become a public page.', 'html-page-publisher' ); ?></p>
				</div>
			</div>

			<div class="htmlpp-card__body">
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>

					<div class="htmlpp-field">
						<label class="htmlpp-field__label" for="page_slug"><?php esc_html_e( 'Page slug', 'html-page-publisher' ); ?></label>
						<div class="htmlpp-field__control htmlpp-field__control--mono">
							<input type="text"
								id="page_slug"
								name="page_slug"
								required
								pattern="[a-z0-9\-]+"
								placeholder="lead-gen-program" />
						</div>
						<p class="htmlpp-field__help">
							<?php
							printf(
								/* translators: %s: example page URL */
								esc_html__( 'URL-friendly identifier. Your page will live at %s', 'html-page-publisher' ),
								'<code>' . esc_html( $htmlpp_example_url ) . '</code>'
							);
							?>
						</p>
					</div>

					<div class="htmlpp-field">
						<label class="htmlpp-field__label" for="html_file"><?php esc_html_e( 'HTML file', 'html-page-publisher' ); ?></label>
						<div class="htmlpp-dropzone">
							<input type="file" id="html_file" name="html_file" accept=".html,.htm" required />
							<span class="htmlpp-dropzone__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
									<polyline points="17 8 12 3 7 8"/>
									<line x1="12" y1="3" x2="12" y2="15"/>
								</svg>
							</span>
							<span class="htmlpp-dropzone__body">
								<span class="htmlpp-dropzone__title"><?php esc_html_e( 'Click or drag to upload', 'html-page-publisher' ); ?></span>
								<span class="htmlpp-dropzone__hint"><?php esc_html_e( '.html or .htm file', 'html-page-publisher' ); ?></span>
							</span>
						</div>
						<p class="htmlpp-field__help">
							<?php esc_html_e( 'Any standalone HTML file. AI export runtime wrappers are automatically stripped.', 'html-page-publisher' ); ?>
						</p>
					</div>

					<div class="htmlpp-field">
						<label class="htmlpp-field__label" for="image_files"><?php esc_html_e( 'Images (optional)', 'html-page-publisher' ); ?></label>
						<div class="htmlpp-dropzone">
							<input type="file" id="image_files" name="image_files[]" accept="image/*" multiple />
							<span class="htmlpp-dropzone__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
									<circle cx="8.5" cy="8.5" r="1.5"/>
									<polyline points="21 15 16 10 5 21"/>
								</svg>
							</span>
							<span class="htmlpp-dropzone__body">
								<span class="htmlpp-dropzone__title"><?php esc_html_e( 'Click or drag images', 'html-page-publisher' ); ?></span>
								<span class="htmlpp-dropzone__hint"><?php esc_html_e( 'PNG, JPG, GIF, SVG, WebP, AVIF', 'html-page-publisher' ); ?></span>
							</span>
						</div>
						<p class="htmlpp-field__help">
							<?php esc_html_e( 'Uploaded to the assets/ folder. Filenames must match references in the HTML.', 'html-page-publisher' ); ?>
						</p>
					</div>

					<div class="htmlpp-form-footer">
						<button type="submit" name="htmlpp_upload" class="htmlpp-button-primary">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="20 6 9 17 4 12"/>
							</svg>
							<?php esc_html_e( 'Publish Page', 'html-page-publisher' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>

		<div class="htmlpp-card">
			<div class="htmlpp-card__header">
				<div>
					<h2 class="htmlpp-card__title"><?php esc_html_e( 'Existing Pages', 'html-page-publisher' ); ?></h2>
					<p class="htmlpp-card__subtitle"><?php printf( /* translators: %d: count */ esc_html( _n( '%d page published', '%d pages published', count( $pages ), 'html-page-publisher' ) ), (int) count( $pages ) ); ?></p>
				</div>
				<?php if ( ! empty( $pages ) ) : ?>
					<span class="htmlpp-badge"><?php echo (int) count( $pages ); ?></span>
				<?php endif; ?>
			</div>

			<div class="htmlpp-card__body htmlpp-card__body--flush">
				<?php if ( empty( $pages ) ) : ?>
					<div class="htmlpp-empty">
						<div class="htmlpp-empty__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
								<polyline points="14 2 14 8 20 8"/>
							</svg>
						</div>
						<p class="htmlpp-empty__title"><?php esc_html_e( 'No pages yet', 'html-page-publisher' ); ?></p>
						<p class="htmlpp-empty__body"><?php esc_html_e( 'Upload an HTML file on the left to publish your first page.', 'html-page-publisher' ); ?></p>
					</div>
				<?php else : ?>
					<div class="htmlpp-table-scroll">
					<table class="htmlpp-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'html-page-publisher' ); ?></th>
								<th style="width:90px;"><?php esc_html_e( 'Images', 'html-page-publisher' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'Modified', 'html-page-publisher' ); ?></th>
								<th style="width:90px;"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pages as $page ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="htmlpp-slug">
											<?php echo esc_html( $page['slug'] ); ?>
										</a>
										<span class="htmlpp-url-pill" title="<?php echo esc_attr( $page['url'] ); ?>">
											<span class="htmlpp-url-pill__text"><?php echo esc_html( $page['url'] ); ?></span>
											<button type="button" class="htmlpp-copy-btn" data-url="<?php echo esc_attr( $page['url'] ); ?>" aria-label="<?php esc_attr_e( 'Copy URL', 'html-page-publisher' ); ?>">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
													<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
													<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
												</svg>
											</button>
										</span>
									</td>
									<td><?php echo (int) count( $page['images'] ); ?></td>
									<td><?php echo esc_html( wp_date( 'M j, Y g:ia', $page['modified'] ) ); ?></td>
									<td>
										<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: page slug */ __( 'Delete "%s"? This cannot be undone.', 'html-page-publisher' ), $page['slug'] ) ); ?>')">
											<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
											<input type="hidden" name="htmlpp_delete" value="<?php echo esc_attr( $page['slug'] ); ?>" />
											<button type="submit" class="htmlpp-delete-btn">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
													<polyline points="3 6 5 6 21 6"/>
													<path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/>
													<path d="M10 11v6M14 11v6"/>
												</svg>
												<?php esc_html_e( 'Delete', 'html-page-publisher' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div><!-- /.htmlpp-table-scroll -->
				<?php endif; ?>
			</div>
		</div>

	</div><!-- /.htmlpp-grid -->

	</div><!-- /.htmlpp-content -->
</div>
