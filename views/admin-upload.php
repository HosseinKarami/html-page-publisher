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
$htmlpp_storage_path = ltrim( wp_make_link_relative( HTMLPP_Storage::base_url() ), '/' );
$htmlpp_settings_url = admin_url( 'admin.php?page=html-page-publisher-settings' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill after a failed upload; nothing is written.
$htmlpp_prefill_slug = isset( $_GET['prefill_slug'] ) ? HTMLPP_Storage::sanitize_slug( sanitize_text_field( wp_unslash( $_GET['prefill_slug'] ) ) ) : '';
$htmlpp_editing_off  = HTMLPP_Uploader::file_editing_disabled();
$htmlpp_protection   = get_transient( HTMLPP_Storage::PROTECTION_TRANSIENT );
$htmlpp_protection   = is_array( $htmlpp_protection ) && isset( $htmlpp_protection['status'] ) ? $htmlpp_protection['status'] : 'unknown';
?>
<div class="wrap htmlpp-page">

	<div class="htmlpp-hero">
		<div class="htmlpp-hero__icon" aria-hidden="true">
		<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256"><defs><radialGradient id="svg-p-a" cx="78%" cy="22%" r="55%"><stop offset="0%" stop-color="#6366f1" stop-opacity=".35"/><stop offset="100%" stop-color="#6366f1" stop-opacity="0"/></radialGradient><filter id="svg-p-b" width="140%" height="140%" x="-20%" y="-20%"><feGaussianBlur in="SourceAlpha" stdDeviation="4"/><feOffset dy="3" result="offset"/><feComponentTransfer><feFuncA slope=".18" type="linear"/></feComponentTransfer><feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs><rect width="256" height="256" fill="#1e1b4b" rx="56"/><rect width="256" height="256" fill="url(#svg-p-a)" rx="56"/><g filter="url(#svg-p-b)" transform="translate(64 50)"><path fill="#fff" d="M0 12Q0 0 12 0h76l40 40v104q0 12-12 12H12q-12 0-12-12Z"/><path fill="#c7d2fe" d="m88 0 40 40h-28q-12 0-12-12Z"/><g fill="none" stroke="#5b5bd6" stroke-linecap="round" stroke-linejoin="round" stroke-width="9"><path d="M40 65 24 85l16 20M54 57l20 56M88 65l16 20-16 20"/></g></g></svg>
		</div>
		<div class="htmlpp-hero__body">
			<h1 class="htmlpp-hero__title">
				<?php esc_html_e( 'HTML Page Publisher', 'html-page-publisher' ); ?>
			</h1>
			<p class="htmlpp-hero__subtitle">
				<?php esc_html_e( 'Upload standalone HTML files — Claude Design exports or any static HTML — and publish them as landing pages at a clean URL.', 'html-page-publisher' ); ?>
			</p>
		</div>
		<div class="htmlpp-hero__actions">
			<a href="<?php echo esc_url( $htmlpp_settings_url ); ?>" class="htmlpp-button htmlpp-button-ghost">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<line x1="21" y1="4" x2="14" y2="4"/>
					<line x1="10" y1="4" x2="3" y2="4"/>
					<line x1="21" y1="12" x2="12" y2="12"/>
					<line x1="8" y1="12" x2="3" y2="12"/>
					<line x1="21" y1="20" x2="16" y2="20"/>
					<line x1="12" y1="20" x2="3" y2="20"/>
					<line x1="14" y1="2" x2="14" y2="6"/>
					<line x1="8" y1="10" x2="8" y2="14"/>
					<line x1="16" y1="18" x2="16" y2="22"/>
				</svg>
				<?php esc_html_e( 'Settings', 'html-page-publisher' ); ?>
			</a>
			<a href="<?php echo esc_url( HTMLPP_Admin::donate_url( 'admin-header-donate' ) ); ?>" class="htmlpp-button htmlpp-button-donate" target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M17 8h1a4 4 0 0 1 0 8h-1"/>
					<path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/>
					<line x1="6" y1="2" x2="6" y2="4"/>
					<line x1="10" y1="2" x2="10" y2="4"/>
					<line x1="14" y1="2" x2="14" y2="4"/>
				</svg>
				<?php esc_html_e( 'Donate', 'html-page-publisher' ); ?>
			</a>
		</div>
	</div>

	<div class="htmlpp-content">

	<?php // WordPress relocates admin_notices to just after .wp-header-end; keep them below the hero. ?>
	<div class="wp-header-end"></div>

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
			<?php if ( 'blocked' === $htmlpp_protection ) : ?>
				<p class="htmlpp-stat__hint"><?php esc_html_e( 'Direct access blocked (verified).', 'html-page-publisher' ); ?></p>
			<?php elseif ( 'open' === $htmlpp_protection ) : ?>
				<p class="htmlpp-stat__hint htmlpp-stat__hint--warning">
					<?php
					printf(
						/* translators: %s: link to the Settings screen */
						esc_html__( 'Direct access is open — see %s.', 'html-page-publisher' ),
						'<a href="' . esc_url( $htmlpp_settings_url ) . '">' . esc_html__( 'Settings', 'html-page-publisher' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<p class="htmlpp-stat__hint">
					<?php
					printf(
						/* translators: %s: link to the Settings screen */
						esc_html__( 'Served at the public URL; check protection in %s.', 'html-page-publisher' ),
						'<a href="' . esc_url( $htmlpp_settings_url ) . '">' . esc_html__( 'Settings', 'html-page-publisher' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
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
								value="<?php echo esc_attr( $htmlpp_prefill_slug ); ?>"
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
							<?php esc_html_e( 'Any standalone HTML file. Claude Design’s export-time runtime wrappers are stripped automatically.', 'html-page-publisher' ); ?>
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

					<div class="htmlpp-field">
						<?php if ( $htmlpp_editing_off ) : ?>
							<p class="htmlpp-field__help">
								<?php esc_html_e( 'Replacing an existing page is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant. Choose a slug that is not published yet.', 'html-page-publisher' ); ?>
							</p>
						<?php else : ?>
							<label class="htmlpp-checkbox">
								<input type="checkbox" name="htmlpp_overwrite" value="1" aria-describedby="htmlpp-overwrite-help" />
								<span><?php esc_html_e( 'Replace the existing page', 'html-page-publisher' ); ?></span>
							</label>
							<p class="htmlpp-field__help" id="htmlpp-overwrite-help">
								<?php esc_html_e( 'Only applies if this slug is already published. Off by default so a typo can’t overwrite a live page; when on, the current version is kept in the page’s version history.', 'html-page-publisher' ); ?>
							</p>
						<?php endif; ?>
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
								<th style="width:170px;"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pages as $htmlpp_page ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( $htmlpp_page['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="htmlpp-slug">
											<?php echo esc_html( $htmlpp_page['slug'] ); ?>
										</a>
										<?php if ( ! empty( $htmlpp_page['title'] ) ) : ?>
											<span class="htmlpp-page-title" title="<?php echo esc_attr( $htmlpp_page['title'] ); ?>"><?php echo esc_html( $htmlpp_page['title'] ); ?></span>
										<?php endif; ?>
										<span class="htmlpp-url-pill" title="<?php echo esc_attr( $htmlpp_page['url'] ); ?>">
											<span class="htmlpp-url-pill__text"><?php echo esc_html( $htmlpp_page['url'] ); ?></span>
											<button type="button" class="htmlpp-copy-btn" data-url="<?php echo esc_attr( $htmlpp_page['url'] ); ?>" aria-label="<?php esc_attr_e( 'Copy URL', 'html-page-publisher' ); ?>">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
													<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
													<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
												</svg>
											</button>
										</span>
									</td>
									<td><?php echo (int) count( $htmlpp_page['images'] ); ?></td>
									<td><?php echo esc_html( wp_date( 'M j, Y g:ia', $htmlpp_page['modified'] ) ); ?></td>
									<td>
										<div class="htmlpp-row-actions">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=html-page-publisher&action=edit&slug=' . rawurlencode( $htmlpp_page['slug'] ) ) ); ?>" class="htmlpp-edit-btn">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
													<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
													<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>
												</svg>
												<?php esc_html_e( 'Edit', 'html-page-publisher' ); ?>
											</a>
											<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: page slug */ __( 'Delete "%s"? This cannot be undone.', 'html-page-publisher' ), $htmlpp_page['slug'] ) ); ?>')">
												<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
												<input type="hidden" name="htmlpp_delete" value="<?php echo esc_attr( $htmlpp_page['slug'] ); ?>" />
												<button type="submit" class="htmlpp-delete-btn">
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
														<polyline points="3 6 5 6 21 6"/>
														<path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/>
														<path d="M10 11v6M14 11v6"/>
													</svg>
													<?php esc_html_e( 'Delete', 'html-page-publisher' ); ?>
												</button>
											</form>
										</div>
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
