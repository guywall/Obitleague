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

	/** Read one value from the page query string (empty when absent). */
	function currentParam( name ) {
		var query = String( window.location.search || '' ).replace( /^\?/, '' );
		var parts = query ? query.split( '&' ) : [];
		for ( var i = 0; i < parts.length; ++i ) {
			var pair = parts[ i ].split( '=' );
			if ( decodeURIComponent( pair[ 0 ] ) === name ) {
				return decodeURIComponent( String( pair[ 1 ] || '' ).replace( /\+/g, ' ' ) );
			}
		}
		return '';
	}

	function fetchStory( itemId ) {
		var payload = new window.FormData();
		payload.append( 'action', 'obitleague_death_wire_story_detail' );
		payload.append( 'nonce', cfg.nonce || '' );
		payload.append( 'item', String( itemId ) );
		// Carry the editor's tab and state filter through so the modal's own
		// actions return them to the list they opened the story from.
		payload.append( 'tab', currentParam( 'tab' ) );
		payload.append( 'state', currentParam( 'state' ) );

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

	/* ------------------------------ pairing widget ------------------ */

	/**
	 * Wikidata pairing widget inside the story modal.
	 *
	 * The widget HTML is injected via setContent(), so all interactions are
	 * delegated from the document. Editor searches Wikidata (pre-filled with
	 * the headline name group), picks a result, then confirms to create the
	 * record or clears to dismiss.
	 */
	function pairFindWrapper( target ) {
		var wrapper = target.closest && target.closest( '.ob-dw-modal__pairing' );
		return wrapper || null;
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		var searchBtn = target.closest && target.closest( '.ob-dw-pair__search' );
		if ( searchBtn && pairFindWrapper( searchBtn ) ) {
			event.preventDefault();
			pairSearch( pairFindWrapper( searchBtn ) );
			return;
		}

		var resultItem = target.closest && target.closest( '.ob-dw-pair__result' );
		if ( resultItem ) {
			var wr = pairFindWrapper( resultItem );
			if ( wr ) {
				event.preventDefault();
				pairSelectResult( resultItem, wr );
			}
			return;
		}

		var confirmBtn = target.closest && target.closest( '.ob-dw-pair__confirm' );
		if ( confirmBtn ) {
			var wc = pairFindWrapper( confirmBtn );
			if ( wc ) {
				event.preventDefault();
				pairConfirm( wc );
			}
			return;
		}

		var clearBtn = target.closest && target.closest( '.ob-dw-pair__clear' );
		if ( clearBtn ) {
			var wd = pairFindWrapper( clearBtn );
			if ( wd ) {
				event.preventDefault();
				pairClear( wd );
			}
			return;
		}
	} );

	function pairSearch( wrapper ) {
		var termInput = wrapper.querySelector( '.ob-dw-pair__term' );
		var resultsEl = wrapper.querySelector( '.ob-dw-pair__results' );
		if ( ! termInput || ! resultsEl ) {
			return;
		}
		var term = termInput.value.trim();
		if ( term.length < 2 ) {
			resultsEl.innerHTML = '<p class="description">Enter at least 2 characters.</p>';
			resultsEl.style.display = 'block';
			return;
		}
		resultsEl.style.display = 'block';
		resultsEl.innerHTML = '<p class="ob-dw-pair__loading">Searching…</p>';

		var payload = new window.FormData();
		payload.append( 'action', 'obitleague_death_wire_search_wikidata' );
		payload.append( 'nonce', cfg.searchNonce || '' );
		payload.append( 'term', term );

		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: payload
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( json && json.success && json.data && Array.isArray( json.data.results ) ) {
				pairRenderResults( json.data.results, wrapper );
			} else {
				var msg = ( json && json.data && json.data.message ) || 'No results found.';
				resultsEl.innerHTML = '<p class="ob-dw-pair__error">' + msg + '</p>';
			}
		} ).catch( function () {
			resultsEl.innerHTML = '<p class="ob-dw-pair__error">Search failed. Please try again.</p>';
		} );
	}

	function pairRenderResults( results, wrapper ) {
		var resultsEl = wrapper.querySelector( '.ob-dw-pair__results' );
		if ( ! resultsEl ) {
			return;
		}
		if ( ! results.length ) {
			resultsEl.innerHTML = '<p class="description">No results found. Try another search.</p>';
			return;
		}
		var html = '<ul class="ob-dw-pair__list">';
		for ( var i = 0; i < results.length; ++i ) {
			var r = results[i];
			var qid = escapeHtml( r.qid || '' );
			html += '<li class="ob-dw-pair__result" data-qid="' + qid + '">';
			html += '<strong class="ob-dw-pair__rname">' + escapeHtml( r.name || qid ) + '</strong>';
			html += '<span class="ob-dw-pair__rdesc">' + escapeHtml( r.description || '' ) + '</span>';
			var infoParts = [];
			if ( r.birth ) {
				infoParts.push( 'b. ' + escapeHtml( r.birth ) );
			}
			if ( r.death ) {
				infoParts.push( 'd. ' + escapeHtml( r.death ) );
			}
			if ( r.enwiki ) {
				infoParts.push( '<a href="https://en.wikipedia.org/wiki/' + encodeURIComponent( r.enwiki ) + '" target="_blank" rel="noopener">enwiki</a>' );
			}
			if ( infoParts.length ) {
				html += '<span class="ob-dw-pair__rinfo">' + infoParts.join( ' · ' ) + '</span>';
			}
			html += '</li>';
		}
		html += '</ul>';
		resultsEl.innerHTML = html;
	}

	function pairSelectResult( item, wrapper ) {
		var list = wrapper.querySelector( '.ob-dw-pair__list' );
		if ( list ) {
			var all = list.querySelectorAll( '.ob-dw-pair__result' );
			for ( var i = 0; i < all.length; ++i ) {
				all[i].classList.remove( 'is-selected' );
			}
		}
		item.classList.add( 'is-selected' );

		var qid = item.getAttribute( 'data-qid' ) || '';
		var name = '';
		var desc = '';
		var infoHtml = '';
		var nameEl = item.querySelector( '.ob-dw-pair__rname' );
		var descEl = item.querySelector( '.ob-dw-pair__rdesc' );
		var infoEl = item.querySelector( '.ob-dw-pair__rinfo' );
		if ( nameEl ) { name = nameEl.textContent; }
		if ( descEl ) { desc = descEl.textContent; }
		if ( infoEl ) { infoHtml = infoEl.innerHTML; }

		var selectedEl = wrapper.querySelector( '.ob-dw-pair__selected' );
		if ( ! selectedEl ) {
			return;
		}
		selectedEl.setAttribute( 'data-qid', qid );
		selectedEl.innerHTML = '';
		var strong = document.createElement( 'strong' );
		strong.textContent = name || qid;
		selectedEl.appendChild( strong );
		if ( desc ) {
			var dash = document.createTextNode( ' — ' );
			selectedEl.appendChild( dash );
			var span = document.createElement( 'span' );
			span.textContent = desc;
			selectedEl.appendChild( span );
		}
		if ( infoHtml ) {
			var info = document.createElement( 'span' );
			info.className = 'ob-dw-pair__rinfo';
			info.innerHTML = infoHtml;
			selectedEl.appendChild( info );
		}
		selectedEl.style.display = 'block';

		var actionsEl = wrapper.querySelector( '.ob-dw-pair__actions' );
		if ( actionsEl ) {
			actionsEl.style.display = 'block';
		}
	}

	function pairClear( wrapper ) {
		var resultsEl = wrapper.querySelector( '.ob-dw-pair__results' );
		var selectedEl = wrapper.querySelector( '.ob-dw-pair__selected' );
		var actionsEl = wrapper.querySelector( '.ob-dw-pair__actions' );
		if ( selectedEl ) {
			selectedEl.innerHTML = '';
			selectedEl.removeAttribute( 'data-qid' );
			selectedEl.style.display = 'none';
		}
		if ( actionsEl ) {
			actionsEl.style.display = 'none';
		}
		if ( resultsEl ) {
			resultsEl.style.display = 'none';
		}
		var list = wrapper.querySelector( '.ob-dw-pair__list' );
		if ( list ) {
			var items = list.querySelectorAll( '.ob-dw-pair__result' );
			for ( var i = 0; i < items.length; ++i ) {
				items[i].classList.remove( 'is-selected' );
			}
		}
	}

	function pairConfirm( wrapper ) {
		var selectedEl = wrapper.querySelector( '.ob-dw-pair__selected' );
		var qid = selectedEl ? selectedEl.getAttribute( 'data-qid' ) : '';
		var item = wrapper.getAttribute( 'data-item' ) || '';
		if ( ! qid || ! item ) {
			window.alert( 'Please select a person from the search results first.' );
			return;
		}
		// The server handler redirects after pairing, so submit a real form
		// POST: the browser follows the redirect and the modal closes with
		// the page reload.
		var form = document.createElement( 'form' );
		form.method = 'POST';
		form.action = cfg.ajaxUrl;
		form.style.display = 'none';
		var addInput = function ( name, value ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.appendChild( input );
		};
		addInput( 'action', 'obitleague_death_wire_pair_story' );
		addInput( '_wpnonce', cfg.pairNonce || '' );
		addInput( 'item', item );
		addInput( 'qid', qid );
		addInput( 'tab', currentParam( 'tab' ) );
		addInput( 'state', currentParam( 'state' ) );
		document.body.appendChild( form );
		form.submit();
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

} )();
