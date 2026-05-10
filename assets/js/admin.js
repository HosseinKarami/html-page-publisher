/**
 * HTML Page Publisher — admin JS.
 *
 * Progressive enhancements:
 *  - Copy-URL buttons
 *  - Dropzone drag & drop for file inputs (falls back to native click)
 *  - Show selected filename inside dropzone
 *  - Reposition WP's Help / Screen Options DOM to sit below the hero
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		relocateScreenMeta();
		initCopyButtons();
		initDropzones();
	} );

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
					if ( titleEl ) titleEl.textContent = input.files.length + ' files selected';
					var total = 0;
					for ( var i = 0; i < input.files.length; i++ ) {
						total += input.files[i].size;
					}
					if ( hintEl ) hintEl.textContent = formatBytes( total ) + ' total';
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
