(function () {
	'use strict';
	if ( ! window.jinyu_stats || ! window.jinyu_stats.ajax_url || ! window.jinyu_stats.today ) {
		return;
	}

	var today = window.jinyu_stats.today;
	var key   = 'jinyu_uv_' + today;

	if ( window.localStorage ) {
		try {
			if ( window.localStorage.getItem( key ) ) {
				return;
			}
		} catch ( e ) {
			// localStorage 不可用则回退到 cookie 检查。
		}
	}

	var url = window.jinyu_stats.ajax_url + '?action=jinyu_record_uv&date=' + encodeURIComponent( today );

	function markDone() {
		if ( window.localStorage ) {
			try {
				window.localStorage.setItem( key, '1' );
			} catch ( e ) {}
		}
	}

	if ( window.fetch ) {
		window.fetch( url, { method: 'POST', credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) {
				if ( response.ok ) {
					markDone();
				}
			} )
			.catch( function () {} );
	} else {
		// IE / 旧浏览器回退：Image beacon。
		var img = new Image();
		img.src = url + '&_t=' + Date.now();
		markDone();
	}
})();
