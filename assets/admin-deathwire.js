/**
 * Death wire admin: story modal and tab helpers.
 *
 * The modal fetches a server-rendered story panel (excerpt, mini wordcloud,
 * extracted details, actions) over admin-ajax and shows it in an overlay.
 * No client-side rendering of data the server already formatted.
 */
( function () {
	'use strict';

	var cfg = window.obDeathWire || {};
	var openBtnSel = '.ob-dw__open';

	/* ------------------------------ modal shell ------------------- */

	function ensureModal() {
		var shell = document.getElementById( 'ob-dw-modal' );
		if ( shell ) {
			return shell;
		}
		shell = document.createElement( 'div' );
		shell.id = 'ob-dw-modal';
		shell.className = 'ob-dw-modal';
		shell.innerHTML =
			'<div class="ob-dw-modal__backdrop" data-close="1"></div>' +
			'<div class="ob-dw-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ob-dw-modal-title">' +
			'<button type="button" class="ob-dw-modal__close" data-close="1" aria-label="Close">&times;</button>' +
			'<div class="ob-dw-modal__content"><p class="ob-dw-modal__loading">Loading story…</p></div>' +
			'</div>';
		document.body.appendChild( shell );

		shell.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-close]' ) ) {
				closeModal();
			}
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && shell.classList.contains( 'is-open' ) ) {
				closeModal();
			}
		} );
		return shell;
	}

	function openModal() {
		var shell = ensureModal();
		shell.classList.add( 'is-open' );
		document.body.classList.add( 'ob-dw-modal-open' );
	}

	function closeModal() {
		var shell = document.getElementById( 'ob-dw-modal' );
		if ( ! shell ) {
			return;
		}
		shell.classList.remove( 'is-open' );
		document.body.classList.remove( 'ob-dw-modal-open' );
	}

	function setLoading( message ) {
		var shell = ensureModal();
		var body = shell.querySelector( '.ob-dw-modal__content' );
		if ( body ) {
			body.innerHTML = '<p class="ob-dw-modal__loading">' + ( message || 'Loading story…' ) + '</p>';
		}
	}

	function setContent( html ) {
		var shell = ensureModal();
		var body = shell.querySelector( '.ob-dw-modal__content' );
		if ( body ) {
			body.innerHTML = html;
		}
	}

	/* ------------------------------ data -------------------------- */

	function fetchStory( itemId ) {
		var payload = new window.FormData();
		payload.append( 'action', 'obitleague_death_wire_story_detail' );
		payload.append( 'nonce', cfg.nonce || '' );
		payload.append( 'item', String( itemId ) );

		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: payload
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function openStory( itemId ) {
		openModal();
		setLoading( 'Loading story #' + itemId + '…' );
		fetchStory( itemId ).then( function ( json ) {
			if ( json && json.success && json.data && json.data.html ) {
				setContent( json.data.html );
				return;
			}
			setContent( '<p class="ob-dw-modal__error">' +
				( ( json && json.data && json.data.message ) || 'The story could not be loaded.' ) +
				'</p>' );
		} ).catch( function () {
			setContent( '<p class="ob-dw-modal__error">The story could not be loaded.</p>' );
		} );
	}

	/* ------------------------------ wiring ------------------------ */

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( openBtnSel );
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var itemId = parseInt( btn.getAttribute( 'data-item' ), 10 );
		if ( itemId > 0 ) {
			openStory( itemId );
		}
	} );

	// After a modal action POSTs and the page reloads, the modal is gone —
	// that is the normal flow. Keep the wire state chips readable.
	if ( document.querySelector( '.ob-dw__storytable' ) ) {
		// Nothing further yet; hooks stay here for follow-up behaviour.
	}
} )();
