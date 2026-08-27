<?php
/**
 * Per-page HTML editor template.
 *
 * @package HTMLPP
 *
 * @var array|null $notice           Notice array from HTMLPP_Uploader.
 * @var string     $slug             Sanitized page slug being edited.
 * @var string     $content          Current index.html contents.
 * @var bool       $editing_disabled True if DISALLOW_FILE_EDIT/MODS is set.
 * @var array      $backups          Backups from HTMLPP_Storage::list_backups().
 * @var array      $assets           Images from HTMLPP_Storage::list_assets().
 * @var string     $page_url         Public URL of the page.
 * @var string     $list_url         Admin URL of the pages list.
 * @var array      $meta             Page metadata from HTMLPP_Meta::get().
 * @var string     $preview_url      Shareable preview URL.
 * @var string     $accept           accept="" list for file inputs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap htmlpp-page">

	<div class="htmlpp-hero">
		<div class="htmlpp-hero__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
				<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>
			</svg>
		</div>
		<div class="htmlpp-hero__body">
			<h1 class="htmlpp-hero__title">
				<?php esc_html_e( 'Edit Page', 'html-page-publisher' ); ?>
			</h1>
			<p class="htmlpp-hero__subtitle">
				<?php
				printf(
					/* translators: %s: page slug */
					esc_html__( 'Editing the HTML for %s. The previous version is backed up automatically on every save.', 'html-page-publisher' ),
					'<code style="color:#c7d2fe;background:rgba(99,102,241,.18);padding:1px 7px;border-radius:5px;">' . esc_html( $slug ) . '</code>'
				);
				?>
			</p>
		</div>
		<div class="htmlpp-hero__actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="htmlpp-button htmlpp-button-ghost">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<line x1="19" y1="12" x2="5" y2="12"/>
					<polyline points="12 19 5 12 12 5"/>
				</svg>
				<?php esc_html_e( 'All Pages', 'html-page-publisher' ); ?>
			</a>
			<?php if ( HTMLPP_Meta::is_public( $meta ) ) : ?>
			<a href="<?php echo esc_url( $page_url ); ?>" class="htmlpp-button htmlpp-button-ghost" target="_blank" rel="noopener noreferrer">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
					<polyline points="15 3 21 3 21 9"/>
					<line x1="10" y1="14" x2="21" y2="3"/>
				</svg>
				<?php esc_html_e( 'View Live', 'html-page-publisher' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'html-page-publisher' ); ?></span>
			</a>
			<?php else : ?>
			<a href="<?php echo esc_url( $preview_url ); ?>" class="htmlpp-button htmlpp-button-ghost" target="_blank" rel="noopener noreferrer">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
					<circle cx="12" cy="12" r="3"/>
				</svg>
				<?php esc_html_e( 'Preview', 'html-page-publisher' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'html-page-publisher' ); ?></span>
			</a>
			<?php endif; ?>
		</div>
	</div>

	<div class="htmlpp-content">

		<?php // WordPress relocates admin_notices to just after .wp-header-end; keep them below the hero. ?>
		<div class="wp-header-end"></div>

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

		<?php if ( $editing_disabled ) : ?>
			<div class="htmlpp-notice htmlpp-notice--error">
				<span class="htmlpp-notice__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0 1 10 0v4"/>
					</svg>
				</span>
				<div class="htmlpp-notice__body">
					<?php esc_html_e( 'Editing is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant. The HTML below is read-only.', 'html-page-publisher' ); ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! HTMLPP_Meta::is_public( $meta ) ) : ?>
			<div class="htmlpp-notice htmlpp-notice--info">
				<span class="htmlpp-notice__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
						<circle cx="12" cy="12" r="3"/>
					</svg>
				</span>
				<div class="htmlpp-notice__body">
					<?php
					printf(
						/* translators: %s: preview URL */
						esc_html__( 'This page is a draft. Visitors and search engines get a 404; you and anyone with the preview link can see it: %s', 'html-page-publisher' ),
						'<a href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $preview_url ) . '</a>'
					);
					?>
					<form method="post" class="htmlpp-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Reset the preview link? Anyone holding the current link will lose access.', 'html-page-publisher' ) ); ?>')">
						<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
						<input type="hidden" name="htmlpp_reset_preview" value="<?php echo esc_attr( $slug ); ?>" />
						<button type="submit" class="button-link"><?php esc_html_e( 'Reset preview link', 'html-page-publisher' ); ?></button>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<form method="post" class="htmlpp-editor-form">
			<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
			<input type="hidden" name="htmlpp_edit" value="<?php echo esc_attr( $slug ); ?>" />

			<div class="htmlpp-card">
				<div class="htmlpp-card__header">
					<div>
						<h2 class="htmlpp-card__title"><?php esc_html_e( 'HTML Source', 'html-page-publisher' ); ?></h2>
						<p class="htmlpp-card__subtitle"><?php esc_html_e( 'Edits are saved to index.html. Claude Design’s export-time runtime wrappers are stripped on save.', 'html-page-publisher' ); ?></p>
					</div>
				</div>
				<div class="htmlpp-card__body">
					<p class="htmlpp-field__help htmlpp-bigfile-note" id="htmlpp-bigfile-note" hidden>
						<?php esc_html_e( 'This file is large or minified, so the plain text editor is used instead of the syntax-highlighted one to keep your browser responsive. Saving still works exactly the same.', 'html-page-publisher' ); ?>
					</p>

					<div class="htmlpp-editor-wrap">
						<textarea
							id="htmlpp-code"
							name="htmlpp_content"
							class="htmlpp-code"
							spellcheck="false"
							autocomplete="off"
							autocapitalize="off"
							<?php echo $editing_disabled ? 'readonly' : ''; ?>
						><?php echo esc_textarea( $content ); ?></textarea>
					</div>
				</div>
			</div>

			<?php if ( ! $editing_disabled ) : ?>
				<div class="htmlpp-actionbar">
					<a href="<?php echo esc_url( $list_url ); ?>" class="htmlpp-button-ghost">
						<?php esc_html_e( 'Cancel', 'html-page-publisher' ); ?>
					</a>
					<button type="submit" class="htmlpp-button-primary">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
							<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/>
							<polyline points="17 21 17 13 7 13 7 21"/>
							<polyline points="7 3 7 8 15 8"/>
						</svg>
						<?php esc_html_e( 'Save Changes', 'html-page-publisher' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</form>

		<div class="htmlpp-card" style="margin-top:20px;">
			<div class="htmlpp-card__header">
				<div>
					<h2 class="htmlpp-card__title"><?php esc_html_e( 'Files &amp; Assets', 'html-page-publisher' ); ?></h2>
					<p class="htmlpp-card__subtitle">
						<?php
						printf(
							/* translators: %s: <code>assets/filename</code> example */
							esc_html__( 'Every file in this page’s folder — images, CSS, JS, fonts. Reference them in your HTML by their path, e.g. %s.', 'html-page-publisher' ),
							'<code>assets/filename</code>'
						);
						?>
					</p>
				</div>
				<?php if ( ! empty( $assets ) ) : ?>
					<span class="htmlpp-badge"><?php echo (int) count( $assets ); ?></span>
				<?php endif; ?>
			</div>

			<div class="htmlpp-card__body">
				<?php if ( empty( $assets ) ) : ?>
					<div class="htmlpp-empty" style="padding:28px 24px;">
						<div class="htmlpp-empty__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
								<circle cx="8.5" cy="8.5" r="1.5"/>
								<polyline points="21 15 16 10 5 21"/>
							</svg>
						</div>
						<p class="htmlpp-empty__title"><?php esc_html_e( 'No files yet', 'html-page-publisher' ); ?></p>
						<p class="htmlpp-empty__body"><?php esc_html_e( 'Upload images or other files below, then reference them in your HTML.', 'html-page-publisher' ); ?></p>
					</div>
				<?php else : ?>
					<div class="htmlpp-assets">
						<?php foreach ( $assets as $htmlpp_asset ) : ?>
							<div class="htmlpp-asset">
								<div class="htmlpp-asset__thumb">
									<?php if ( ! empty( $htmlpp_asset['is_image'] ) ) : ?>
										<img src="<?php echo esc_url( $htmlpp_asset['url'] ); ?>" alt="" loading="lazy" decoding="async" />
									<?php else : ?>
										<span class="htmlpp-asset__ext"><?php echo esc_html( strtoupper( $htmlpp_asset['ext'] ) ); ?></span>
									<?php endif; ?>
								</div>
								<div class="htmlpp-asset__body">
									<span class="htmlpp-asset__name" title="<?php echo esc_attr( $htmlpp_asset['reference'] ); ?>"><?php echo esc_html( $htmlpp_asset['name'] ); ?></span>
									<span class="htmlpp-asset__size"><?php echo esc_html( size_format( $htmlpp_asset['size'] ) ); ?></span>
									<span class="htmlpp-url-pill">
										<span class="htmlpp-url-pill__text"><?php echo esc_html( $htmlpp_asset['reference'] ); ?></span>
										<button type="button" class="htmlpp-copy-btn" data-url="<?php echo esc_attr( $htmlpp_asset['reference'] ); ?>" aria-label="<?php esc_attr_e( 'Copy reference', 'html-page-publisher' ); ?>">
											<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
												<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
												<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
											</svg>
										</button>
									</span>
									<?php if ( ! $editing_disabled ) : ?>
										<div class="htmlpp-asset__actions">
											<form method="post" enctype="multipart/form-data" class="htmlpp-asset-replace">
												<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
												<input type="hidden" name="htmlpp_asset_replace" value="<?php echo esc_attr( $slug ); ?>" />
												<input type="hidden" name="htmlpp_asset_ref" value="<?php echo esc_attr( $htmlpp_asset['reference'] ); ?>" />
												<label class="htmlpp-asset-btn">
													<input type="file" name="htmlpp_asset_file" accept=".<?php echo esc_attr( $htmlpp_asset['ext'] ); ?>" />
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
														<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
														<polyline points="17 8 12 3 7 8"/>
														<line x1="12" y1="3" x2="12" y2="15"/>
													</svg>
													<?php esc_html_e( 'Replace', 'html-page-publisher' ); ?>
												</label>
												<button type="submit" class="screen-reader-text"><?php esc_html_e( 'Upload replacement', 'html-page-publisher' ); ?></button>
											</form>
											<form method="post" class="htmlpp-asset-delete" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: image filename */ __( 'Delete "%s"? This cannot be undone.', 'html-page-publisher' ), $htmlpp_asset['name'] ) ); ?>')">
												<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
												<input type="hidden" name="htmlpp_asset_delete" value="<?php echo esc_attr( $slug ); ?>" />
												<input type="hidden" name="htmlpp_asset_ref" value="<?php echo esc_attr( $htmlpp_asset['reference'] ); ?>" />
												<button type="submit" class="htmlpp-asset-btn htmlpp-asset-btn--danger">
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
														<polyline points="3 6 5 6 21 6"/>
														<path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/>
														<path d="M10 11v6M14 11v6"/>
													</svg>
													<?php esc_html_e( 'Delete', 'html-page-publisher' ); ?>
												</button>
											</form>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! $editing_disabled ) : ?>
					<form method="post" enctype="multipart/form-data" class="htmlpp-asset-add" style="margin-top:<?php echo empty( $assets ) ? '4' : '20'; ?>px;">
						<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
						<input type="hidden" name="htmlpp_asset_upload" value="<?php echo esc_attr( $slug ); ?>" />
						<div class="htmlpp-field" style="margin:0;">
							<label class="htmlpp-field__label" for="htmlpp_asset_files"><?php esc_html_e( 'Add files', 'html-page-publisher' ); ?></label>
							<div class="htmlpp-dropzone">
								<input type="file" id="htmlpp_asset_files" name="image_files[]" accept="<?php echo esc_attr( $accept ); ?>" multiple />
								<span class="htmlpp-dropzone__icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
										<circle cx="8.5" cy="8.5" r="1.5"/>
										<polyline points="21 15 16 10 5 21"/>
									</svg>
								</span>
								<span class="htmlpp-dropzone__body">
									<span class="htmlpp-dropzone__title"><?php esc_html_e( 'Click or drag files', 'html-page-publisher' ); ?></span>
									<span class="htmlpp-dropzone__hint"><?php esc_html_e( 'Images, CSS, JS, fonts, video, PDF', 'html-page-publisher' ); ?></span>
								</span>
							</div>
							<p class="htmlpp-field__help"><?php esc_html_e( 'Files go into assets/ and keep their name; a duplicate name gets a numeric suffix. To overwrite an existing file, use Replace above.', 'html-page-publisher' ); ?></p>
						</div>
						<div class="htmlpp-actionbar">
							<button type="submit" class="htmlpp-button-primary">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
									<polyline points="17 8 12 3 7 8"/>
									<line x1="12" y1="3" x2="12" y2="15"/>
								</svg>
								<?php esc_html_e( 'Upload Files', 'html-page-publisher' ); ?>
							</button>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="htmlpp-card" style="margin-top:20px;" id="htmlpp-page-settings">
			<div class="htmlpp-card__header">
				<div>
					<h2 class="htmlpp-card__title"><?php esc_html_e( 'Page Settings', 'html-page-publisher' ); ?></h2>
					<p class="htmlpp-card__subtitle"><?php esc_html_e( 'Visibility, URL and search-engine options for this page.', 'html-page-publisher' ); ?></p>
				</div>
			</div>
			<div class="htmlpp-card__body">
				<form method="post" class="htmlpp-settings-form">
					<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
					<input type="hidden" name="htmlpp_page_settings" value="<?php echo esc_attr( $slug ); ?>" />

					<div class="htmlpp-settings-grid">
						<fieldset class="htmlpp-field">
							<legend class="htmlpp-field__label"><?php esc_html_e( 'Status', 'html-page-publisher' ); ?></legend>
							<label class="htmlpp-radio">
								<input type="radio" name="htmlpp_status" value="published" <?php checked( HTMLPP_Meta::is_public( $meta ) ); ?> />
								<span><?php esc_html_e( 'Published — visible to everyone', 'html-page-publisher' ); ?></span>
							</label>
							<label class="htmlpp-radio">
								<input type="radio" name="htmlpp_status" value="draft" <?php checked( ! HTMLPP_Meta::is_public( $meta ) ); ?> />
								<span><?php esc_html_e( 'Draft — hidden; preview link only', 'html-page-publisher' ); ?></span>
							</label>
						</fieldset>

						<div class="htmlpp-field">
							<label class="htmlpp-field__label" for="htmlpp_path"><?php esc_html_e( 'Custom path (optional)', 'html-page-publisher' ); ?></label>
							<div class="htmlpp-field__control htmlpp-field__control--mono">
								<input type="text" id="htmlpp_path" name="htmlpp_path" value="<?php echo esc_attr( HTMLPP_Meta::HOME === $meta['path'] ? '/' : $meta['path'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. promo, guides/spring, or /', 'html-page-publisher' ); ?>" />
							</div>
							<p class="htmlpp-field__help">
								<?php
								printf(
									/* translators: 1: example URL with custom path, 2: home URL */
									esc_html__( 'Serve this page at %1$s instead of the prefix URL; a previous custom path keeps redirecting here. Enter / to make it the site’s front page (%2$s). Paths used by existing pages, posts and WordPress routes are refused; other rewrite rules are not checked, so pick a path that is free.', 'html-page-publisher' ),
									'<code>' . esc_html( home_url( '/promo/' ) ) . '</code>',
									'<code>' . esc_html( home_url( '/' ) ) . '</code>'
								);
								?>
							</p>
						</div>

						<div class="htmlpp-field">
							<label class="htmlpp-field__label" for="htmlpp_new_slug"><?php esc_html_e( 'Slug', 'html-page-publisher' ); ?></label>
							<div class="htmlpp-field__control htmlpp-field__control--mono">
								<input type="text" id="htmlpp_new_slug" name="htmlpp_new_slug" value="<?php echo esc_attr( $slug ); ?>" pattern="[a-z0-9\-]+" <?php disabled( $editing_disabled ); ?> />
							</div>
							<p class="htmlpp-field__help">
								<?php if ( $editing_disabled ) : ?>
									<?php esc_html_e( 'Renaming is disabled by the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constant.', 'html-page-publisher' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Changing the slug moves the page; the old URL redirects to the new one.', 'html-page-publisher' ); ?>
								<?php endif; ?>
							</p>
						</div>

						<fieldset class="htmlpp-field">
							<legend class="htmlpp-field__label"><?php esc_html_e( 'Search engines & snippets', 'html-page-publisher' ); ?></legend>
							<label class="htmlpp-checkbox">
								<input type="checkbox" name="htmlpp_noindex" value="1" <?php checked( ! empty( $meta['noindex'] ) ); ?> />
								<span><?php esc_html_e( 'Hide from search engines (noindex) and leave out of the sitemap', 'html-page-publisher' ); ?></span>
							</label>
							<label class="htmlpp-checkbox">
								<input type="checkbox" name="htmlpp_no_snippets" value="1" <?php checked( ! empty( $meta['no_snippets'] ) ); ?> />
								<span><?php esc_html_e( 'Don’t add the global head/footer snippets to this page', 'html-page-publisher' ); ?></span>
							</label>
						</fieldset>
					</div>

					<div class="htmlpp-actionbar">
						<button type="submit" class="htmlpp-button-primary">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="20 6 9 17 4 12"/>
							</svg>
							<?php esc_html_e( 'Save Settings', 'html-page-publisher' ); ?>
						</button>
					</div>
				</form>

				<form method="post" class="htmlpp-duplicate-form">
					<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
					<input type="hidden" name="htmlpp_duplicate" value="<?php echo esc_attr( $slug ); ?>" />
					<label class="htmlpp-field__label" for="htmlpp_copy_slug"><?php esc_html_e( 'Duplicate this page', 'html-page-publisher' ); ?></label>
					<div class="htmlpp-inline">
						<div class="htmlpp-field__control htmlpp-field__control--mono">
							<input type="text" id="htmlpp_copy_slug" name="htmlpp_new_slug" value="<?php echo esc_attr( $slug . '-copy' ); ?>" pattern="[a-z0-9\-]+" required />
						</div>
						<button type="submit" class="htmlpp-button htmlpp-button-ghost"><?php esc_html_e( 'Duplicate as draft', 'html-page-publisher' ); ?></button>
					</div>
					<p class="htmlpp-field__help"><?php esc_html_e( 'Copies the HTML and all files to a new draft page.', 'html-page-publisher' ); ?></p>
				</form>
			</div>
		</div>

		<div class="htmlpp-card" style="margin-top:20px;">
			<div class="htmlpp-card__header">
				<div>
					<h2 class="htmlpp-card__title"><?php esc_html_e( 'Version History', 'html-page-publisher' ); ?></h2>
					<p class="htmlpp-card__subtitle"><?php esc_html_e( 'Snapshots taken before each save. Restoring also backs up the current version.', 'html-page-publisher' ); ?></p>
				</div>
				<?php if ( ! empty( $backups ) ) : ?>
					<span class="htmlpp-badge"><?php echo (int) count( $backups ); ?></span>
				<?php endif; ?>
			</div>

			<div class="htmlpp-card__body htmlpp-card__body--flush">
				<?php if ( empty( $backups ) ) : ?>
					<div class="htmlpp-empty">
						<div class="htmlpp-empty__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M3 3v5h5"/>
								<path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/>
								<polyline points="12 7 12 12 15 15"/>
							</svg>
						</div>
						<p class="htmlpp-empty__title"><?php esc_html_e( 'No previous versions yet', 'html-page-publisher' ); ?></p>
						<p class="htmlpp-empty__body"><?php esc_html_e( 'The first time you save, the version you replace is snapshotted here.', 'html-page-publisher' ); ?></p>
					</div>
				<?php else : ?>
					<div class="htmlpp-table-scroll">
					<table class="htmlpp-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Saved', 'html-page-publisher' ); ?></th>
								<th style="width:110px;"><?php esc_html_e( 'Size', 'html-page-publisher' ); ?></th>
								<th style="width:120px;"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $backups as $htmlpp_backup ) : ?>
								<tr>
									<td>
										<span class="htmlpp-slug" style="font-size:13px;">
											<?php echo esc_html( wp_date( 'M j, Y g:i:s a', $htmlpp_backup['modified'] ) ); ?>
										</span>
										<span class="htmlpp-url-pill" style="margin-top:4px;">
											<span class="htmlpp-url-pill__text"><?php echo esc_html( $htmlpp_backup['name'] ); ?></span>
										</span>
									</td>
									<td><?php echo esc_html( size_format( $htmlpp_backup['size'] ) ); ?></td>
									<td>
										<?php if ( $editing_disabled ) : ?>
											<span class="htmlpp-field__help" style="margin:0;"><?php esc_html_e( 'Locked', 'html-page-publisher' ); ?></span>
										<?php else : ?>
											<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Restore this version? The current page will be backed up first.', 'html-page-publisher' ) ); ?>')">
												<?php wp_nonce_field( 'htmlpp_action', 'htmlpp_nonce' ); ?>
												<input type="hidden" name="htmlpp_restore" value="<?php echo esc_attr( $slug ); ?>" />
												<input type="hidden" name="htmlpp_backup" value="<?php echo esc_attr( $htmlpp_backup['name'] ); ?>" />
												<button type="submit" class="htmlpp-restore-btn">
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
														<path d="M3 3v5h5"/>
														<path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/>
													</svg>
													<?php esc_html_e( 'Restore', 'html-page-publisher' ); ?>
												</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div><!-- /.htmlpp-table-scroll -->
				<?php endif; ?>
			</div>
		</div>

	</div><!-- /.htmlpp-content -->
</div>
