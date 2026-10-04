/**
 * Obituaries view switch: list (default) or grid.
 *
 * The server renders the list, so a stored grid preference is the only thing
 * this script has to flip. The choice is kept in the browser so it follows the
 * reader across pages without touching the database.
 */
(function () {
	'use strict';

	var KEY = 'obDeathsView';

	function readView() {
		try {
			return window.localStorage.getItem(KEY) === 'grid' ? 'grid' : 'list';
		} catch (e) {
			return 'list';
		}
	}

	function writeView(view) {
		try {
			window.localStorage.setItem(KEY, view);
		} catch (e) {
			// Private mode or storage disabled: the switch still works for
			// this page, it just cannot be remembered.
		}
	}

	function each(list, fn) {
		Array.prototype.forEach.call(list, fn);
	}

	function apply(view) {
		each(document.querySelectorAll('[data-ob-obituaries]'), function (el) {
			el.classList.toggle('ob-deaths-index--list', view !== 'grid');
			el.classList.toggle('ob-deaths-index--grid', view === 'grid');
		});
		each(document.querySelectorAll('[data-ob-view]'), function (btn) {
			btn.setAttribute('aria-pressed', btn.getAttribute('data-ob-view') === view ? 'true' : 'false');
		});
	}

	function init() {
		var view = readView();
		apply(view);
		each(document.querySelectorAll('[data-ob-view]'), function (btn) {
			btn.addEventListener('click', function () {
				var next = btn.getAttribute('data-ob-view') === 'grid' ? 'grid' : 'list';
				writeView(next);
				apply(next);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
