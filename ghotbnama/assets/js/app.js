/* Ghotbnama (قطب‌نما) — app behaviour. No build step, no dependencies. */
( function () {
	'use strict';

	var M = window.QTB || {};
	var $ = function ( s, el ) { return ( el || document ).querySelector( s ); };
	var $$ = function ( s, el ) { return Array.prototype.slice.call( ( el || document ).querySelectorAll( s ) ); };
	var fa = function ( n ) { return String( n ).replace( /\d/g, function ( d ) { return '۰۱۲۳۴۵۶۷۸۹'[ d ]; } ); };

	function toast( msg, bad ) {
		var t = $( '#qtb-toast' );
		if ( ! t ) { return; }
		t.textContent = msg;
		t.className = 'qtb-toast is-show' + ( bad ? ' is-bad' : '' );
		clearTimeout( t._h );
		t._h = setTimeout( function () { t.className = 'qtb-toast'; }, bad ? 7000 : 3500 );
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
		try { sessionStorage.setItem( 'qtb_scroll', String( window.scrollY ) ); } catch ( e ) {}
		location.reload();
	}
	try {
		var sy = sessionStorage.getItem( 'qtb_scroll' );
		if ( sy ) { sessionStorage.removeItem( 'qtb_scroll' ); window.addEventListener( 'load', function () { window.scrollTo( 0, +sy ); } ); }
	} catch ( e ) {}

	// ---- Navigation (mobile) ----
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-qtb-open-nav]' ) ) { document.body.classList.add( 'nav-open' ); }
		if ( e.target.closest( '[data-qtb-close-nav]' ) ) { document.body.classList.remove( 'nav-open' ); }
		var c = e.target.closest( '[data-qtb-copy]' );
		if ( c && navigator.clipboard ) {
			navigator.clipboard.writeText( c.textContent.trim() ).then( function () { toast( 'کپی شد' ); } );
		}
	} );

	// ---- Conditional fields ----
	function syncToggles() {
		$$( '[data-qtb-when]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-qtb-when' );
			var show = true;
			if ( 'woo' === key ) {
				var s = $( 'select[data-qtb-toggle="woo"]' );
				show = ! s || 'woo_rest' === s.value || 'auto' === s.value;
			} else {
				var parts = key.split( '-' );
				var r = $( 'input[data-qtb-toggle="' + parts[ 0 ] + '"]:checked' );
				show = ! r || r.value === parts[ 1 ];
			}
			el.hidden = ! show;
		} );
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-qtb-toggle]' ) ) { syncToggles(); }
	} );
	syncToggles();

	// ---- Run panel ----
	var run = $( '[data-qtb-run]' );
	function paint( st, box ) {
		box = box || run;
		if ( ! box || ! st ) { return; }
		var bar = $( '.qtb-progress i', box );
		if ( bar ) { bar.style.width = ( st.pct || 0 ) + '%'; }
		var lab = $( '[data-qtb-label]', box );
		if ( lab ) { lab.textContent = st.error ? 'خطا: ' + st.error : ( st.done ? 'تمام شد؛ در حال بارگذاری نتایج…' : ( st.paused ? 'متوقف شده: ' + st.label : st.label + '… ' + fa( st.pct ) + '٪' ) ); }
		var ctl = $( '[data-qtb-ctl]', box );
		if ( ctl ) {
			var live = ! st.done && ! st.error;
			ctl.hidden = ! live;
			var msg = $( '[data-qtb-ctl-msg]', ctl );
			msg.hidden = ! st.paused;
			msg.textContent = st.paused ? st.paused.msg : '';
			$( '[data-act="pause"]', ctl ).hidden = !! st.paused;
			$( '[data-act="resume"]', ctl ).hidden = ! st.paused;
			$( '[data-act="skip"]', ctl ).hidden = ! ( st.paused && ( 'serp' === st.paused.why || 'serp_browser' === st.paused.why ) );
		}
		var ul = $( '[data-qtb-steps]', box );
		if ( ul && st.steps ) {
			ul.innerHTML = st.steps.map( function ( s ) { return '<li class="is-' + s.state + '"><i></i>' + s.label + '</li>'; } ).join( '' );
		}
		var log = $( '[data-qtb-log]', box );
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
		$$( '[data-qtb-start], [data-qtb-upload] button', run ).forEach( function ( b ) { b.disabled = on; } );
	}
	function loop( pid, first ) {
		busy( true );
		var fails = 0;
		var step = function ( p ) {
			p.then( function ( st ) {
				fails = 0;
				paint( st );
				if ( st.error ) { busy( false ); toast( st.error, true ); return; }
				if ( st.paused ) { busy( false ); toast( st.paused.msg, 'serp' === st.paused.why ); return; }
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
		var pid = run.getAttribute( 'data-qtb-run' );
		run.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-qtb-start]' );
			if ( ! b ) { return; }
			paint( { pct: 1, label: 'شروع', steps: [] } );
			loop( pid, api( 'run/' + pid, { source: b.getAttribute( 'data-qtb-start' ), from: b.getAttribute( 'data-from' ) || 'import' } ) );
		} );
		var up = $( '[data-qtb-upload]', run );
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
		$$( '[data-qtb-ctl] [data-act]', run ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var act = b.getAttribute( 'data-act' );
				if ( 'cancel' === act && ! window.confirm( 'اجرا لغو شود؟ نتیجه قبلی پروژه دست نمی‌خورد.' ) ) { return; }
				b.disabled = true;
				api( 'control/' + pid, { action: act } ).then( function ( st ) {
					paint( st );
					if ( 'resume' === act || 'skip' === act ) { loop( pid, api( 'tick/' + pid ) ); }
					if ( 'pause' === act ) { toast( 'در پایان همین بخش (حداکثر حدود ۲۰ ثانیه) متوقف می‌شود.' ); }
					if ( 'cancel' === act ) { busy( false ); }
				} ).catch( function ( err ) { toast( err.message, true ); } ).then( function () { b.disabled = false; } );
			} );
		} );
		if ( '1' === run.getAttribute( 'data-running' ) ) {
			loop( pid, api( 'tick/' + pid ) );
		} else if ( /[?&]run=1/.test( location.search ) && run.scrollIntoView ) {
			run.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	// ---- Cluster editor ----
	$$( '[data-qtb-editor]' ).forEach( function ( ed ) {
		var pid = ed.getAttribute( 'data-qtb-editor' );
		var list = [];
		try { list = JSON.parse( ed.getAttribute( 'data-clusters' ) || '[]' ); } catch ( e ) {}
		var edit = function ( body, ok ) {
			return api( 'edit/' + pid, body ).then( function () { toast( ok || 'ذخیره شد' ); reloadKeepScroll(); } ).catch( function ( err ) { toast( err.message, true ); } );
		};
		var pop = null;
		var closePop = function () { if ( pop ) { pop.remove(); pop = null; } };
		document.addEventListener( 'click', function ( e ) { if ( pop && ! e.target.closest( '.qtb-pop' ) && ! e.target.closest( '[data-qtb-move]' ) ) { closePop(); } } );

		ed.addEventListener( 'click', function ( e ) {
			var t;
			if ( ( t = e.target.closest( '[data-qtb-remove]' ) ) ) {
				edit( { op: 'remove', kw: +t.getAttribute( 'data-qtb-remove' ) }, 'کلمه حذف شد' );
			} else if ( ( t = e.target.closest( '[data-qtb-rename]' ) ) ) {
				var h = t.closest( '.qtb-cl' ).querySelector( 'h4' );
				var name = window.prompt( 'نام جدید کلاستر:', h ? h.textContent : '' );
				if ( name ) { edit( { op: 'rename', id: +t.getAttribute( 'data-qtb-rename' ), name: name } ); }
			} else if ( ( t = e.target.closest( '[data-qtb-restore]' ) ) ) {
				edit( { op: 'restore', idx: +t.getAttribute( 'data-qtb-restore' ), to: 'new' }, 'کلمه برگشت و در یک کلاستر جدید قرار گرفت' );
			} else if ( ( t = e.target.closest( '[data-qtb-move]' ) ) ) {
				closePop();
				var kw = +t.getAttribute( 'data-qtb-move' );
				var mine = +t.closest( '.qtb-cl' ).getAttribute( 'data-cl' );
				pop = document.createElement( 'div' );
				pop.className = 'qtb-pop';
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
						s.className = 'qtb-muted';
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
			} else if ( e.target.closest( '[data-qtb-merge]' ) ) {
				var ids = picked();
				if ( ids.length > 1 && window.confirm( 'کلاسترهای انتخاب‌شده در «' + nameOf( ids[ 0 ] ) + '» ادغام شوند؟' ) ) {
					edit( { op: 'merge', ids: ids }, 'کلاسترها ادغام شدند' );
				}
			} else if ( e.target.closest( '[data-qtb-unpick]' ) ) {
				$$( '[data-qtb-pick]', ed ).forEach( function ( c ) { c.checked = false; } );
				syncPick();
			}
		} );
		var order = [];
		var picked = function () { return order.slice(); };
		var nameOf = function ( id ) { var c = list.filter( function ( x ) { return x[ 0 ] === id; } )[ 0 ]; return c ? c[ 1 ] : ''; };
		var syncPick = function () {
			order = order.filter( function ( id ) { var c = $( '[data-qtb-pick="' + id + '"]', ed ); return c && c.checked; } );
			$$( '[data-qtb-pick]', ed ).forEach( function ( c ) {
				var id = +c.getAttribute( 'data-qtb-pick' );
				if ( c.checked && order.indexOf( id ) < 0 ) { order.push( id ); }
				c.closest( '.qtb-cl' ).classList.toggle( 'is-picked', c.checked );
			} );
			var bar = $( '[data-qtb-bulk]', ed );
			if ( bar ) { bar.hidden = order.length < 2; var n = $( '[data-qtb-bulk-n]', bar ); if ( n ) { n.textContent = fa( order.length ); } }
		};
		ed.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.matches( '[data-qtb-pick]' ) ) { syncPick(); }
			if ( t.matches( '[data-qtb-page]' ) ) { edit( { op: 'set', id: +t.getAttribute( 'data-qtb-page' ), page: t.value }, 'نوع صفحه تغییر کرد' ); }
		} );
	} );

	// ---- Calendar rebuild ----
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-qtb-calendar]' );
		if ( ! b ) { return; }
		b.disabled = true;
		api( 'calendar/' + b.getAttribute( 'data-qtb-calendar' ) ).then( function ( d ) { toast( d.msg ); reloadKeepScroll(); } ).catch( function ( err ) { b.disabled = false; toast( err.message, true ); } );
	} );

	// ---- Google Sheets ----
	var gs = $( '[data-qtb-gsheet]' );
	if ( gs ) {
		gs.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-qtb-gsheet-go]' );
			if ( ! b ) { return; }
			var pid = gs.getAttribute( 'data-qtb-gsheet' );
			var bar = $( '[data-qtb-gsheet-bar]', gs );
			var msg = $( '[data-qtb-gsheet-msg]', gs );
			b.disabled = true;
			bar.hidden = false;
			msg.textContent = 'در حال آماده‌سازی تب‌ها…';
			var go = function ( first ) {
				api( 'gsheet/' + pid, first ? { restart: 1 } : {} ).then( function ( d ) {
					bar.querySelector( 'i' ).style.width = d.pct + '%';
					msg.textContent = d.done ? 'نوشته شد (' + fa( d.tabs ) + ' تب).' : 'در حال نوشتن… ' + fa( d.pct ) + '٪';
					if ( d.done ) {
						b.disabled = false;
						toast( d.errors && d.errors.length ? 'نوشته شد، با هشدار: ' + d.errors[ 0 ] : 'خروجی در گوگل شیت نوشته شد', !! ( d.errors && d.errors.length ) );
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

	// ---- Workshop ----
	var wsj = $( '[data-qtb-wsjob]' );
	var wsPid = wsj ? wsj.getAttribute( 'data-qtb-wsjob' ) : null;
	var wsNames = { triage: 'غربال کلمات', topics: 'تقسیم موضوعی', topic: 'پردازش موضوع', add: 'افزودن کلمات جدید', facets: 'فرهنگ ویژگی‌ها' };
	var kinds = { remove: 'حذف', keep: 'نگه‌داشتن', park: 'پارکینگ', page: 'نوع صفحه', note: 'دستور' };

	function apiGet( path, params ) {
		var q = Object.keys( params || {} ).map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ); } ).join( '&' );
		var url = M.rest + path + ( q ? ( M.rest.indexOf( '?' ) > -1 ? '&' : '?' ) + q : '' );
		return fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': M.nonce } } ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( d ) {
				if ( ! r.ok ) { throw new Error( d && d.message ? d.message : 'HTTP ' + r.status ); }
				return d;
			} );
		} );
	}
	function wsGoto( step, tid ) {
		var u = new URL( location.href );
		[ 'st', 'tr', 'q', 'pg', 'tid' ].forEach( function ( k ) { u.searchParams.delete( k ); } );
		u.searchParams.set( 'ws', step );
		if ( tid ) { u.searchParams.set( 'tid', tid ); }
		location.href = u.toString();
	}
	function wsEdit( body, ok, stay ) {
		return api( 'ws/edit/' + wsPid, body ).then( function ( d ) {
			if ( ok ) { toast( 'function' === typeof ok ? ok( d ) : ok ); }
			if ( ! stay ) { reloadKeepScroll(); }
			return d;
		} ).catch( function ( err ) { toast( err.message, true ); return null; } );
	}
	function wsBusy( on ) {
		$$( '[data-qtb-wsgo], [data-qtb-wsupload] button, [data-qtb-wsadd] button, [data-qtb-finalize]' ).forEach( function ( b ) { b.disabled = on; } );
	}
	function wsLoop( first ) {
		wsj.hidden = false;
		wsj.classList.remove( 'is-done' );
		wsBusy( true );
		var fails = 0;
		var step = function ( p ) {
			p.then( function ( st ) {
				fails = 0;
				var tt = $( '[data-qtb-wsjob-title]', wsj );
				if ( tt ) { tt.textContent = wsNames[ st.action ] || ''; }
				paint( st, wsj );
				if ( st.error ) { wsBusy( false ); toast( st.error, true ); return; }
				if ( st.paused && 'serp_browser' === st.paused.why && st.paused.items ) {
					// The browser route: this page fetches the results, then the step goes on.
					browserSerp( st.paused, wsPid, function ( done, all ) {
						var lab = $( '[data-qtb-label]', wsj );
						if ( lab ) { lab.textContent = 'نتایج گوگل از مرورگر شما: ' + fa( done ) + ' از ' + fa( all ) + ' — این صفحه را باز نگه دارید'; }
					} ).then( function () {
						return api( 'ws/control/' + wsPid, { action: 'resume' } );
					} ).then( function () { step( api( 'ws/tick/' + wsPid ) ); } ).catch( function ( err ) {
						wsBusy( false );
						toast( err.message, true );
					} );
					return;
				}
				if ( st.paused ) { wsBusy( false ); toast( st.paused.msg, 'serp' === st.paused.why ); return; }
				if ( st.done ) {
					setTimeout( function () { wsGoto( 'add' === st.action ? 'topics' : st.action, 'topic' === st.action ? st.tid : 0 ); }, 900 );
					return;
				}
				step( api( 'ws/tick/' + wsPid ) );
			} ).catch( function ( err ) {
				if ( ++fails <= 4 ) {
					setTimeout( function () { step( api( 'ws/tick/' + wsPid ) ); }, 2500 * fails );
					return;
				}
				wsBusy( false );
				toast( err.message, true );
			} );
		};
		step( first );
	}
	if ( wsj ) {
		if ( '1' === wsj.getAttribute( 'data-running' ) ) {
			wsLoop( api( 'ws/tick/' + wsPid ) );
		}
		$$( '[data-qtb-ctl] [data-act]', wsj ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var act = b.getAttribute( 'data-act' );
				if ( 'cancel' === act && ! window.confirm( 'این کار لغو شود؟' ) ) { return; }
				b.disabled = true;
				api( 'ws/control/' + wsPid, { action: act } ).then( function ( st ) {
					paint( st, wsj );
					if ( 'resume' === act || 'skip' === act ) { wsLoop( api( 'ws/tick/' + wsPid ) ); }
					if ( 'pause' === act ) { toast( 'در پایان همین بخش (حداکثر حدود ۲۰ ثانیه) متوقف می‌شود.' ); }
					if ( 'cancel' === act ) { wsBusy( false ); setTimeout( reloadKeepScroll, 800 ); }
				} ).catch( function ( err ) { toast( err.message, true ); } ).then( function () { b.disabled = false; } );
			} );
		} );
		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-qtb-wsgo]' );
			if ( ! b || b.disabled ) { return; }
			if ( b.getAttribute( 'data-confirm' ) && ! window.confirm( b.getAttribute( 'data-confirm' ) ) ) { return; }
			var body = { action: b.getAttribute( 'data-qtb-wsgo' ) };
			if ( b.getAttribute( 'data-source' ) ) { body.source = b.getAttribute( 'data-source' ); }
			if ( b.getAttribute( 'data-fresh' ) ) { body.fresh = 1; }
			if ( b.getAttribute( 'data-tid' ) ) {
				body.tid = +b.getAttribute( 'data-tid' );
				var ms = $( '[data-qtb-tmode="' + body.tid + '"]' );
				if ( ms ) { body.mode = ms.value; }
			}
			paint( { pct: 1, label: 'شروع', steps: [] }, wsj );
			wsj.hidden = false;
			if ( wsj.scrollIntoView ) { wsj.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
			wsLoop( api( 'ws/start/' + wsPid, body ) );
		} );
		$$( '[data-qtb-wsadd]' ).forEach( function ( f ) {
			f.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fd = new FormData( f );
				var file = f.querySelector( 'input[type=file]' );
				if ( ( ! file || ! file.files.length ) && ! String( fd.get( 'text' ) || '' ).trim() ) { toast( 'کلمه‌ای وارد نشده است.', true ); return; }
				wsBusy( true );
				api( 'ws/add/' + wsPid, fd, true ).then( function ( d ) {
					toast( d.msg );
					if ( d.status ) { wsLoop( Promise.resolve( d.status ) ); } else { wsBusy( false ); }
				} ).catch( function ( err ) { wsBusy( false ); toast( err.message, true ); } );
			} );
		} );
		$$( '[data-qtb-wsupload]' ).forEach( function ( f ) {
			f.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				wsBusy( true );
				api( 'upload/' + wsPid, new FormData( f ), true ).then( function ( d ) {
					toast( d.msg );
					wsLoop( api( 'ws/start/' + wsPid, { action: 'triage', source: 'upload' } ) );
				} ).catch( function ( err ) { wsBusy( false ); toast( err.message, true ); } );
			} );
		} );
	}

	// Triage: bulk decisions (shift-click selects a range).
	$$( '[data-qtb-triage]' ).forEach( function ( box ) {
		var last = null;
		var boxes = function () { return $$( '[data-qtb-kw]', box ); };
		var sync = function () {
			var n = boxes().filter( function ( c ) { return c.checked; } ).length;
			var bar = $( '[data-qtb-bulk]', box );
			bar.hidden = ! n;
			$( '[data-qtb-bulk-n]', bar ).textContent = fa( n );
		};
		box.addEventListener( 'click', function ( e ) {
			var c = e.target.closest( '[data-qtb-kw]' );
			if ( c ) {
				var all = boxes();
				if ( e.shiftKey && last ) {
					var a = all.indexOf( last ), z = all.indexOf( c );
					all.slice( Math.min( a, z ), Math.max( a, z ) + 1 ).forEach( function ( x ) { x.checked = c.checked; } );
				}
				last = c;
				sync();
			}
			var s = e.target.closest( '[data-qtb-set]' );
			if ( s ) {
				var ids = boxes().filter( function ( x ) { return x.checked; } ).map( function ( x ) { return +x.getAttribute( 'data-qtb-kw' ); } );
				wsEdit( { op: 'triage', st: s.getAttribute( 'data-qtb-set' ), kws: ids }, function ( d ) { return fa( d.n ) + ' کلمه به‌روز شد'; } );
			}
		} );
		box.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '[data-qtb-checkall]' ) ) {
				boxes().forEach( function ( x ) { x.checked = e.target.checked; } );
				sync();
			}
		} );
	} );

	// Rules.
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-qtb-rule]' );
		if ( b ) {
			wsEdit( { op: 'rule_add', kind: b.getAttribute( 'data-qtb-rule' ), match: b.getAttribute( 'data-match' ) }, function ( d ) { return 'قانون اضافه شد؛ ' + fa( d.n ) + ' کلمه تغییر کرد'; } );
			return;
		}
		b = e.target.closest( '[data-qtb-ruledel]' );
		if ( b && window.confirm( 'این قانون حذف شود؟ (تصمیم‌هایی که قبلاً با آن گرفته شده می‌ماند)' ) ) {
			wsEdit( { op: 'rule_del', id: +b.getAttribute( 'data-qtb-ruledel' ) }, 'قانون حذف شد' );
		}
	} );
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target.closest( '[data-qtb-ruleform]' );
		if ( ! f ) { return; }
		e.preventDefault();
		var body = { op: 'rule_add' };
		new FormData( f ).forEach( function ( v, k ) { body[ k ] = v; } );
		wsEdit( body, function ( d ) { return 'قانون اضافه شد؛ ' + fa( d.n || 0 ) + ' کلمه تغییر کرد'; } );
	} );

	// Topics list.
	$$( '[data-qtb-topics]' ).forEach( function ( box ) {
		var picked = function () { return $$( '[data-qtb-tpick]', box ).filter( function ( c ) { return c.checked; } ).map( function ( c ) { return +c.getAttribute( 'data-qtb-tpick' ); } ); };
		box.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.matches( '[data-qtb-tpick]' ) ) { $( '[data-qtb-topicmerge]', box ).hidden = picked().length < 2; }
			if ( t.matches( '[data-qtb-tmode]' ) ) { wsEdit( { op: 'mode', tid: +t.getAttribute( 'data-qtb-tmode' ), mode: t.value }, 'روش بازبینی ذخیره شد؛ در پردازش بعدی اعمال می‌شود', true ); }
		} );
		box.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-qtb-trename]' );
			if ( b ) {
				var name = window.prompt( 'نام جدید موضوع:', b.getAttribute( 'data-name' ) );
				if ( name ) { wsEdit( { op: 'topic_rename', tid: +b.getAttribute( 'data-qtb-trename' ), name: name }, 'نام موضوع تغییر کرد' ); }
			}
			if ( e.target.closest( '[data-qtb-topicmerge]' ) && window.confirm( 'موضوع‌های انتخاب‌شده در اولی ادغام شوند؟ کلاسترها، قفل‌ها و بخش‌ها حفظ می‌شوند.' ) ) {
				wsEdit( { op: 'topic_merge', ids: picked() }, 'موضوع‌ها ادغام شدند' );
			}
		} );
	} );

	// Review board.
	$$( '[data-qtb-board]' ).forEach( function ( bd ) {
		var tid = +bd.getAttribute( 'data-tid' );
		var cls = [], tps = [];
		var all = [];
		try { cls = JSON.parse( bd.getAttribute( 'data-clusters' ) || '[]' ); tps = JSON.parse( bd.getAttribute( 'data-topics' ) || '[]' ); all = JSON.parse( bd.getAttribute( 'data-all' ) || '[]' ); } catch ( e ) {}
		var dragKw = null;
		var norm = function ( s ) { return String( s ).replace( /ي/g, 'ی' ).replace( /ك/g, 'ک' ).replace( /[\s‌]+/g, '' ).replace( /آ/g, 'ا' ); };
		var liOf = function ( kw ) { return $( 'li[data-kw="' + kw + '"]', bd ); };
		var recount = function ( card ) {
			if ( ! card || ! card.matches( '.qtb-bd-cl' ) ) { return; }
			var lis = $$( 'ul > li[data-kw]', card );
			$( '[data-n]', card ).textContent = fa( lis.length );
			$( '[data-vol]', card ).textContent = fa( lis.reduce( function ( a, l ) { return a + ( +l.getAttribute( 'data-v' ) || 0 ); }, 0 ).toLocaleString( 'en' ) ).replace( /,/g, '٬' );
			card.classList.add( 'is-locked' );
			if ( ! lis.length ) { card.remove(); }
		};
		var move = function ( kw, to ) {
			var li = liOf( kw );
			var from = li ? li.closest( '.qtb-bd-cl' ) : null;
			if ( from && String( to ) === from.getAttribute( 'data-cl' ) ) { return; }
			var target = /^\d+$/.test( String( to ) ) ? $( '.qtb-bd-cl[data-cl="' + to + '"] > ul', bd ) : ( 'park' === to || 'remove' === to ? $( '.qtb-aside.is-' + to, bd ) : null );
			var optimistic = li && target && from;
			if ( optimistic ) {
				var empty = $( '.is-empty', target );
				if ( empty ) { empty.remove(); }
				target.appendChild( li );
				li.classList.add( 'is-moved' );
				recount( from );
				recount( target.closest( '.qtb-bd-cl' ) );
			}
			wsEdit( { op: 'move', tid: tid, kw: kw, to: String( to ) }, 'park' === to ? 'به پارکینگ رفت' : ( 'remove' === to ? 'حذف شد' : 'منتقل شد' ), !! optimistic ).then( function ( d ) {
				if ( ! d && optimistic ) { reloadKeepScroll(); }
			} );
		};

		// A cluster card dragged by its header to another section.
		var dragCl = null;
		bd.addEventListener( 'dragstart', function ( e ) {
			var h = e.target.closest && e.target.closest( '[data-cldrag]' );
			if ( ! h || e.target.closest( 'li[data-kw]' ) ) { return; }
			dragCl = +h.getAttribute( 'data-cldrag' );
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData( 'text/plain', 'cl' + dragCl ); } catch ( x ) {}
			bd.classList.add( 'is-dragging-cl' );
		} );
		bd.addEventListener( 'dragend', function () { dragCl = null; bd.classList.remove( 'is-dragging-cl' ); } );
		bd.addEventListener( 'dragover', function ( e ) {
			var z = null !== dragCl && e.target.closest( '[data-secdrop]' );
			if ( ! z ) { return; }
			e.preventDefault();
			$$( '.qtb-bd-sec.is-over', bd ).forEach( function ( x ) { if ( x !== z ) { x.classList.remove( 'is-over' ); } } );
			z.classList.add( 'is-over' );
		} );
		bd.addEventListener( 'drop', function ( e ) {
			var z = null !== dragCl && e.target.closest( '[data-secdrop]' );
			if ( ! z ) { return; }
			e.preventDefault();
			e.stopPropagation();
			var cid = dragCl;
			dragCl = null;
			var card = $( '.qtb-bd-cl[data-cl="' + cid + '"]', bd );
			if ( card && card.closest( '.qtb-bd-sec' ) === z ) { z.classList.remove( 'is-over' ); return; }
			wsEdit( { op: 'section', tid: tid, cid: cid, section: z.getAttribute( 'data-secdrop' ) }, 'کلاستر به بخش دیگر رفت' );
		}, true );

		// Drag and drop.
		bd.addEventListener( 'dragstart', function ( e ) {
			var li = e.target.closest && e.target.closest( 'li[data-kw]' );
			if ( ! li ) { return; }
			dragKw = +li.getAttribute( 'data-kw' );
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData( 'text/plain', String( dragKw ) ); } catch ( x ) {}
			li.classList.add( 'is-drag' );
			bd.classList.add( 'is-dragging' );
		} );
		bd.addEventListener( 'dragend', function ( e ) {
			var li = e.target.closest && e.target.closest( 'li[data-kw]' );
			if ( li ) { li.classList.remove( 'is-drag' ); }
			bd.classList.remove( 'is-dragging' );
			$$( '.is-over', bd ).forEach( function ( x ) { x.classList.remove( 'is-over' ); } );
		} );
		bd.addEventListener( 'dragover', function ( e ) {
			var z = e.target.closest( '[data-drop]' );
			if ( ! z || null === dragKw ) { return; }
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			if ( ! z.classList.contains( 'is-over' ) ) {
				$$( '.is-over', bd ).forEach( function ( x ) { x.classList.remove( 'is-over' ); } );
				z.classList.add( 'is-over' );
			}
		} );
		bd.addEventListener( 'drop', function ( e ) {
			var z = e.target.closest( '[data-drop]' );
			if ( ! z || null === dragKw ) { return; }
			e.preventDefault();
			z.classList.remove( 'is-over' );
			var kw = dragKw;
			dragKw = null;
			var to = z.getAttribute( 'data-drop' );
			var li = liOf( kw );
			if ( li && ! li.closest( '.qtb-bd-cl' ) && /^\d+$/.test( to ) ) {
				// From the parking / removed list back into a cluster.
				wsEdit( { op: 'move', tid: tid, kw: kw, to: to }, 'کلمه برگشت' );
				return;
			}
			if ( 'new' === to ) {
				wsEdit( { op: 'move', tid: tid, kw: kw, to: 'new' }, 'کلاستر جدید ساخته شد' );
				return;
			}
			move( kw, to );
		} );

		// Move popover.
		var pop = null;
		var closePop = function () { if ( pop ) { pop.remove(); pop = null; } };
		document.addEventListener( 'click', function ( e ) { if ( pop && ! e.target.closest( '.qtb-pop' ) && ! e.target.closest( '[data-qtb-wsmove]' ) && ! e.target.closest( '[data-qtb-clmenu]' ) ) { closePop(); } } );
		var openMove = function ( btn, kw ) {
			closePop();
			var card = btn.closest( '.qtb-bd-cl' );
			var mine = card ? +card.getAttribute( 'data-cl' ) : -1;
			pop = document.createElement( 'div' );
			pop.className = 'qtb-pop';
			pop.innerHTML = '<input type="search" placeholder="جستجوی کلاستر یا موضوع مقصد…"><ul></ul>';
			document.body.appendChild( pop );
			var r = btn.getBoundingClientRect();
			pop.style.top = ( window.scrollY + r.bottom + 6 ) + 'px';
			pop.style.left = Math.max( 8, window.scrollX + r.left - 150 ) + 'px';
			var ul = $( 'ul', pop ), input = $( 'input', pop );
			var item = function ( to, label, sub ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.setAttribute( 'data-to', to );
				b.appendChild( document.createTextNode( label ) );
				if ( sub ) { var s = document.createElement( 'small' ); s.className = 'qtb-muted'; s.textContent = sub; b.appendChild( s ); }
				return '<li>' + b.outerHTML + '</li>';
			};
			var render = function () {
				var q = norm( input.value.trim() );
				var items = q ? [] : [ '<li><button type="button" data-to="new"><b>+ کلاستر جدید</b></button></li>', item( 'park', 'پارکینگ' ), item( 'remove', 'حذف' ) ];
				cls.filter( function ( c ) { return c[ 0 ] !== mine && ( ! q || norm( c[ 1 ] ).indexOf( q ) > -1 ); } ).slice( 0, 60 ).forEach( function ( c ) { items.push( item( c[ 0 ], c[ 1 ], 'کلاستر' ) ); } );
				if ( q ) {
					all.filter( function ( c ) { return norm( c[ 2 ] ).indexOf( q ) > -1 || norm( c[ 3 ] ).indexOf( q ) > -1; } ).slice( 0, 40 ).forEach( function ( c ) { items.push( item( 'c:' + c[ 0 ] + ':' + c[ 1 ], c[ 2 ], 'پیلار ' + c[ 3 ] ) ); } );
				}
				tps.filter( function ( t ) { return ! q || norm( t[ 1 ] ).indexOf( q ) > -1; } ).slice( 0, 30 ).forEach( function ( t ) { items.push( item( 'topic:' + t[ 0 ], t[ 1 ], 'پیلار (بدون کلاستر)' ) ); } );
				ul.innerHTML = items.join( '' );
			};
			input.addEventListener( 'input', render );
			ul.addEventListener( 'click', function ( ev ) {
				var bt = ev.target.closest( '[data-to]' );
				if ( ! bt ) { return; }
				var to = bt.getAttribute( 'data-to' );
				closePop();
				if ( 'new' === to || 0 === to.indexOf( 'topic:' ) || 0 === to.indexOf( 'c:' ) || ! card ) {
					wsEdit( { op: 'move', tid: tid, kw: kw, to: to }, 'منتقل شد' );
				} else {
					move( kw, to );
				}
			} );
			render();
			input.focus();
		};

		// Cluster menu: move to another pillar (and section) or merge into any cluster.
		var openClMenu = function ( btn, cid ) {
			closePop();
			pop = document.createElement( 'div' );
			pop.className = 'qtb-pop is-wide';
			pop.innerHTML = '<div class="qtb-seg qtb-pop-tabs"><button type="button" class="is-active" data-m="move">انتقال به پیلار</button><button type="button" data-m="merge">ادغام با کلاستر</button></div><input type="search" placeholder="جستجو…"><ul></ul>';
			document.body.appendChild( pop );
			var r = btn.getBoundingClientRect();
			pop.style.top = ( window.scrollY + r.bottom + 6 ) + 'px';
			pop.style.left = Math.max( 8, window.scrollX + r.left - 260 ) + 'px';
			var ul = $( 'ul', pop ), input = $( 'input', pop ), mode = 'move';
			var mine = cls.filter( function ( c ) { return c[ 0 ] !== cid; } ).map( function ( c ) { return [ tid, c[ 0 ], c[ 1 ], 'همین پیلار' ]; } );
			var li = function ( attrs, label, sub ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				Object.keys( attrs ).forEach( function ( k ) { b.setAttribute( k, attrs[ k ] ); } );
				b.appendChild( document.createTextNode( label ) );
				if ( sub ) { var s = document.createElement( 'small' ); s.className = 'qtb-muted'; s.textContent = sub; b.appendChild( s ); }
				return '<li>' + b.outerHTML + '</li>';
			};
			var render = function () {
				var q = norm( input.value.trim() );
				var items = [];
				if ( 'move' === mode ) {
					tps.filter( function ( t ) { return ! q || norm( t[ 1 ] ).indexOf( q ) > -1; } ).forEach( function ( t ) { items.push( li( { 'data-tt': t[ 0 ] }, t[ 1 ], 'پیلار' ) ); } );
					items.push( li( { 'data-tt': 'new' }, '+ پیلار جدید…', '' ) );
				} else {
					mine.concat( all ).filter( function ( c ) { return ! q || norm( c[ 2 ] ).indexOf( q ) > -1 || norm( c[ 3 ] ).indexOf( q ) > -1; } ).slice( 0, 60 ).forEach( function ( c ) { items.push( li( { 'data-mt': c[ 0 ], 'data-mc': c[ 1 ] }, c[ 2 ], c[ 3 ] ) ); } );
				}
				ul.innerHTML = items.join( '' ) || '<li class="qtb-muted qtb-small" style="padding:6px">موردی نیست</li>';
			};
			$$( '.qtb-pop-tabs button', pop ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					mode = b.getAttribute( 'data-m' );
					$$( '.qtb-pop-tabs button', pop ).forEach( function ( x ) { x.classList.toggle( 'is-active', x === b ); } );
					render();
					input.focus();
				} );
			} );
			input.addEventListener( 'input', render );
			ul.addEventListener( 'click', function ( ev ) {
				var b = ev.target.closest( 'button' );
				if ( ! b ) { return; }
				var tt = b.getAttribute( 'data-tt' );
				if ( tt ) {
					closePop();
					var go = function ( to ) {
						var sec = ( window.prompt( 'بخش مقصد در آن پیلار (خالی = همان بخش فعلی):', '' ) || '' ).trim();
						wsEdit( { op: 'cluster_move', tid: tid, cid: cid, to_tid: +to, section: sec }, 'کلاستر به پیلار دیگر رفت' );
					};
					if ( 'new' === tt ) {
						var name = ( window.prompt( 'نام پیلار جدید:', '' ) || '' ).trim();
						if ( ! name ) { return; }
						wsEdit( { op: 'topic_new', name: name }, null, true ).then( function ( d ) { if ( d && d.tid ) { go( d.tid ); } } );
					} else {
						go( tt );
					}
					return;
				}
				var mt = b.getAttribute( 'data-mt' );
				if ( mt ) {
					closePop();
					if ( window.confirm( 'این کلاستر در «' + b.firstChild.textContent + '» ادغام شود؟' ) ) {
						wsEdit( { op: 'cluster_merge_into', tid: tid, cid: cid, to_tid: +mt, to_cid: +b.getAttribute( 'data-mc' ) }, 'کلاسترها ادغام شدند' );
					}
				}
			} );
			render();
			input.focus();
		};

		// Evidence drawer.
		var drawer = $( '[data-qtb-drawer]', bd );
		var el = function ( tag, cls, text ) { var x = document.createElement( tag ); if ( cls ) { x.className = cls; } if ( null != text ) { x.textContent = text; } return x; };
		var evidence = function ( kw ) {
			drawer.hidden = false;
			$( '[data-qtb-drawer-title]', drawer ).textContent = 'در حال خواندن…';
			var body = $( '[data-qtb-drawer-body]', drawer );
			body.innerHTML = '';
			apiGet( 'ws/evidence/' + wsPid, { tid: tid, kw: kw } ).then( function ( d ) {
				$( '[data-qtb-drawer-title]', drawer ).textContent = d.k + ' · ' + fa( d.v );
				var m = d.meta || {};
				var conf = { high: 'اطمینان زیاد', mid: 'اطمینان متوسط', low: 'اطمینان کم' };
				var why = { serp: 'اشتراک نتایج گوگل', lexical: 'شباهت معنایی (بدون سرپ)', ai: 'جابه‌جایی هوش مصنوعی', user: 'تصمیم شما', comp: 'رقیب هر دو را با یک صفحه گرفته' };
				if ( m.conf ) { body.appendChild( el( 'p', 'qtb-small', ( conf[ m.conf ] || '' ) + ' · ' + ( why[ m.why ] || '' ) ) ); }
				if ( m.ai && m.ai.reason ) { body.appendChild( el( 'p', 'qtb-note is-ai', 'هوش مصنوعی: ' + m.ai.reason ) ); }
				( d.shared || [] ).forEach( function ( s ) {
					var box = el( 'div', 'qtb-ev-shared' );
					box.appendChild( el( 'b', '', 'مشترک با «' + s.with + '»: ' + fa( s.urls.length ) + ' نتیجه' ) );
					var ul = el( 'ul', 'qtb-small' );
					s.urls.forEach( function ( u ) { ul.appendChild( el( 'li', '', u.url.replace( /^https?:\/\/(www\.)?/, '' ) + '  (#' + u.pa + ' / #' + u.pb + ')' ) ); } );
					box.appendChild( ul );
					body.appendChild( box );
				} );
				if ( d.comp && d.comp.length ) {
					body.appendChild( el( 'h4', '', 'رقبا (سئو سیگنال)' ) );
					var cu = el( 'ul', 'qtb-ev-comp' );
					d.comp.forEach( function ( h ) {
						var li = el( 'li', h.same ? 'is-same' : '' );
						li.appendChild( el( 'b', '', h.domain + ' · جایگاه ' + fa( h.rank ) ) );
						li.appendChild( el( 'small', 'qtb-muted', ' ' + h.type + ( h.same ? ' — همان صفحه‌ای که سر کلاستر را هم دارد' : '' ) ) );
						li.appendChild( el( 'div', 'qtb-ltr qtb-small', h.page ) );
						cu.appendChild( li );
					} );
					body.appendChild( cu );
				}
				if ( ! d.serp.length ) {
					body.appendChild( el( 'p', 'qtb-muted qtb-small', 'نتایج گوگل این کلمه گرفته نشده (سرویس سرپ وصل نیست، حالت رایگان، یا سقف سرپ پروژه).' ) );
					return;
				}
				body.appendChild( el( 'h4', '', '۱۰ نتیجه اول گوگل' ) );
				var ol = el( 'ol', 'qtb-ev-serp' );
				d.serp.forEach( function ( o ) {
					var li = el( 'li' );
					var a = el( 'a', '', o.title || o.url );
					a.href = o.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
					li.appendChild( a );
					var meta = el( 'small', 'qtb-muted', o.domain + ' · ' + o.type );
					li.appendChild( meta );
					ol.appendChild( li );
				} );
				body.appendChild( ol );
			} ).catch( function ( err ) { drawer.hidden = true; toast( err.message, true ); } );
		};
		$( '[data-qtb-drawer-close]', drawer ).addEventListener( 'click', function () { drawer.hidden = true; } );
		document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { drawer.hidden = true; closePop(); } } );

		// Picking clusters for a merge (first picked = kept).
		var order = [];
		var syncPick = function () {
			$$( '[data-qtb-pick]', bd ).forEach( function ( c ) {
				var id = +c.getAttribute( 'data-qtb-pick' );
				var at = order.indexOf( id );
				if ( c.checked && at < 0 ) { order.push( id ); }
				if ( ! c.checked && at > -1 ) { order.splice( at, 1 ); }
				c.closest( '.qtb-cl' ).classList.toggle( 'is-picked', c.checked );
			} );
			var b = $( '[data-qtb-bdmerge]', bd );
			b.hidden = order.length < 2;
			$( '[data-n]', b ).textContent = fa( order.length );
			var bs = $( '[data-qtb-bdbulksec]', bd );
			if ( bs ) {
				bs.hidden = order.length < 1;
				if ( bs.options.length < 2 ) {
					var secBox = $( '.qtb-bd-secs', bd );
					JSON.parse( ( secBox && secBox.getAttribute( 'data-sections' ) ) || '[]' ).forEach( function ( n ) {
						var o = document.createElement( 'option' );
						o.value = n;
						o.textContent = n;
						bs.appendChild( o );
					} );
				}
				bs.options[ 0 ].textContent = 'بردن ' + fa( order.length ) + ' کلاستر انتخاب‌شده به بخش…';
			}
		};

		// Filters.
		var filter = 'all';
		var applyFilter = function () {
			var q = norm( ( $( '[data-qtb-bdsearch]', bd ) || {} ).value || '' );
			bd.classList.toggle( 'is-filtering', 'all' !== filter || !! q );
			$$( '.qtb-bd-cl', bd ).forEach( function ( card ) {
				var shown = 0;
				$$( 'ul > li[data-kw]', card ).forEach( function ( li ) {
					var ok = ( 'all' === filter || ( 'low' === filter && ( li.classList.contains( 'is-c-low' ) || li.classList.contains( 'is-c-mid' ) ) ) || ( 'ai' === filter && $( '.qtb-ai-flag', li ) ) || ( 'review' === filter && li.classList.contains( 'is-rev' ) ) ) && ( ! q || ( li.getAttribute( 'data-k' ) || '' ).indexOf( q ) > -1 );
					li.hidden = ! ok;
					shown += ok ? 1 : 0;
				} );
				card.hidden = ! shown;
			} );
		};
		var sb = $( '[data-qtb-bdsearch]', bd );
		if ( sb ) { sb.addEventListener( 'input', applyFilter ); }

		bd.addEventListener( 'click', function ( e ) {
			var t;
			if ( ( t = e.target.closest( '[data-qtb-more]' ) ) ) {
				t.closest( '.qtb-bd-cl' ).classList.toggle( 'is-open' );
				return;
			}
			if ( ( t = e.target.closest( '[data-qtb-split]' ) ) ) {
				t.disabled = true;
				wsEdit( { op: 'split_node', tid: tid, cid: +t.getAttribute( 'data-cid' ), node: t.getAttribute( 'data-qtb-split' ) }, 'زیرگروه صفحه جدا شد' );
				return;
			}
			if ( e.target.closest( '[data-qtb-bdreview]' ) ) {
				var rf = $( '[data-qtb-bdfilter] [data-f="review"]', bd );
				if ( rf ) { rf.click(); }
				return;
			}
			if ( ( t = e.target.closest( '[data-qtb-bdfilter] [data-f]' ) ) ) {
				e.preventDefault();
				filter = t.getAttribute( 'data-f' );
				$$( '[data-qtb-bdfilter] [data-f]', bd ).forEach( function ( a ) { a.classList.toggle( 'is-active', a === t ); } );
				applyFilter();
			} else if ( ( t = e.target.closest( '[data-qtb-to]' ) ) ) {
				move( +t.getAttribute( 'data-kw' ), t.getAttribute( 'data-qtb-to' ) );
			} else if ( ( t = e.target.closest( '[data-qtb-wsmove]' ) ) ) {
				openMove( t, +t.getAttribute( 'data-qtb-wsmove' ) );
			} else if ( ( t = e.target.closest( '[data-qtb-ev]' ) ) ) {
				evidence( +t.getAttribute( 'data-qtb-ev' ) );
			} else if ( ( t = e.target.closest( '[data-qtb-clmenu]' ) ) ) {
				openClMenu( t, +t.getAttribute( 'data-qtb-clmenu' ) );
			} else if ( e.target.closest( '[data-qtb-publish]' ) ) {
				var pb = e.target.closest( '[data-qtb-publish]' );
				pb.disabled = true;
				pb.textContent = 'در حال به‌روزرسانی…';
				api( 'ws/publish/' + wsPid ).then( function ( d ) { return buildOut( d.status ); } ).then( function () { toast( 'خروجی به‌روز شد.' ); reloadKeepScroll(); } ).catch( function ( err ) { pb.disabled = false; toast( err.message, true ); } );
			} else if ( ( t = e.target.closest( '[data-qtb-secrename]' ) ) ) {
				var from = t.getAttribute( 'data-qtb-secrename' );
				var to = ( window.prompt( 'نام جدید بخش:', from ) || '' ).trim();
				if ( to && to !== from ) { wsEdit( { op: 'section_rename', tid: tid, from: from, to: to }, 'نام بخش تغییر کرد' ); }
			} else if ( ( t = e.target.closest( '[data-qtb-wsrename]' ) ) ) {
				var h = t.closest( '.qtb-cl' ).querySelector( 'h4' );
				var name = window.prompt( 'نام جدید کلاستر:', h ? h.textContent : '' );
				if ( name ) {
					wsEdit( { op: 'rename', tid: tid, cid: +t.getAttribute( 'data-qtb-wsrename' ), name: name }, 'نام تغییر کرد', true ).then( function ( d ) { if ( d && h ) { h.textContent = name; } } );
				}
			} else if ( ( t = e.target.closest( '[data-qtb-wslock]' ) ) ) {
				var on = '1' === t.getAttribute( 'data-on' );
				wsEdit( { op: 'lock', tid: tid, cid: +t.getAttribute( 'data-qtb-wslock' ), off: on ? 1 : 0 }, on ? 'قفل باز شد' : 'قفل شد؛ پردازش دوباره این کلاستر را تغییر نمی‌دهد', true ).then( function ( d ) {
					if ( d ) { t.setAttribute( 'data-on', on ? '0' : '1' ); t.closest( '.qtb-bd-cl' ).classList.toggle( 'is-locked', ! on ); }
				} );
			} else if ( e.target.closest( '[data-qtb-bdmerge]' ) ) {
				var first = $( '.qtb-bd-cl[data-cl="' + order[ 0 ] + '"] h4', bd );
				if ( order.length > 1 && window.confirm( 'کلاسترهای انتخاب‌شده در «' + ( first ? first.textContent : '' ) + '» ادغام شوند؟' ) ) {
					wsEdit( { op: 'merge', tid: tid, ids: order }, 'کلاسترها ادغام شدند' );
				}
			} else if ( ( t = e.target.closest( '[data-qtb-wsop]' ) ) ) {
				var op = t.getAttribute( 'data-qtb-wsop' );
				wsEdit( { op: op, tid: tid }, 'approve' === op ? 'موضوع تأیید شد؛ اصلاح‌های شما حافظه موضوع‌های بعدی است' : 'موضوع برای ویرایش باز شد' );
			}
		} );
		bd.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.matches( '[data-qtb-pick]' ) ) { syncPick(); }
			if ( t.matches( '[data-qtb-bdbulksec]' ) && t.value ) {
				wsEdit( { op: 'section_bulk', tid: tid, cids: order.slice(), section: t.value }, fa( order.length ) + ' کلاستر به بخش «' + t.value + '» رفت' );
				return;
			}
			if ( t.matches( '[data-qtb-tmode]' ) ) { wsEdit( { op: 'mode', tid: tid, mode: t.value }, 'روش بازبینی ذخیره شد', true ); }
			if ( t.matches( '[data-qtb-wssec]' ) ) {
				var sec = t.value;
				if ( '__new' === sec ) {
					sec = ( window.prompt( 'نام بخش جدید (مثلاً «بر اساس برند» یا «راهنمای خرید»):', '' ) || '' ).trim();
					if ( ! sec ) { t.selectedIndex = 0; return; }
				}
				wsEdit( { op: 'section', tid: tid, cid: +t.getAttribute( 'data-qtb-wssec' ), section: sec }, 'بخش کلاستر تغییر کرد' );
			}
			if ( t.matches( '[data-qtb-wspage]' ) ) {
				wsEdit( { op: 'page', tid: tid, cid: +t.getAttribute( 'data-qtb-wspage' ), page: t.value }, 'نوع صفحه تغییر کرد', true ).then( function ( d ) {
					if ( ! d ) { return; }
					var card = t.closest( '.qtb-bd-cl' );
					$( '.qtb-cl-h', card ).className = 'qtb-cl-h is-' + t.value.split( ':' )[ 0 ];
					var why = $( '.qtb-cl-why', card );
					if ( ! why ) { why = el( 'div', 'qtb-cl-why' ); $( '.qtb-cl-meta', card ).after( why ); }
					why.textContent = 'انتخاب شما';
					card.classList.add( 'is-locked' );
				} );
			}
		} );

		// Comment → rules.
		var cm = $( '[data-qtb-comment]', bd );
		var proposed = [];
		if ( cm ) {
			cm.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '[data-qtb-comment-go]' ) ) {
					var text = $( '[data-qtb-comment-text]', cm ).value.trim();
					if ( ! text ) { return; }
					var go = e.target.closest( 'button' );
					go.disabled = true;
					api( 'ws/comment/' + wsPid, { tid: tid, text: text } ).then( function ( d ) {
						proposed = d.rules || [];
						$( '[data-qtb-comment-out]', cm ).hidden = false;
						$( '[data-qtb-comment-reply]', cm ).textContent = d.reply || '';
						var ul = $( '[data-qtb-comment-rules]', cm );
						ul.innerHTML = '';
						proposed.forEach( function ( r, n ) {
							var li = el( 'li' );
							var c = el( 'input' );
							c.type = 'checkbox'; c.checked = true; c.setAttribute( 'data-n', n );
							li.appendChild( c );
							li.appendChild( el( 'span', 'qtb-chip', kinds[ r.kind ] || r.kind ) );
							li.appendChild( document.createTextNode( ' ' + ( r.text || r.match ) ) );
							if ( r.match ) { li.appendChild( el( 'small', 'qtb-muted', ' — «' + r.match + '»' ) ); }
							ul.appendChild( li );
						} );
						if ( ! proposed.length ) { ul.appendChild( el( 'li', 'qtb-muted', 'قانونی پیشنهاد نشد؛ نظر شما در حافظه موضوع ذخیره شد.' ) ); }
					} ).catch( function ( err ) { toast( err.message, true ); } ).then( function () { go.disabled = false; } );
				}
				if ( e.target.closest( '[data-qtb-comment-save]' ) ) {
					var pick = $$( '[data-qtb-comment-rules] input:checked', cm ).map( function ( c ) { return proposed[ +c.getAttribute( 'data-n' ) ]; } );
					if ( ! pick.length ) { return; }
					var chain = Promise.resolve();
					var saved = 0, touched = 0;
					pick.forEach( function ( r ) {
						chain = chain.then( function () {
							return wsEdit( { op: 'rule_add', kind: r.kind, match: r.match || '', page: r.page || '', archive: r.archive || '', text: r.text || '' }, null, true ).then( function ( d ) { if ( d ) { saved++; touched += d.n || 0; } } );
						} );
					} );
					chain.then( function () {
						toast( fa( saved ) + ' قانون ذخیره شد' + ( touched ? '؛ ' + fa( touched ) + ' کلمه تغییر کرد' : '' ) );
						$( '[data-qtb-after-rules]', cm ).hidden = false;
						$( '[data-qtb-comment-save]', cm ).disabled = true;
					} );
				}
			} );
		}
	} );

	// Competitors.
	$$( '[data-qtb-compup]' ).forEach( function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var fd = new FormData( f );
			fd.append( 'act', 'upload' );
			var b = $( 'button', f );
			b.disabled = true;
			b.textContent = 'در حال خواندن…';
			api( 'comp/' + f.getAttribute( 'data-qtb-compup' ), fd, true ).then( function ( d ) { toast( d.msg ); setTimeout( reloadKeepScroll, 700 ); } ).catch( function ( err ) { b.disabled = false; b.textContent = 'بارگذاری رقیب'; toast( err.message, true ); } );
		} );
	} );
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-qtb-compai], [data-qtb-compdel]' );
		if ( ! b ) { return; }
		var up = $( '[data-qtb-compup]' );
		var del = b.hasAttribute( 'data-qtb-compdel' );
		var dom = b.getAttribute( del ? 'data-qtb-compdel' : 'data-qtb-compai' );
		if ( del && ! window.confirm( 'رقیب ' + dom + ' حذف شود؟' ) ) { return; }
		b.disabled = true;
		if ( ! del ) { b.textContent = 'در حال تحلیل… (تا ۲ دقیقه)'; }
		api( 'comp/' + up.getAttribute( 'data-qtb-compup' ), { act: del ? 'delete' : 'ai', domain: dom } ).then( function ( d ) { toast( d.msg ); reloadKeepScroll(); } ).catch( function ( err ) { b.disabled = false; toast( err.message, true ); } );
	} );
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-qtb-compexport]' ) ) {
			var up = $( '[data-qtb-compup]' );
			api( 'comp/' + up.getAttribute( 'data-qtb-compup' ), { act: 'export', on: e.target.checked ? 1 : 0 } ).then( function ( d ) { toast( d.msg ); } ).catch( function ( err ) { toast( err.message, true ); } );
		}
	} );
	$$( '[data-qtb-gap]' ).forEach( function ( box ) {
		var boxes = function () { return $$( '[data-qtb-gapk]', box ); };
		var sync = function () {
			var n = boxes().filter( function ( c ) { return c.checked; } ).length;
			var bar = $( '[data-qtb-gapbar]', box );
			if ( bar ) { bar.hidden = ! n; $( '[data-n]', bar ).textContent = fa( n ); }
		};
		box.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '[data-qtb-gapall]' ) ) { boxes().forEach( function ( c ) { c.checked = e.target.checked; } ); }
			sync();
		} );
		box.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-qtb-gapadd]' );
			if ( ! b || ! wsPid ) { return; }
			var lines = boxes().filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.getAttribute( 'data-qtb-gapk' ) + '\t' + c.getAttribute( 'data-v' ); } );
			if ( ! lines.length ) { return; }
			var fd = new FormData();
			fd.append( 'text', lines.join( '\n' ) );
			b.disabled = true;
			wsBusy( true );
			api( 'ws/add/' + wsPid, fd, true ).then( function ( d ) {
				toast( d.msg );
				if ( d.status ) { wsLoop( Promise.resolve( d.status ) ); } else { wsBusy( false ); b.disabled = false; }
			} ).catch( function ( err ) { wsBusy( false ); b.disabled = false; toast( err.message, true ); } );
		} );
	} );

	// The output build continues in slices until done.
	function buildOut( st ) {
		if ( ! st || st.done ) { return Promise.resolve( st ); }
		if ( st.error ) { return Promise.reject( new Error( st.error ) ); }
		return api( 'tick/' + wsPid ).then( buildOut );
	}

	// Final.
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-qtb-finalize]' );
		if ( ! b || ! window.confirm( 'کیورد ریسرچ از موضوع‌ها ساخته شود؟ نتیجه قبلی جایگزین می‌شود.' ) ) { return; }
		var inc = $( '[data-qtb-include-ready]' );
		b.disabled = true;
		b.textContent = 'در حال ساخت…';
		api( 'ws/finalize/' + wsPid, { include_ready: inc && inc.checked ? 1 : 0 } ).then( function ( d ) {
			toast( fa( d.clusters || 0 ) + ' صفحه از ' + fa( d.topics || 0 ) + ' موضوع؛ در حال ساخت خروجی…' );
			return buildOut( d.status );
		} ).then( function () { location.href = b.getAttribute( 'data-url' ); } ).catch( function ( err ) { b.disabled = false; toast( err.message, true ); } );
	} );

	// ---- Settings tests ----
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-qtb-test]' );
		if ( ! b ) { return; }
		var what = b.getAttribute( 'data-qtb-test' );
		var out = $( '[data-qtb-test-out="' + what + '"]' );
		b.disabled = true;
		out.hidden = false;
		out.textContent = 'در حال تست با مقادیر همین صفحه… (برای ماندگاری، «ذخیره تنظیمات» را هم بزنید)';
		var form = b.closest( 'form' );
		var vals = {};
		if ( form ) {
			new FormData( form ).forEach( function ( v, k ) { if ( 'string' === typeof v ) { vals[ k ] = v; } } );
		}
		api( 'test/' + what, { form: vals } ).then( function ( d ) {
			out.textContent = ( d.ok ? '✓ ' : '✗ ' ) + d.msg;
			out.style.color = d.ok ? 'var(--accent-ink)' : 'var(--bad)';
		} ).catch( function ( err ) { out.textContent = '✗ ' + err.message; out.style.color = 'var(--bad)'; } ).then( function () { b.disabled = false; } );
	} );

	// ---- Serper from the browser (route «browser») ----
	var serperKey = null;
	function serperFetch( key, q, gl, hl ) {
		return fetch( 'https://google.serper.dev/search', {
			method: 'POST',
			headers: { 'X-API-KEY': key, 'Content-Type': 'application/json' },
			body: JSON.stringify( { q: q, gl: gl, hl: hl, num: 10 } )
		} ).then( function ( r ) {
			return r.json().catch( function () { return null; } ).then( function ( d ) {
				if ( 401 === r.status || 403 === r.status ) { var e = new Error( 'Serper کلید را رد کرد یا اعتبار حساب تمام شده است.' ); e.hard = true; throw e; }
				if ( 429 === r.status ) { var e2 = new Error( 'سقف درخواست Serper پر شده؛ چند دقیقه بعد «ادامه» را بزنید.' ); e2.hard = true; throw e2; }
				if ( ! r.ok || ! d ) { throw new Error( 'Serper خطا داد (HTTP ' + r.status + ')' ); }
				return d;
			} );
		}, function () {
			var e = new Error( 'مرورگر به Serper نرسید (اینترنت، فیلترشکن یا اجازه مرورگر). مسیر Worker کلادفلر را در تنظیمات امتحان کنید.' );
			e.hard = true;
			throw e;
		} );
	}
	function browserSerp( p, pid, progress ) {
		var items = p.items.slice();
		var all = items.length;
		var done = 0;
		var buf = [];
		var fails = 0;
		var flush = function ( force ) {
			if ( ! buf.length || ( ! force && buf.length < 10 ) ) { return Promise.resolve(); }
			var batch = buf.splice( 0, buf.length );
			return api( 'ws/serp/' + pid, { items: batch } );
		};
		var keyP = serperKey ? Promise.resolve( serperKey ) : api( 'serp/browser-key', {} ).then( function ( d ) { serperKey = d; return d; } );
		return keyP.then( function ( k ) {
			var worker = function () {
				var it = items.shift();
				if ( ! it ) { return Promise.resolve(); }
				return serperFetch( k.key, it[ 1 ], p.gl || k.gl, p.hl || k.hl ).then( function ( d ) {
					buf.push( { i: it[ 0 ], k: it[ 1 ], data: d } );
					done++;
					if ( progress ) { progress( done, all ); }
					return flush( false );
				}, function ( err ) {
					if ( err.hard || ++fails > 8 ) { throw err; }
				} ).then( worker );
			};
			return Promise.all( [ worker(), worker(), worker() ] );
		} ).then( function () { return flush( true ); }, function ( err ) {
			return flush( true ).then( function () { throw err; } );
		} );
	}

	// ---- Serper connection guide (settings) ----
	var wz = $( '[data-qtb-wz]' );
	if ( wz ) {
		var order = [ 'account', 'key', 'test', 'cf_account', 'cf_worker', 'cf_code', 'cf_url' ];
		var routeNames = { direct: 'مستقیم از سرور', worker: 'Worker کلادفلر', browser: 'مرورگر شما' };
		var S = JSON.parse( wz.getAttribute( 'data-state' ) || '{}' );
		var connected = function () { return S.has_key && S.done.indexOf( 'test' ) > -1 && 'done' === S.step; };
		var setup = function ( body ) {
			return api( 'serp/setup', body ).then( function ( d ) { if ( d.state ) { S = d.state; } render(); return d; } );
		};
		var render = function () {
			var cur = S.step || 'account';
			var cfOn = S.done.indexOf( 'cf_account' ) > -1 || 0 === cur.indexOf( 'cf_' ) || 'worker' === S.route;
			$$( '.qtb-wz-step', wz ).forEach( function ( li ) {
				var id = li.getAttribute( 'data-step' );
				var i = order.indexOf( id );
				var open = id === cur;
				var done = S.done.indexOf( id ) > -1;
				// A step opens once every earlier step of its path is done.
				var prev = order.slice( 0, i ).filter( function ( x ) { return 0 !== x.indexOf( 'cf_' ) || 0 === id.indexOf( 'cf_' ); } );
				var ready = prev.every( function ( x ) { return S.done.indexOf( x ) > -1 || ( 'test' === x && 0 === id.indexOf( 'cf_' ) ); } );
				li.classList.toggle( 'is-done', done );
				li.classList.toggle( 'is-open', open && ready );
				li.classList.toggle( 'is-locked', ! ready );
				li.hidden = 0 === id.indexOf( 'cf_' ) && ! cfOn;
			} );
			var shown = $$( '.qtb-wz-step', wz ).filter( function ( li ) { return ! li.hidden; } );
			shown.forEach( function ( li, n ) { li.classList.toggle( 'is-last', n === shown.length - 1 ); } );
			$( '[data-qtb-wz-final]', wz ).hidden = ! connected();
			wz.classList.toggle( 'is-connected', connected() );
			$( '[data-qtb-wz-route]', wz ).textContent = connected() ? 'وصل از مسیر: ' + routeNames[ S.route ] : 'هنوز وصل نیست';
			$$( '[data-qtb-wz-routes] [data-qtb-wz-use]', wz ).forEach( function ( b ) { b.classList.toggle( 'is-active', b.getAttribute( 'data-qtb-wz-use' ) === S.route ); } );
			[ 'direct', 'browser', 'worker' ].forEach( function ( r ) {
				var row = $( '[data-qtb-wz-test="' + r + '"]', wz );
				var t = S.tests && S.tests[ r ];
				if ( ! row || ! t ) { return; }
				row.className = t.ok ? 'is-ok' : 'is-bad';
				$( '[data-msg]', row ).textContent = t.msg;
				var use = $( '[data-qtb-wz-use]', row );
				if ( use ) { use.hidden = ! t.ok; }
			} );
			var adv = $( '[data-qtb-wz-advice]', wz );
			if ( adv && S.tests && S.tests.direct && S.tests.browser ) {
				adv.hidden = false;
				adv.textContent = S.tests.direct.ok ? 'مسیر مستقیم کار می‌کند؛ ساده‌ترین انتخاب همین است.' : ( S.tests.browser.ok ? 'سرور به Serper نمی‌رسد ولی مرورگر شما می‌رسد. مسیر مرورگر را انتخاب کنید، یا برای کار بدون باز ماندن صفحه، Worker کلادفلر بسازید.' : 'هیچ‌کدام از دو مسیر کار نکرد. Worker کلادفلر را بسازید؛ چند قدم پایین‌تر باز شد.' );
			}
		};
		var loadCode = function () {
			var pre = $( '[data-qtb-wz-code]', wz );
			if ( pre && '…' === pre.textContent ) {
				api( 'serp/setup', { op: 'worker_code' } ).then( function ( d ) { pre.textContent = d.code; } ).catch( function ( err ) { toast( err.message, true ); } );
			}
		};
		var testBrowser = function () {
			var row = $( '[data-qtb-wz-test="browser"]', wz );
			$( '[data-msg]', row ).textContent = 'در حال تست…';
			serperKey = null;
			return api( 'serp/browser-key', {} ).then( function ( k ) {
				serperKey = k;
				return serperFetch( k.key, 'خرید گل آپارتمانی', k.gl, k.hl );
			} ).then( function ( d ) {
				var n = ( d.organic || [] ).length;
				return setup( { op: 'browser_result', ok: n > 0 ? 1 : 0, msg: n > 0 ? 'وصل شد؛ ' + fa( n ) + ' نتیجه گرفته شد.' : 'پاسخ بدون نتیجه' } );
			}, function ( err ) {
				return setup( { op: 'browser_result', ok: 0, msg: err.message } );
			} );
		};
		wz.addEventListener( 'click', function ( e ) {
			var t;
			if ( ( t = e.target.closest( '[data-qtb-wz-done]' ) ) ) {
				var next = t.getAttribute( 'data-next' );
				setup( { op: 'step', step: t.getAttribute( 'data-qtb-wz-done' ), done: 1, next: next } ).then( function () { if ( 'cf_code' === next ) { loadCode(); } } ).catch( function ( err ) { toast( err.message, true ); } );
			} else if ( ( t = e.target.closest( '[data-qtb-wz-open]' ) ) ) {
				var li = t.closest( '.qtb-wz-step' );
				if ( li.classList.contains( 'is-locked' ) ) { toast( 'اول قدم قبلی را انجام دهید و تأیید کنید.', true ); return; }
				S.step = t.getAttribute( 'data-qtb-wz-open' );
				render();
				if ( 'cf_code' === S.step ) { loadCode(); }
			} else if ( e.target.closest( '[data-qtb-wz-savekey]' ) ) {
				var key = $( '[data-qtb-wz-key]', wz ).value.trim();
				setup( { op: 'save_key', key: key } ).then( function () { toast( 'کلید ذخیره شد؛ حالا اتصال را تست کنید.' ); } ).catch( function ( err ) { toast( err.message, true ); } );
			} else if ( ( t = e.target.closest( '[data-qtb-wz-test-go]' ) ) || e.target.closest( '[data-qtb-wz-retest]' ) ) {
				var b = t || e.target.closest( '[data-qtb-wz-retest]' );
				b.disabled = true;
				$( '[data-qtb-wz-test="direct"] [data-msg]', wz ).textContent = 'در حال تست…';
				setup( { op: 'test', route: 'direct' } ).catch( function ( err ) { toast( err.message, true ); } ).then( testBrowser ).then( function () {
					if ( S.tests.worker || S.worker_url ) { return setup( { op: 'test', route: 'worker' } ); }
				} ).then( function () {
					var ok = ( S.tests.direct && S.tests.direct.ok ) || ( S.tests.browser && S.tests.browser.ok );
					if ( ! ok && 0 !== ( S.step || '' ).indexOf( 'cf_' ) && 'done' !== S.step ) { return setup( { op: 'step', step: 'test', done: 1, next: 'cf_account' } ); }
				} ).catch( function ( err ) { toast( err.message, true ); } ).then( function () { b.disabled = false; } );
			} else if ( ( t = e.target.closest( '[data-qtb-wz-use]' ) ) ) {
				var r = t.getAttribute( 'data-qtb-wz-use' );
				setup( { op: 'set_route', route: r } ).then( function () { toast( 'مسیر «' + routeNames[ r ] + '» انتخاب شد.' ); } ).catch( function ( err ) { toast( err.message, true ); } );
			} else if ( e.target.closest( '[data-qtb-wz-copycode]' ) ) {
				var code = $( '[data-qtb-wz-code]', wz ).textContent;
				if ( navigator.clipboard ) { navigator.clipboard.writeText( code ).then( function () { toast( 'کد کپی شد؛ در ویرایشگر کلادفلر جای‌گذاری کنید.' ); } ); }
			} else if ( e.target.closest( '[data-qtb-wz-savew]' ) ) {
				var url = $( '[data-qtb-wz-wurl]', wz ).value.trim();
				var msg = $( '[data-qtb-wz-wmsg]', wz );
				msg.hidden = false;
				msg.textContent = 'در حال ذخیره و تست…';
				setup( { op: 'save_worker', url: url } ).then( function () {
					return setup( { op: 'test', route: 'worker' } );
				} ).then( function ( d ) {
					msg.textContent = ( d.ok ? '✓ ' : '✗ ' ) + d.msg;
					msg.className = 'qtb-wz-tip ' + ( d.ok ? 'is-ok' : 'is-bad' );
					if ( d.ok ) {
						return setup( { op: 'step', step: 'cf_url', done: 1, next: 'cf_url' } ).then( function () { return setup( { op: 'set_route', route: 'worker' } ); } ).then( function () { toast( 'Worker کلادفلر وصل شد و انتخاب شد.' ); } );
					}
				} ).catch( function ( err ) { msg.textContent = '✗ ' + err.message; msg.className = 'qtb-wz-tip is-bad'; } );
			} else if ( e.target.closest( '[data-qtb-wz-reset]' ) ) {
				if ( window.confirm( 'راهنما از اول شروع شود؟ کلید و مسیر فعلی پاک نمی‌شود.' ) ) { setup( { op: 'reset' } ); }
			}
		} );
		render();
		if ( 'cf_code' === S.step ) { loadCode(); }
	}

	// ---- Facet dictionary (workshop step «ویژگی‌ها») ----
	$$( '[data-qtb-fx]' ).forEach( function ( box ) {
		var toks = JSON.parse( box.getAttribute( 'data-toks' ) || '[]' );
		var types = JSON.parse( box.getAttribute( 'data-types' ) || '[]' );
		var fxEdit = function ( body, ok ) {
			return api( 'ws/edit/' + wsPid, body ).then( function ( d ) { if ( ok ) { toast( ok ); } reloadKeepScroll(); return d; } ).catch( function ( err ) { toast( err.message, true ); } );
		};
		var drag = null;
		box.addEventListener( 'dragstart', function ( e ) {
			var li = e.target.closest( '[data-tok]' );
			if ( ! li ) { return; }
			drag = li;
			li.classList.add( 'is-drag' );
			box.classList.add( 'is-dragging' );
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData( 'text/plain', li.getAttribute( 'data-tok' ) ); } catch ( x ) {}
		} );
		box.addEventListener( 'dragend', function () {
			if ( drag ) { drag.classList.remove( 'is-drag' ); }
			drag = null;
			box.classList.remove( 'is-dragging' );
			$$( '.is-over', box ).forEach( function ( x ) { x.classList.remove( 'is-over' ); } );
		} );
		box.addEventListener( 'dragover', function ( e ) {
			var lane = e.target.closest( '[data-fxlane]' );
			if ( ! drag || ! lane ) { return; }
			e.preventDefault();
			$$( '.is-over', box ).forEach( function ( x ) { if ( x !== lane ) { x.classList.remove( 'is-over' ); } } );
			lane.classList.add( 'is-over' );
		} );
		box.addEventListener( 'drop', function ( e ) {
			var lane = e.target.closest( '[data-fxlane]' );
			if ( ! drag || ! lane ) { return; }
			e.preventDefault();
			var to = lane.getAttribute( 'data-fxlane' );
			if ( drag.closest( '[data-fxlane]' ) === lane ) { return; }
			$( '[data-fxdrop]', lane ).appendChild( drag );
			fxEdit( { op: 'fx_token', tok: drag.getAttribute( 'data-tok' ), t: to }, '«' + drag.getAttribute( 'data-tok' ) + '» به ردیف تازه رفت' );
		} );
		// A word: its role and the main spelling it is merged into.
		var pop = null;
		var closePop = function () { if ( pop && pop.parentNode ) { pop.parentNode.removeChild( pop ); } pop = null; };
		document.addEventListener( 'click', function ( e ) { if ( pop && ! e.target.closest( '.qtb-pop' ) && ! e.target.closest( '[data-tok]' ) ) { closePop(); } } );
		box.addEventListener( 'click', function ( e ) {
			var li = e.target.closest( '[data-tok]' );
			var b;
			if ( li ) {
				closePop();
				var tok = li.getAttribute( 'data-tok' );
				var cur = li.closest( '[data-fxlane]' ).getAttribute( 'data-fxlane' );
				pop = document.createElement( 'div' );
				pop.className = 'qtb-pop is-wide qtb-fx-pop';
				pop.innerHTML = '<h4></h4><p class="qtb-small qtb-muted" data-ex></p><label class="qtb-small">نقش<select data-t></select></label><label class="qtb-small">یکی کردن با واژه دیگر <small>همان صفحه، نوشتار دیگر</small><input type="search" placeholder="جستجوی واژه اصلی…" data-q></label><ul data-list></ul><div class="qtb-inline"><button type="button" class="qtb-btn is-small" data-unmerge hidden>جدا کردن از واژه اصلی</button></div>';
				$( 'h4', pop ).textContent = tok;
				$( '[data-ex]', pop ).textContent = li.getAttribute( 'title' ) || '';
				var sel = $( '[data-t]', pop );
				types.forEach( function ( t ) { var o = document.createElement( 'option' ); o.value = t[ 0 ]; o.textContent = t[ 1 ]; o.selected = t[ 0 ] === cur; sel.appendChild( o ); } );
				sel.addEventListener( 'change', function () { fxEdit( { op: 'fx_token', tok: tok, t: sel.value }, 'نقش «' + tok + '» عوض شد' ); } );
				var c = li.getAttribute( 'data-c' );
				var un = $( '[data-unmerge]', pop );
				un.hidden = c === tok;
				un.addEventListener( 'click', function () { fxEdit( { op: 'fx_token', tok: tok, c: tok }, '«' + tok + '» جدا شد' ); } );
				var list = $( '[data-list]', pop );
				var q = $( '[data-q]', pop );
				var draw = function () {
					var v = q.value.trim();
					list.innerHTML = '';
					toks.filter( function ( x ) { return x[ 0 ] !== tok && ( ! v || x[ 0 ].indexOf( v ) > -1 ); } ).slice( 0, 30 ).forEach( function ( x ) {
						var it = document.createElement( 'li' );
						var bt = document.createElement( 'button' );
						bt.type = 'button';
						bt.innerHTML = '<span></span><em></em>';
						bt.firstChild.textContent = x[ 0 ];
						bt.lastChild.textContent = fa( x[ 1 ] );
						bt.addEventListener( 'click', function () { fxEdit( { op: 'fx_token', tok: tok, c: x[ 0 ] }, '«' + tok + '» با «' + x[ 0 ] + '» یکی شد' ); } );
						it.appendChild( bt );
						list.appendChild( it );
					} );
				};
				q.addEventListener( 'input', draw );
				draw();
				document.body.appendChild( pop );
				var r = li.getBoundingClientRect();
				pop.style.top = ( window.scrollY + r.bottom + 6 ) + 'px';
				pop.style.left = Math.max( 8, Math.min( window.innerWidth - pop.offsetWidth - 8, window.scrollX + r.left + r.width / 2 - pop.offsetWidth / 2 ) ) + 'px';
			} else if ( e.target.closest( '[data-qtb-fxtype]' ) ) {
				var label = window.prompt( 'نام نوع ویژگی (مفرد)، مثلاً «جنس» یا «شهر»:' );
				if ( ! label ) { return; }
				var plural = window.prompt( 'نام بخش این نوع در ساختار سایت (جمع)، مثلاً «جنس‌ها»:', label ) || label;
				fxEdit( { op: 'fx_type', label: label, plural: plural }, 'نوع «' + label + '» اضافه شد' );
			} else if ( ( b = e.target.closest( '[data-qtb-fxrename]' ) ) ) {
				var l2 = window.prompt( 'نام نوع ویژگی:', b.getAttribute( 'data-label' ) );
				if ( ! l2 ) { return; }
				var p2 = window.prompt( 'نام بخش (جمع):', b.getAttribute( 'data-plural' ) ) || l2;
				fxEdit( { op: 'fx_type', id: b.getAttribute( 'data-qtb-fxrename' ), label: l2, plural: p2 }, 'نام نوع عوض شد' );
			} else if ( ( b = e.target.closest( '[data-qtb-fxdel]' ) ) ) {
				if ( window.confirm( 'این نوع ویژگی حذف شود؟' ) ) { fxEdit( { op: 'fx_type_del', id: b.getAttribute( 'data-qtb-fxdel' ) }, 'نوع حذف شد' ); }
			}
		} );
		var fs = $( '[data-qtb-fxsearch]', box );
		if ( fs ) {
			fs.addEventListener( 'input', function () {
				var v = fs.value.trim();
				$$( '[data-tok]', box ).forEach( function ( li ) { li.classList.toggle( 'is-dim', !! v && li.getAttribute( 'data-tok' ).indexOf( v ) < 0 ); li.classList.toggle( 'is-hit', !! v && li.getAttribute( 'data-tok' ).indexOf( v ) > -1 ); } );
			} );
		}
	} );
}() );
