(function(){
'use strict';

function onHeader(){
	var header = document.querySelector('[data-ob-header]');
	if(!header){ return; }

	var inner  = header.querySelector('.ob-header__inner');
	var toggle = header.querySelector('.ob-header__toggle');
	var panel  = header.querySelector('.ob-header__panel');

	function barHeight(){
		return inner ? inner.offsetHeight : 66;
	}

	function syncHeight(){
		header.style.setProperty('--ob-header-h', barHeight() + 'px');
	}

	function setOpen(open){
		header.classList.toggle('is-open', open);
		if(toggle){ toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		document.body.classList.toggle('ob-menu-open', open);
		if(open){ syncHeight(); }
	}

	if(toggle){
		toggle.addEventListener('click', function(){
			setOpen(!header.classList.contains('is-open'));
		});
	}

	// Mega items: chevron is a real toggle (the accordion on phones); on
	// desktop the hover/focus CSS does the work and these are no-ops.
	var items = header.querySelectorAll('.ob-header__item');
	for(var i=0;i<items.length;i++){
		(function(item){
			var sub  = item.querySelector('[data-ob-mega]');
			if(!sub){ return; }

			var chev = item.querySelector('.ob-header__chev');
			if(chev){
				chev.addEventListener('click', function(){
					var open = item.classList.toggle('is-open');
					chev.setAttribute('aria-expanded', open ? 'true' : 'false');
					if(window.innerWidth < 1024){ syncHeight(); }
				});
			}

			function hoverOpen(){
				if(window.matchMedia('(min-width: 1024px)').matches){
					item.classList.add('is-open');
				}
			}
			function hoverClose(){
				if(window.matchMedia('(min-width: 1024px)').matches){
					item.classList.remove('is-open');
				}
			}

			item.addEventListener('mouseenter', hoverOpen);
			item.addEventListener('focusin', hoverOpen);
			item.addEventListener('mouseleave', hoverClose);
			item.addEventListener('focusout', hoverClose);
			sub.addEventListener('mouseenter', hoverOpen);
			sub.addEventListener('mouseleave', hoverClose);
		})(items[i]);
	}

	// Collapsing search: the magnifier expands the field. Progressive
	// enhancement — without .ob-search-ready the field stays visible.
	var search = header.querySelector('[data-ob-search]');
	if(search){
		search.classList.add('ob-search-ready');
		var searchToggle = search.querySelector('.ob-header__search-toggle');
		var searchInput  = search.querySelector('input[type="search"]');

		var setSearchOpen = function(open){
			search.classList.toggle('is-open', open);
			if(searchToggle){ searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			if(open && searchInput){
				// The field is visibility:hidden until .is-open applies, and a
				// hidden element cannot take focus. Wait for the next frame so
				// the opening click lands the caret in the box instead of
				// leaving the user to click it a second time.
				if(window.requestAnimationFrame){
					window.requestAnimationFrame(function(){ searchInput.focus(); });
				} else {
					searchInput.focus();
				}
			}
		};

		if(searchToggle){
			searchToggle.addEventListener('click', function(event){
				event.stopPropagation();
				setSearchOpen(!search.classList.contains('is-open'));
			});
		}

		document.addEventListener('click', function(event){
			if(search.classList.contains('is-open') && !search.contains(event.target)){
				setSearchOpen(false);
			}
		});

		document.addEventListener('keydown', function(event){
			if(event.key === 'Escape' && search.classList.contains('is-open')){
				setSearchOpen(false);
				if(searchToggle){ searchToggle.focus(); }
			}
		});
	}

	// Close after choosing a destination, and on Escape.
	if(panel){
		panel.addEventListener('click', function(event){
			if(event.target.closest('a')){ setOpen(false); }
		});
	}
	document.addEventListener('keydown', function(event){
		if(event.key === 'Escape' && header.classList.contains('is-open')){
			setOpen(false);
			if(toggle){ toggle.focus(); }
		}
	});

	window.addEventListener('resize', function(){
		if(window.innerWidth >= 1024){
			setOpen(false);
			var open = header.querySelectorAll('.ob-header__item.is-open');
			for(var j=0;j<open.length;j++){ open[j].classList.remove('is-open'); }
		} else if(header.classList.contains('is-open')){
			syncHeight();
		}
	});
}

if(document.readyState === 'loading'){
	document.addEventListener('DOMContentLoaded', onHeader);
}else{
	onHeader();
}
})();
