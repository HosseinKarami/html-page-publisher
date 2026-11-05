/**
 * HTML Page Publisher — admin JS.
 *
 * Progressive enhancements:
 *  - Copy-URL buttons
 *  - Dropzone drag & drop for file inputs (falls back to native click)
 *  - Show selected filename inside dropzone
 *  - Reposition WP's Help / Screen Options DOM to sit below the hero
 *  - Initialize WP's CodeMirror on the page editor textarea
 *  - Settings → AI assistants: app picker, password, setup text, copy
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
		initSearch();
		initCopy();
		initAppPicker();
		initConnect();
	} );

	/**
	 * Copy buttons: data-htmlpp-copy="#selector" copies that element's text,
	 * data-htmlpp-copy-text copies the attribute's value.
	 */
	function initCopy() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-htmlpp-copy], [data-htmlpp-copy-text]' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			var text = btn.getAttribute( 'data-htmlpp-copy-text' );
			if ( null === text ) {
				var target = document.querySelector( btn.getAttribute( 'data-htmlpp-copy' ) );
				text = target ? target.textContent.trim() : '';
			}
			if ( ! text ) {
				return;
			}
			copyText( text, function () {
				var label = btn.querySelector( '.htmlpp-copy__label' );
				var original = label ? label.textContent : '';
				btn.classList.add( 'is-copied' );
				if ( label ) {
					label.textContent = ( window.htmlppL10n && window.htmlppL10n.copied ) || 'Copied';
				}
				setTimeout( function () {
					btn.classList.remove( 'is-copied' );
					if ( label ) {
						label.textContent = original;
					}
				}, 1500 );
			} );
		} );
	}

	/**
	 * Write text to the clipboard, with a fallback for older browsers and
	 * non-secure origins.
	 *
	 * @param {string}   text Text to copy.
	 * @param {Function} done Called once copied.
	 */
	function copyText( text, done ) {
		if ( navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, function () {
				fallbackCopy( text );
				done();
			} );
			return;
		}
		fallbackCopy( text );
		done();
	}

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( _e ) {}
		document.body.removeChild( ta );
	}

	/**
	 * AI assistants tab: show only the steps for the chosen app, and
	 * remember the choice in this browser.
	 */
	function initAppPicker() {
		var radios = Array.prototype.slice.call( document.querySelectorAll( 'input[name="htmlpp-app"]' ) );
		if ( ! radios.length ) {
			return;
		}
		var KEY = 'htmlpp-ai-app';
		var saved = null;
		try {
			saved = window.localStorage.getItem( KEY );
		} catch ( _e ) {}
		radios.forEach( function ( radio ) {
			if ( saved && radio.value === saved ) {
				radio.checked = true;
			}
			radio.addEventListener( 'change', function () {
				apply();
				try {
					window.localStorage.setItem( KEY, radio.value );
				} catch ( _e ) {}
			} );
		} );

		function apply() {
			var current = radios.filter( function ( r ) {
				return r.checked;
			} )[0] || radios[0];
			document.querySelectorAll( '[data-apps]' ).forEach( function ( el ) {
				el.hidden = el.getAttribute( 'data-apps' ).split( ' ' ).indexOf( current.value ) === -1;
			} );
			document.querySelectorAll( '[data-htmlpp-app-name]' ).forEach( function ( el ) {
				el.textContent = current.getAttribute( 'data-name' );
			} );
		}
		apply();
	}

	/**
	 * AI assistants tab: create an application password (or take a pasted
	 * one) and fill it into the setup text. The password stays in this page
	 * only; WordPress stores just its hash.
	 */
	function initConnect() {
		var cfg = window.htmlppConnect;
		var create = document.querySelector( '[data-htmlpp-create-password]' );
		var paste = document.querySelector( '[data-htmlpp-paste-password]' );
		var status = document.querySelector( '[data-htmlpp-password-status]' );
		var step = document.querySelector( '[data-htmlpp-password-step]' );
		if ( ! cfg || ( ! create && ! paste ) ) {
			return;
		}
		var tokens = Array.prototype.slice.call( document.querySelectorAll( '[data-htmlpp-token]' ) );
		tokens.forEach( function ( el ) {
			el.setAttribute( 'data-placeholder', el.textContent );
		} );

		function setStatus( text, isError ) {
			if ( status ) {
				status.textContent = text;
				status.classList.toggle( 'is-error', !! isError );
			}
		}

		function fill( password ) {
			var auth = password ? base64( cfg.username + ':' + password ) : '';
			tokens.forEach( function ( el ) {
				var value = 'auth' === el.getAttribute( 'data-htmlpp-token' ) ? auth : password;
				el.textContent = value || el.getAttribute( 'data-placeholder' );
				el.classList.toggle( 'is-filled', !! value );
			} );
			if ( step ) {
				step.classList.toggle( 'is-done', !! password );
			}
		}

		if ( paste ) {
			paste.addEventListener( 'input', function () {
				var value = paste.value.replace( /[^A-Za-z0-9]/g, '' );
				var ok = value.length >= 24;
				fill( ok ? value : '' );
				setStatus( ok ? cfg.pasted : '' );
			} );
		}

		if ( create ) {
			create.addEventListener( 'click', function () {
				var picked = document.querySelector( 'input[name="htmlpp-app"]:checked' );
				var app = picked ? picked.getAttribute( 'data-name' ) : 'AI assistant';
				var now = new Date();
				var pad = function ( n ) {
					return ( n < 10 ? '0' : '' ) + n;
				};
				var stamp = now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() ) + ' ' + pad( now.getHours() ) + ':' + pad( now.getMinutes() );
				var name = cfg.appName.replace( '%1$s', app ).replace( '%2$s', stamp );
				create.disabled = true;
				setStatus( cfg.creating );
				fetch( cfg.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce
					},
					body: JSON.stringify( { name: name } )
				} ).then( function ( res ) {
					return res.json().then( function ( body ) {
						return { ok: res.ok, body: body };
					} );
				} ).then( function ( r ) {
					create.disabled = false;
					if ( ! r.ok || ! r.body || ! r.body.password ) {
						setStatus( cfg.failed + ' ' + ( ( r.body && r.body.message ) || '' ), true );
						return;
					}
					if ( paste ) {
						paste.value = '';
					}
					fill( r.body.password );
					setStatus( cfg.created.replace( '%s', r.body.name || name ) );
				} ).catch( function ( err ) {
					create.disabled = false;
					setStatus( cfg.failed + ' ' + ( err && err.message ? err.message : '' ), true );
				} );
			} );
		}
	}

	/**
	 * Base64 of a UTF-8 string (usernames may contain any character).
	 *
	 * @param {string} str Text.
	 * @return {string} Base64.
	 */
	function base64( str ) {
		var bytes = new TextEncoder().encode( str );
		var bin = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			bin += String.fromCharCode( bytes[ i ] );
		}
		return window.btoa( bin );
	}

	/**
	 * Client-side filter for the pages table.
	 */
	function initSearch() {
		var input = document.querySelector( '[data-htmlpp-search]' );
		if ( ! input ) {
			return;
		}
		var selector = input.getAttribute( 'data-htmlpp-search' );
		var rows = Array.prototype.slice.call( document.querySelectorAll( selector ) );
		var table = rows.length ? rows[0].closest( 'table' ) : null;
		var empty = document.createElement( 'p' );
		empty.className = 'htmlpp-table-empty';
		empty.hidden = true;
		empty.textContent = ( window.htmlppL10n && window.htmlppL10n.noMatches ) || 'No matches.';
		if ( table && table.parentNode ) {
			table.parentNode.appendChild( empty );
		}
		var status = document.getElementById( 'htmlpp-search-status' );
		input.addEventListener( 'input', function () {
			var q = input.value.trim().toLowerCase();
			var shown = 0;
			rows.forEach( function ( row ) {
				// Match on the page cell (slug, title, URL), not on buttons/dates.
				var cell = row.querySelector( 'td' );
				var text = ( cell ? cell.textContent : row.textContent ).toLowerCase();
				var hit = ! q || text.indexOf( q ) !== -1;
				row.hidden = ! hit;
				if ( hit ) {
					shown++;
				}
			} );
			empty.hidden = shown > 0;
			if ( status ) {
				status.textContent = q ? shown + ' / ' + rows.length : '';
			}
		} );
	}

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
