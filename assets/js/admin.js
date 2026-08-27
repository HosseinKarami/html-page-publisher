/**
 * HTML Page Publisher — admin JS.
 *
 * Progressive enhancements:
 *  - Copy-URL buttons
 *  - Dropzone drag & drop for file inputs (falls back to native click)
 *  - Show selected filename inside dropzone
 *  - Reposition WP's Help / Screen Options DOM to sit below the hero
 *  - Initialize WP's CodeMirror on the page editor textarea
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		relocateScreenMeta();
		initCopyButtons();
		initDropzones();
		initCodeEditor();
		initAssetReplace();
		initReviewNotice();
	} );

	/**
	 * When the review button is clicked, also record "done" in the
	 * background so the notice is not shown again.
	 */
	function initReviewNotice() {
		function ping( url ) {
			if ( url && window.fetch ) {
				fetch( url, { credentials: 'same-origin', redirect: 'manual' } ).catch( function () {} );
			}
		}
		function removeNotice( notice ) {
			if ( ! notice ) {
				return;
			}
			// Move focus somewhere sensible before the focused element goes away.
			var heading = document.querySelector( '.htmlpp-hero__title' );
			if ( heading ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus();
			}
			notice.remove();
		}
		document.querySelectorAll( '[data-htmlpp-dismiss]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function () {
				ping( link.getAttribute( 'data-htmlpp-dismiss' ) );
				removeNotice( link.closest( '.htmlpp-review-notice' ) );
			} );
		} );
		// WordPress injects the "X" (.notice-dismiss) after DOMContentLoaded,
		// so listen by delegation. Treat the X as "maybe later".
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.htmlpp-review-notice .notice-dismiss' ) : null;
			if ( ! btn ) {
				return;
			}
			var notice = btn.closest( '.htmlpp-review-notice' );
			ping( notice ? notice.getAttribute( 'data-htmlpp-later' ) : '' );
		} );
	}

	/**
	 * Submit the per-asset Replace form as soon as a file is chosen, so it's
	 * a single click. A visually-hidden submit button is the no-JS fallback.
	 */
	function initAssetReplace() {
		document.querySelectorAll( '.htmlpp-asset-replace input[type="file"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				if ( input.files && input.files.length ) {
					var form = input.closest( 'form' );
					if ( form ) {
						if ( typeof form.requestSubmit === 'function' ) {
							form.requestSubmit();
						} else {
							form.submit();
						}
					}
				}
			} );
		} );
	}

	/**
	 * Upgrade the editor textarea to WordPress' bundled CodeMirror.
	 *
	 * Settings are injected inline by HTMLPP_Admin::enqueue_assets() only on
	 * the editor screen. If they're absent (other screens, or the user
	 * disabled syntax highlighting in their profile) the plain textarea is
	 * left untouched and still works.
	 */
	function initCodeEditor() {
		var textarea = document.getElementById( 'htmlpp-code' );
		if ( ! textarea || textarea.readOnly ) {
			return;
		}
		if ( ! window.wp || ! window.wp.codeEditor || ! window.htmlppCodeEditorSettings ) {
			return;
		}

		// CodeMirror freezes the tab on very large or minified-into-one-line
		// HTML (common for AI exports). A plain <textarea> handles multi-MB
		// content smoothly, so fall back to it past these thresholds.
		var value = textarea.value;
		var MAX_CHARS = 200000;          // ~200 KB total
		var MAX_LINE = 5000;             // any single line this long = minified
		var tooBig = value.length > MAX_CHARS || /[^\n]{5000,}/.test( value );

		if ( tooBig ) {
			var note = document.getElementById( 'htmlpp-bigfile-note' );
			if ( note ) {
				note.hidden = false;
			}
			var initial = value;
			installUnsavedGuard( textarea, function () {
				return textarea.value !== initial;
			} );
			return; // Leave the plain textarea in place.
		}

		var editor = window.wp.codeEditor.initialize( textarea, window.htmlppCodeEditorSettings );
		if ( ! editor || ! editor.codemirror ) {
			return;
		}

		editor.codemirror.markClean();
		installUnsavedGuard( textarea, function () {
			return ! editor.codemirror.isClean();
		} );

		// Flush CodeMirror's buffer back into the textarea before submit.
		// CodeMirror.fromTextArea already hooks this, but doing it explicitly
		// is harmless and guards against detached-form edge cases.
		var form = textarea.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				editor.codemirror.save();
			} );
		}
	}

	/**
	 * Warn before leaving the editor with unsaved changes. Skipped while the
	 * form itself is submitting.
	 *
	 * @param {HTMLTextAreaElement} textarea Editor textarea.
	 * @param {Function}            isDirty  Returns true when there are unsaved edits.
	 */
	function installUnsavedGuard( textarea, isDirty ) {
		var form = textarea.closest( 'form' );
		var submitting = false;
		if ( form ) {
			form.addEventListener( 'submit', function () {
				submitting = true;
			} );
		}
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( submitting || ! isDirty() ) {
				return;
			}
			e.preventDefault();
			// Browsers show their own generic message; the string is a fallback.
			e.returnValue = ( window.htmlppL10n && window.htmlppL10n.unsaved ) || '';
		} );
	}

	/**
	 * Move WP's #screen-meta (Help/Screen Options panel) and
	 * #screen-meta-links (the Help/Screen Options buttons) from above the
	 * .wrap into our hero's sibling slot, so the buttons appear directly
	 * below the indigo hero instead of overlapping its top-right corner.
	 *
	 * Keeps WP's native DOM order (panel before buttons) so the slide-down
	 * behavior keeps working unchanged.
	 */
	function relocateScreenMeta() {
		if ( ! document.body.classList.contains( 'htmlpp-active' ) ) {
			return;
		}
		var wrap = document.querySelector( '.htmlpp-page' );
		if ( ! wrap ) {
			return;
		}
		var hero = wrap.querySelector( '.htmlpp-hero' );
		if ( ! hero ) {
			return;
		}
		var meta = document.getElementById( 'screen-meta' );
		var metaLinks = document.getElementById( 'screen-meta-links' );

		var anchor = hero;
		if ( meta ) {
			anchor.after( meta );
			anchor = meta;
		}
		if ( metaLinks ) {
			anchor.after( metaLinks );
		}

		// Signal the CSS to reveal #screen-meta-links now that it's in
		// the right place. Hidden until this point to avoid a flash + jump.
		document.body.classList.add( 'htmlpp-meta-ready' );
	}

	function initCopyButtons() {
		document.querySelectorAll( '.htmlpp-copy-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var url = btn.getAttribute( 'data-url' );
				if ( ! url ) {
					return;
				}
				var done = function () {
					btn.classList.add( 'is-copied' );
					var original = btn.innerHTML;
					btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
					setTimeout( function () {
						btn.classList.remove( 'is-copied' );
						btn.innerHTML = original;
					}, 1500 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( url ).then( done );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = url;
					document.body.appendChild( ta );
					ta.select();
					try { document.execCommand( 'copy' ); } catch ( _e ) {}
					document.body.removeChild( ta );
					done();
				}
			} );
		} );
	}

	function initDropzones() {
		document.querySelectorAll( '.htmlpp-dropzone' ).forEach( function ( zone ) {
			var input = zone.querySelector( 'input[type="file"]' );
			if ( ! input ) {
				return;
			}
			var titleEl = zone.querySelector( '.htmlpp-dropzone__title' );
			var hintEl = zone.querySelector( '.htmlpp-dropzone__hint' );
			var origTitle = titleEl ? titleEl.textContent : '';
			var origHint = hintEl ? hintEl.textContent : '';

			function render() {
				if ( ! input.files || input.files.length === 0 ) {
					if ( titleEl ) titleEl.textContent = origTitle;
					if ( hintEl ) hintEl.textContent = origHint;
					return;
				}
				if ( input.files.length === 1 ) {
					if ( titleEl ) titleEl.textContent = input.files[0].name;
					if ( hintEl ) hintEl.textContent = formatBytes( input.files[0].size );
				} else {
					var l10n = window.htmlppL10n || {};
					var filesLabel = ( l10n.filesSelected || '%d files selected' ).replace( '%d', input.files.length );
					if ( titleEl ) titleEl.textContent = filesLabel;
					var total = 0;
					for ( var i = 0; i < input.files.length; i++ ) {
						total += input.files[i].size;
					}
					if ( hintEl ) hintEl.textContent = ( l10n.total || '%s total' ).replace( '%s', formatBytes( total ) );
				}
			}

			input.addEventListener( 'change', render );

			[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
				zone.addEventListener( ev, function ( e ) {
					e.preventDefault();
					zone.classList.add( 'is-active' );
				} );
			} );
			[ 'dragleave', 'drop' ].forEach( function ( ev ) {
				zone.addEventListener( ev, function ( e ) {
					e.preventDefault();
					zone.classList.remove( 'is-active' );
				} );
			} );
			zone.addEventListener( 'drop', function ( e ) {
				if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
					input.files = e.dataTransfer.files;
					render();
				}
			} );
		} );
	}

	function formatBytes( bytes ) {
		if ( bytes === 0 ) return '0 B';
		var k = 1024;
		var sizes = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( k ) );
		return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 1 ) ) + ' ' + sizes[i];
	}
} )();
