( function () {
	'use strict';

	var config = window.a8cspSyncJobs;
	var panel = document.getElementById( 'a8csp-sync-job' );

	if ( ! config || ! panel ) {
		return;
	}

	var SLOW_POLL_INTERVAL = 15000;
	var STATUSES = [ 'queued', 'running', 'waiting', 'completed', 'cancelled', 'failed' ];
	var NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: '\'', nbsp: ' ' };

	var i18n = config.i18n || {};
	var pollInterval = Math.max( 1000, parseInt( config.pollInterval, 10 ) || 3000 );
	var slowPollInterval = Math.max( pollInterval, SLOW_POLL_INTERVAL );
	var lang = document.documentElement.lang || undefined;
	var settingsReady = panel.getAttribute( 'data-settings-ready' ) === '1';
	var matchingCount = parseInt( panel.getAttribute( 'data-matching-count' ), 10 ) || 0;

	function find( role ) {
		return panel.querySelector( '[data-sync-role="' + role + '"]' );
	}

	var ui = {
		start: find( 'start' ),
		skipSynced: find( 'skip-synced' ),
		message: find( 'message' ),
		status: find( 'status' ),
		statusHeading: find( 'status-heading' ),
		statusLabel: find( 'status-label' ),
		spinner: find( 'spinner' ),
		filters: find( 'filters' ),
		progress: find( 'progress' ),
		progressText: find( 'progress-text' ),
		countSynced: find( 'count-synced' ),
		countSkipped: find( 'count-skipped' ),
		countFailed: find( 'count-failed' ),
		skippedAtStart: find( 'skipped-at-start' ),
		countSkippedAtStart: find( 'count-skipped-at-start' ),
		meta: find( 'meta' ),
		error: find( 'error' ),
		errorLine: find( 'error-line' ),
		errorText: find( 'error-text' ),
		nextRun: find( 'next-run' ),
		failures: find( 'failures' ),
		failuresSummary: find( 'failures-summary' ),
		failuresList: find( 'failures-list' ),
		failuresMore: find( 'failures-more' ),
		cancel: find( 'cancel' ),
		retry: find( 'retry' ),
		dismiss: find( 'dismiss' ),
		reload: find( 'reload' )
	};

	var state = {
		job: config.job && typeof config.job === 'object' ? config.job : null,
		busy: false,
		polling: false,
		stopped: false,
		timer: null,
		seq: 0,
		appliedSeq: 0,
		sawActive: false,
		messageSource: '',
		messageText: '',
		failuresKey: null
	};

	var numberFormat = null;
	try {
		numberFormat = new Intl.NumberFormat( lang );
	} catch ( e ) {
		numberFormat = null;
	}

	function toInt( value ) {
		var number = parseInt( value, 10 );
		return isNaN( number ) || number < 0 ? 0 : number;
	}

	function num( value ) {
		var number = toInt( value );
		return numberFormat ? numberFormat.format( number ) : String( number );
	}

	// Server strings can carry HTML entities (texturized titles, term names); decode without parsing markup.
	function decodeEntities( value ) {
		return value.replace( /&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, function ( match, entity ) {
			if ( entity.charAt( 0 ) === '#' ) {
				var code = entity.charAt( 1 ).toLowerCase() === 'x' ? parseInt( entity.slice( 2 ), 16 ) : parseInt( entity.slice( 1 ), 10 );
				return code > 0 && code <= 0x10ffff ? String.fromCodePoint( code ) : match;
			}
			var named = NAMED_ENTITIES[ entity.toLowerCase() ];
			return named === undefined ? match : named;
		} );
	}

	function text( value ) {
		return value === null || value === undefined ? '' : decodeEntities( String( value ) );
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( template || '' ).replace( /%(?:(\d+)\$)?s/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];
			return value === undefined ? '' : String( value );
		} );
	}

	function formatTime( seconds ) {
		var value = toInt( seconds );
		if ( ! value ) {
			return '';
		}
		var date = new Date( value * 1000 );
		try {
			return date.toLocaleString( lang );
		} catch ( e ) {
			return date.toLocaleString();
		}
	}

	function safeUrl( url ) {
		if ( typeof url !== 'string' || ! url ) {
			return '';
		}
		try {
			var parsed = new URL( url, window.location.href );
			return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
		} catch ( e ) {
			return '';
		}
	}

	function setText( element, value ) {
		if ( element.textContent !== value ) {
			element.textContent = value;
		}
	}

	function setHidden( element, hidden ) {
		if ( element.hidden !== hidden ) {
			element.hidden = hidden;
		}
	}

	function setClass( element, className ) {
		if ( element.className !== className ) {
			element.className = className;
		}
	}

	function isActive( job ) {
		return !! ( job && job.is_active );
	}

	// After a cancel, a batch that was already running still saves its counts, so keep polling until it lets go.
	function needsPoll( job ) {
		return isActive( job ) || !! ( job && job.runner_busy );
	}

	function isVisible( element ) {
		return !! element && ! element.disabled && element.offsetParent !== null;
	}

	function showMessage( message, source ) {
		if ( state.messageSource === source && state.messageText === message ) {
			return;
		}
		clearMessage();

		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-error inline';
		var paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		notice.appendChild( paragraph );
		ui.message.appendChild( notice );

		state.messageSource = source;
		state.messageText = message;
	}

	function clearMessage( source ) {
		if ( source && source !== state.messageSource ) {
			return;
		}
		while ( ui.message.firstChild ) {
			ui.message.removeChild( ui.message.firstChild );
		}
		state.messageSource = '';
		state.messageText = '';
	}

	function requestError( status, payload ) {
		// admin-ajax answers 0 / -1 when the user is logged out or the nonce check dies.
		var expired = status === 403 || payload === 0 || payload === -1;
		var serverMessage = payload && payload.data && typeof payload.data.message === 'string' ? payload.data.message : '';
		var error = new Error( serverMessage || ( expired ? i18n.sessionExpired : i18n.requestFailed ) || '' );
		error.status = status;
		error.expired = expired;
		return error;
	}

	function request( action, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( payload ) {
				if ( payload && payload.success === true ) {
					return payload.data || {};
				}
				throw requestError( response.status, payload );
			} );
		}, function () {
			throw requestError( 0, null );
		} );
	}

	function applyJob( seq, job ) {
		if ( seq < state.appliedSeq ) {
			return false;
		}
		state.appliedSeq = seq;
		state.job = job && typeof job === 'object' ? job : null;
		return true;
	}

	function schedulePoll( delay ) {
		clearTimeout( state.timer );
		state.timer = null;

		if ( state.stopped || state.polling || ! needsPoll( state.job ) ) {
			return;
		}

		if ( typeof delay !== 'number' ) {
			// A waiting job retries on a 30s+ schedule, so fast polling would only add load.
			delay = document.hidden || ! isActive( state.job ) || state.job.status === 'waiting' ? slowPollInterval : pollInterval;
		}

		state.timer = setTimeout( poll, delay );
	}

	function poll() {
		clearTimeout( state.timer );
		state.timer = null;

		if ( state.polling || state.stopped || state.busy ) {
			return;
		}

		state.polling = true;
		var seq = ++state.seq;

		request( 'a8csp_cws_sync_job_status' ).then( function ( data ) {
			state.polling = false;
			if ( applyJob( seq, data.job ) ) {
				clearMessage( 'poll' );
			}
			render();
			schedulePoll();
		}, function ( error ) {
			state.polling = false;
			if ( seq >= state.appliedSeq ) {
				if ( error.expired ) {
					state.stopped = true;
					showMessage( error.message, 'poll' );
				} else {
					showMessage( i18n.statusFailed || error.message, 'poll' );
				}
			}
			render();
			schedulePoll( slowPollInterval );
		} );
	}

	function focusAfterAction( button ) {
		var active = document.activeElement;
		if ( active && active !== document.body && active !== button ) {
			return;
		}

		var targets = [ button, ui.statusHeading, ui.start ];
		for ( var i = 0; i < targets.length; i++ ) {
			if ( isVisible( targets[ i ] ) ) {
				targets[ i ].focus();
				return;
			}
		}
	}

	function runAction( action, fields, button ) {
		if ( state.busy || state.stopped ) {
			return;
		}

		state.busy = true;
		clearMessage();
		render();
		var seq = ++state.seq;

		request( action, fields ).then( function ( data ) {
			state.busy = false;
			applyJob( seq, data.job );
			render();
			focusAfterAction( button );
			schedulePoll();
		}, function ( error ) {
			state.busy = false;
			if ( error.expired ) {
				state.stopped = true;
			}
			showMessage( error.message, 'action' );
			render();
			focusAfterAction( button );
			if ( error.status === 409 ) {
				poll();
			} else {
				schedulePoll();
			}
		} );
	}

	function renderError( job, status ) {
		var message = text( job.last_error );
		var waiting = status === 'waiting';

		setHidden( ui.error, ! message && ! waiting );
		setClass( ui.error, 'notice inline a8csp-sync-job-error ' + ( status === 'failed' ? 'notice-error' : 'notice-warning' ) );
		setHidden( ui.errorLine, ! message );
		setText( ui.errorText, message );
		setHidden( ui.nextRun, ! waiting );

		if ( waiting ) {
			var human = text( job.next_run_human );
			var upcoming = toInt( job.next_run_at ) * 1000 > Date.now();
			setText( ui.nextRun, upcoming && human ? format( i18n.nextRetry, human ) : ( i18n.retryingSoon || '' ) );
		}
	}

	function renderFailures( job ) {
		var failures = Array.isArray( job.failures ) ? job.failures : [];
		var failedCount = toInt( job.failed );

		setHidden( ui.failures, failures.length === 0 && failedCount === 0 );
		setText( ui.failuresSummary, format( i18n.failuresSummary, num( failedCount ) ) );

		var key = JSON.stringify( failures );
		if ( key !== state.failuresKey ) {
			state.failuresKey = key;
			while ( ui.failuresList.firstChild ) {
				ui.failuresList.removeChild( ui.failuresList.firstChild );
			}

			failures.forEach( function ( failure ) {
				if ( ! failure || typeof failure !== 'object' ) {
					return;
				}

				var item = document.createElement( 'li' );
				var href = safeUrl( failure.edit_url );
				var title = document.createElement( href ? 'a' : 'strong' );
				if ( href ) {
					title.href = href;
				}
				title.textContent = text( failure.title ) || format( i18n.postFallback, toInt( failure.post_id ) );
				item.appendChild( title );

				var message = text( failure.message );
				if ( message ) {
					item.appendChild( document.createTextNode( ' — ' ) );
					var detail = document.createElement( 'span' );
					detail.className = 'a8csp-sync-job-failure-message';
					detail.textContent = message;
					item.appendChild( detail );
				}

				ui.failuresList.appendChild( item );
			} );
		}

		var truncated = failures.length > 0 && failedCount > failures.length;
		setHidden( ui.failuresMore, ! truncated );
		if ( truncated ) {
			setText( ui.failuresMore, format( i18n.failuresMore, num( failures.length ), num( failedCount ) ) );
		}
	}

	function render() {
		var job = state.job;
		var active = isActive( job );
		if ( active ) {
			state.sawActive = true;
		}

		var locked = state.busy || state.stopped;
		var startable = settingsReady && matchingCount > 0 && ! active;
		ui.start.disabled = locked || ! startable;
		ui.skipSynced.disabled = locked || ! startable;

		setHidden( ui.status, ! job );
		if ( ! job ) {
			state.failuresKey = null;
			return;
		}

		var status = STATUSES.indexOf( job.status ) === -1 ? 'unknown' : job.status;
		setClass( ui.statusLabel, 'a8csp-sync-job-badge is-' + status );
		setText( ui.statusLabel, text( job.status_label ) || status );
		ui.spinner.classList.toggle( 'is-active', active && ! state.stopped );
		setText( ui.filters, text( job.filters_label ) );

		var total = toInt( job.total );
		var processed = Math.min( toInt( job.processed ), total );
		ui.progress.max = Math.max( total, 1 );
		ui.progress.value = total > 0 ? processed : ( active ? 0 : 1 );
		setText( ui.progressText, format( i18n.progress, num( processed ), num( total ) ) );

		setText( ui.countSynced, num( job.synced ) );
		setText( ui.countSkipped, num( job.skipped ) );
		setText( ui.countFailed, num( job.failed ) );
		setHidden( ui.skippedAtStart, toInt( job.skipped_at_start ) === 0 );
		setText( ui.countSkippedAtStart, num( job.skipped_at_start ) );

		var meta = [];
		if ( toInt( job.started_at ) ) {
			meta.push( format( i18n.startedAt, formatTime( job.started_at ) ) );
		}
		if ( ! active && toInt( job.finished_at ) ) {
			meta.push( format( i18n.finishedAt, formatTime( job.finished_at ) ) );
		}
		setText( ui.meta, meta.join( ' · ' ) );
		setHidden( ui.meta, meta.length === 0 );

		renderError( job, status );
		renderFailures( job );

		setHidden( ui.cancel, ! active );
		setHidden( ui.retry, active || ! job.has_failures );
		setHidden( ui.dismiss, active );
		setHidden( ui.reload, ! state.stopped && ( active || ! state.sawActive || ( toInt( job.synced ) === 0 && job.status !== 'cancelled' ) ) );
		ui.cancel.disabled = locked;
		ui.retry.disabled = locked;
		ui.dismiss.disabled = locked;
	}

	ui.start.addEventListener( 'click', function () {
		runAction( 'a8csp_cws_sync_job_start', {
			post_type: panel.getAttribute( 'data-post-type' ) || 'post',
			category: panel.getAttribute( 'data-category' ) || '0',
			tag: panel.getAttribute( 'data-tag' ) || '0',
			skip_synced: ui.skipSynced.checked ? '1' : '0'
		}, ui.start );
	} );

	ui.cancel.addEventListener( 'click', function () {
		if ( window.confirm( i18n.confirmCancel ) ) {
			runAction( 'a8csp_cws_sync_job_cancel', {}, ui.cancel );
		}
	} );

	ui.retry.addEventListener( 'click', function () {
		runAction( 'a8csp_cws_sync_job_retry_failed', {}, ui.retry );
	} );

	ui.dismiss.addEventListener( 'click', function () {
		runAction( 'a8csp_cws_sync_job_dismiss', {}, ui.dismiss );
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && needsPoll( state.job ) && ! state.polling ) {
			schedulePoll( 0 );
		}
	} );

	render();
	if ( needsPoll( state.job ) ) {
		poll();
	}
} )();
