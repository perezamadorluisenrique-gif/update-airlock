( function () {
	'use strict';
	var cfg = window.updlockAdmin;
	if ( ! cfg ) {
		return;
	}

	function post( data ) {
		var body = new URLSearchParams( data );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	// Confirmation dialogs on buttons that carry data-updlock-confirm.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest ? e.target.closest( '[data-updlock-confirm]' ) : null;
		if ( el && ! window.confirm( el.getAttribute( 'data-updlock-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// Runs one inspection job in small steps. Resolves with the final response data.
	function runJob( kind, slug, onProgress ) {
		function step( token ) {
			var data = { action: 'updlock_inspect', _ajax_nonce: cfg.nonce, kind: kind, slug: slug };
			if ( token ) {
				data.token = token;
			}
			return post( data ).then( function ( res ) {
				if ( ! res.success ) {
					throw new Error( ( res.data && res.data.message ) || cfg.strings.failed );
				}
				onProgress( res.data );
				return res.data.done ? res.data : step( res.data.token );
			} );
		}
		onProgress( { progress: 2, done: false, first: true } );
		return step( '' );
	}

	// Progress page.
	var job = document.getElementById( 'updlock-job' );
	if ( job ) {
		var bar = job.querySelector( 'progress' );
		var status = job.querySelector( '.updlock-status' );
		status.textContent = cfg.strings.download;
		runJob( job.getAttribute( 'data-kind' ), job.getAttribute( 'data-slug' ), function ( d ) {
			bar.value = d.progress;
			if ( d.progress > 5 ) {
				status.textContent = cfg.strings.reading;
			}
		} ).then( function ( d ) {
			window.location.href = d.redirect;
		} ).catch( function ( err ) {
			status.textContent = err.message;
		} );
	}

	// Welcome: inspect all waiting updates.
	var inspectAll = document.getElementById( 'updlock-inspect-all' );
	if ( inspectAll ) {
		inspectAll.addEventListener( 'click', function () {
			inspectAll.disabled = true;
			var items = Array.prototype.slice.call( document.querySelectorAll( '#updlock-inspect-list li' ) );
			items.reduce( function ( chain, li ) {
				return chain.then( function () {
					var out = li.querySelector( '.updlock-result' );
					out.textContent = cfg.strings.working;
					return runJob( li.getAttribute( 'data-kind' ), li.getAttribute( 'data-slug' ), function ( d ) {
						out.textContent = d.done ? '' : cfg.strings.working + ' ' + d.progress + '%';
					} ).then( function ( d ) {
						out.innerHTML = '';
						var a = document.createElement( 'a' );
						a.href = d.redirect;
						a.textContent = d.label;
						out.appendChild( a );
					} ).catch( function ( err ) {
						out.textContent = err.message;
					} );
				} );
			}, Promise.resolve() );
		} );
	}

	// Welcome: capture the baseline one page at a time.
	var baseList = document.getElementById( 'updlock-baseline-list' );
	var baseBtn = document.getElementById( 'updlock-baseline-start' );
	if ( baseList && baseBtn ) {
		baseBtn.addEventListener( 'click', function () {
			baseBtn.disabled = true;
			var nonce = baseList.getAttribute( 'data-updlock-baseline' );
			Array.prototype.slice.call( baseList.querySelectorAll( 'li' ) ).reduce( function ( chain, li ) {
				return chain.then( function () {
					var out = li.querySelector( '.updlock-result' );
					out.textContent = cfg.strings.working;
					return post( { action: 'updlock_baseline', _ajax_nonce: nonce, url: li.getAttribute( 'data-url' ) } ).then( function ( res ) {
						if ( res.success ) {
							out.textContent = ( res.data.ok ? 'OK ' : '! ' ) + ( res.data.status || res.data.error ) + ' (' + res.data.ms + ' ms)';
						} else {
							out.textContent = ( res.data && res.data.message ) || cfg.strings.failed;
						}
					} ).catch( function () {
						out.textContent = cfg.strings.failed;
					} );
				} );
			}, Promise.resolve() );
		} );
	}

	// Entries that could not be checked yet: ask the server to try again from this page.
	if ( cfg.pending ) {
		post( { action: 'updlock_recheck', _ajax_nonce: cfg.recheck } );
	}
}() );
