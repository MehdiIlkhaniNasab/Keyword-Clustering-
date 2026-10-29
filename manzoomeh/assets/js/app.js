/* Manzoomeh (منظومه) — app behaviour. No build step, no dependencies. */
( function () {
	'use strict';

	var M = window.MZM || {};
	var $ = function ( s, el ) { return ( el || document ).querySelector( s ); };
	var $$ = function ( s, el ) { return Array.prototype.slice.call( ( el || document ).querySelectorAll( s ) ); };
	var fa = function ( n ) { return String( n ).replace( /\d/g, function ( d ) { return '۰۱۲۳۴۵۶۷۸۹'[ d ]; } ); };

	function toast( msg, bad ) {
		var t = $( '#mzm-toast' );
		if ( ! t ) { return; }
		t.textContent = msg;
		t.className = 'mzm-toast is-show' + ( bad ? ' is-bad' : '' );
		clearTimeout( t._h );
		t._h = setTimeout( function () { t.className = 'mzm-toast'; }, bad ? 7000 : 3500 );
	}

	function api( path, body, isForm ) {
		var opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': M.nonce } };
		if ( isForm ) {
			opt.body = body;
		} else {
			opt.headers[ 'Content-Type' ] = 'application/json';
			opt.body = JSON.stringify( body || {} );
		}
		return fetch( M.rest + path, opt ).then( function ( r ) {
			return r.json().catch( function () { return { message: 'پاسخ نامعتبر از سرور (HTTP ' + r.status + ')' }; } ).then( function ( d ) {
				if ( ! r.ok ) { throw new Error( d && d.message ? d.message : 'HTTP ' + r.status ); }
				return d;
			} );
		} );
	}

	function reloadKeepScroll() {
		try { sessionStorage.setItem( 'mzm_scroll', String( window.scrollY ) ); } catch ( e ) {}
		location.reload();
	}
	try {
		var sy = sessionStorage.getItem( 'mzm_scroll' );
		if ( sy ) { sessionStorage.removeItem( 'mzm_scroll' ); window.addEventListener( 'load', function () { window.scrollTo( 0, +sy ); } ); }
	} catch ( e ) {}

	// ---- Navigation (mobile) ----
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-mzm-open-nav]' ) ) { document.body.classList.add( 'nav-open' ); }
		if ( e.target.closest( '[data-mzm-close-nav]' ) ) { document.body.classList.remove( 'nav-open' ); }
		var c = e.target.closest( '[data-mzm-copy]' );
		if ( c && navigator.clipboard ) {
			navigator.clipboard.writeText( c.textContent.trim() ).then( function () { toast( 'کپی شد' ); } );
		}
	} );

	// ---- Conditional fields ----
	function syncToggles() {
		$$( '[data-mzm-when]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-mzm-when' );
			var show = true;
			if ( 'woo' === key ) {
				var s = $( 'select[data-mzm-toggle="woo"]' );
				show = ! s || 'woo_rest' === s.value || 'auto' === s.value;
			} else {
				var parts = key.split( '-' );
				var r = $( 'input[data-mzm-toggle="' + parts[ 0 ] + '"]:checked' );
				show = ! r || r.value === parts[ 1 ];
			}
			el.hidden = ! show;
		} );
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-mzm-toggle]' ) ) { syncToggles(); }
	} );
	syncToggles();

	// ---- Run panel ----
	var run = $( '[data-mzm-run]' );
	function paint( st ) {
		if ( ! run || ! st ) { return; }
		var bar = $( '.mzm-progress i', run );
		if ( bar ) { bar.style.width = ( st.pct || 0 ) + '%'; }
		var lab = $( '[data-mzm-label]', run );
		if ( lab ) { lab.textContent = st.error ? 'خطا: ' + st.error : ( st.done ? 'تمام شد؛ در حال بارگذاری نتایج…' : st.label + '… ' + fa( st.pct ) + '٪' ); }
		var ul = $( '[data-mzm-steps]', run );
		if ( ul && st.steps ) {
			ul.innerHTML = st.steps.map( function ( s ) { return '<li class="is-' + s.state + '"><i></i>' + s.label + '</li>'; } ).join( '' );
		}
		var log = $( '[data-mzm-log]', run );
		if ( log && st.log && st.log.length ) {
			log.hidden = false;
			log.innerHTML = st.log.map( function ( l ) {
				var li = document.createElement( 'li' );
				li.className = 'is-' + l.l;
				li.innerHTML = '<b>' + fa( l.t ) + '</b>';
				li.appendChild( document.createTextNode( l.m ) );
				return li.outerHTML;
			} ).join( '' );
			log.scrollTop = log.scrollHeight;
		}
	}
	function busy( on ) {
		$$( '[data-mzm-start], [data-mzm-upload] button', run ).forEach( function ( b ) { b.disabled = on; } );
	}
	function loop( pid, first ) {
		busy( true );
		var fails = 0;
		var step = function ( p ) {
			p.then( function ( st ) {
				fails = 0;
				paint( st );
				if ( st.error ) { busy( false ); toast( st.error, true ); return; }
				if ( st.done ) {
					setTimeout( function () { location.href = location.href.replace( /[?&]run=1/, '' ).replace( /#run$/, '' ); }, 900 );
					return;
				}
				step( api( 'tick/' + pid ) );
			} ).catch( function ( err ) {
				// A slow host can drop one long request: retry a few times before giving up.
				if ( ++fails <= 4 ) {
					setTimeout( function () { step( api( 'tick/' + pid ) ); }, 2500 * fails );
					return;
				}
				busy( false );
				toast( err.message, true );
			} );
		};
		step( first );
	}
	if ( run ) {
		var pid = run.getAttribute( 'data-mzm-run' );
		run.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-mzm-start]' );
			if ( ! b ) { return; }
			paint( { pct: 1, label: 'شروع', steps: [] } );
			loop( pid, api( 'run/' + pid, { source: b.getAttribute( 'data-mzm-start' ), from: b.getAttribute( 'data-from' ) || 'import' } ) );
		} );
		var up = $( '[data-mzm-upload]', run );
		if ( up ) {
			up.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fd = new FormData( up );
				busy( true );
				api( 'upload/' + pid, fd, true ).then( function ( d ) {
					toast( d.msg );
					loop( pid, api( 'run/' + pid, { source: 'upload' } ) );
				} ).catch( function ( err ) { busy( false ); toast( err.message, true ); } );
			} );
		}
		if ( '1' === run.getAttribute( 'data-running' ) ) {
			loop( pid, api( 'tick/' + pid ) );
		} else if ( /[?&]run=1/.test( location.search ) && run.scrollIntoView ) {
			run.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	// ---- Cluster editor ----
	$$( '[data-mzm-editor]' ).forEach( function ( ed ) {
		var pid = ed.getAttribute( 'data-mzm-editor' );
		var list = [];
		try { list = JSON.parse( ed.getAttribute( 'data-clusters' ) || '[]' ); } catch ( e ) {}
		var edit = function ( body, ok ) {
			return api( 'edit/' + pid, body ).then( function () { toast( ok || 'ذخیره شد' ); reloadKeepScroll(); } ).catch( function ( err ) { toast( err.message, true ); } );
		};
		var pop = null;
		var closePop = function () { if ( pop ) { pop.remove(); pop = null; } };
		document.addEventListener( 'click', function ( e ) { if ( pop && ! e.target.closest( '.mzm-pop' ) && ! e.target.closest( '[data-mzm-move]' ) ) { closePop(); } } );

		ed.addEventListener( 'click', function ( e ) {
			var t;
			if ( ( t = e.target.closest( '[data-mzm-remove]' ) ) ) {
				edit( { op: 'remove', kw: +t.getAttribute( 'data-mzm-remove' ) }, 'کلمه حذف شد' );
			} else if ( ( t = e.target.closest( '[data-mzm-rename]' ) ) ) {
				var h = t.closest( '.mzm-cl' ).querySelector( 'h4' );
				var name = window.prompt( 'نام جدید کلاستر:', h ? h.textContent : '' );
				if ( name ) { edit( { op: 'rename', id: +t.getAttribute( 'data-mzm-rename' ), name: name } ); }
			} else if ( ( t = e.target.closest( '[data-mzm-restore]' ) ) ) {
				edit( { op: 'restore', idx: +t.getAttribute( 'data-mzm-restore' ), to: 'new' }, 'کلمه برگشت و در یک کلاستر جدید قرار گرفت' );
			} else if ( ( t = e.target.closest( '[data-mzm-move]' ) ) ) {
				closePop();
				var kw = +t.getAttribute( 'data-mzm-move' );
				var mine = +t.closest( '.mzm-cl' ).getAttribute( 'data-cl' );
				pop = document.createElement( 'div' );
				pop.className = 'mzm-pop';
				pop.innerHTML = '<input type="search" placeholder="جستجوی کلاستر مقصد…"><ul></ul>';
				document.body.appendChild( pop );
				var r = t.getBoundingClientRect();
				pop.style.top = ( window.scrollY + r.bottom + 6 ) + 'px';
				pop.style.left = Math.max( 8, window.scrollX + r.left - 150 ) + 'px';
				var ul = pop.querySelector( 'ul' );
				var input = pop.querySelector( 'input' );
				var render = function () {
					var q = input.value.trim();
					var items = [ '<li><button type="button" data-to="new"><b>+ کلاستر جدید</b></button></li>' ];
					list.filter( function ( c ) { return c[ 0 ] !== mine && ( ! q || c[ 1 ].indexOf( q ) > -1 || c[ 2 ].indexOf( q ) > -1 ); } ).slice( 0, 60 ).forEach( function ( c ) {
						var b = document.createElement( 'button' );
						b.type = 'button';
						b.setAttribute( 'data-to', c[ 0 ] );
						b.appendChild( document.createTextNode( c[ 1 ] ) );
						var s = document.createElement( 'small' );
						s.className = 'mzm-muted';
						s.textContent = c[ 2 ];
						b.appendChild( s );
						items.push( '<li>' + b.outerHTML + '</li>' );
					} );
					ul.innerHTML = items.join( '' );
				};
				input.addEventListener( 'input', render );
				ul.addEventListener( 'click', function ( ev ) {
					var bt = ev.target.closest( '[data-to]' );
					if ( bt ) { closePop(); edit( { op: 'move', kw: kw, to: bt.getAttribute( 'data-to' ) }, 'کلمه منتقل شد' ); }
				} );
				render();
				input.focus();
			} else if ( e.target.closest( '[data-mzm-merge]' ) ) {
				var ids = picked();
				if ( ids.length > 1 && window.confirm( 'کلاسترهای انتخاب‌شده در «' + nameOf( ids[ 0 ] ) + '» ادغام شوند؟' ) ) {
					edit( { op: 'merge', ids: ids }, 'کلاسترها ادغام شدند' );
				}
			} else if ( e.target.closest( '[data-mzm-unpick]' ) ) {
				$$( '[data-mzm-pick]', ed ).forEach( function ( c ) { c.checked = false; } );
				syncPick();
			}
		} );
		var order = [];
		var picked = function () { return order.slice(); };
		var nameOf = function ( id ) { var c = list.filter( function ( x ) { return x[ 0 ] === id; } )[ 0 ]; return c ? c[ 1 ] : ''; };
		var syncPick = function () {
			order = order.filter( function ( id ) { var c = $( '[data-mzm-pick="' + id + '"]', ed ); return c && c.checked; } );
			$$( '[data-mzm-pick]', ed ).forEach( function ( c ) {
				var id = +c.getAttribute( 'data-mzm-pick' );
				if ( c.checked && order.indexOf( id ) < 0 ) { order.push( id ); }
				c.closest( '.mzm-cl' ).classList.toggle( 'is-picked', c.checked );
			} );
			var bar = $( '[data-mzm-bulk]', ed );
			if ( bar ) { bar.hidden = order.length < 2; var n = $( '[data-mzm-bulk-n]', bar ); if ( n ) { n.textContent = fa( order.length ); } }
		};
		ed.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.matches( '[data-mzm-pick]' ) ) { syncPick(); }
			if ( t.matches( '[data-mzm-page]' ) ) { edit( { op: 'set', id: +t.getAttribute( 'data-mzm-page' ), page: t.value }, 'نوع صفحه تغییر کرد' ); }
		} );
	} );

	// ---- Calendar rebuild ----
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-mzm-calendar]' );
		if ( ! b ) { return; }
		b.disabled = true;
		api( 'calendar/' + b.getAttribute( 'data-mzm-calendar' ) ).then( function ( d ) { toast( d.msg ); reloadKeepScroll(); } ).catch( function ( err ) { b.disabled = false; toast( err.message, true ); } );
	} );

	// ---- Google Sheets ----
	var gs = $( '[data-mzm-gsheet]' );
	if ( gs ) {
		gs.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-mzm-gsheet-go]' );
			if ( ! b ) { return; }
			var pid = gs.getAttribute( 'data-mzm-gsheet' );
			var bar = $( '[data-mzm-gsheet-bar]', gs );
			var msg = $( '[data-mzm-gsheet-msg]', gs );
			b.disabled = true;
			bar.hidden = false;
			msg.textContent = 'در حال آماده‌سازی تب‌ها…';
			var go = function ( first ) {
				api( 'gsheet/' + pid, first ? { restart: 1 } : {} ).then( function ( d ) {
					bar.querySelector( 'i' ).style.width = d.pct + '%';
					msg.textContent = d.done ? 'نوشته شد (' + fa( d.tabs ) + ' تب).' : 'در حال نوشتن… ' + fa( d.pct ) + '٪';
					if ( d.done ) {
						b.disabled = false;
						toast( 'خروجی در گوگل شیت نوشته شد' );
						var a = document.createElement( 'a' );
						a.href = d.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ' باز کردن شیت';
						msg.appendChild( a );
					} else {
						go( false );
					}
				} ).catch( function ( err ) { b.disabled = false; msg.textContent = ''; toast( err.message, true ); } );
			};
			go( true );
		} );
	}

	// ---- Settings tests ----
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-mzm-test]' );
		if ( ! b ) { return; }
		var what = b.getAttribute( 'data-mzm-test' );
		var out = $( '[data-mzm-test-out="' + what + '"]' );
		b.disabled = true;
		out.hidden = false;
		out.textContent = 'در حال تست… (اگر تنظیمات را تغییر داده‌اید، اول ذخیره کنید)';
		api( 'test/' + what ).then( function ( d ) {
			out.textContent = ( d.ok ? '✓ ' : '✗ ' ) + d.msg;
			out.style.color = d.ok ? 'var(--accent-ink)' : 'var(--bad)';
		} ).catch( function ( err ) { out.textContent = '✗ ' + err.message; out.style.color = 'var(--bad)'; } ).then( function () { b.disabled = false; } );
	} );
}() );
