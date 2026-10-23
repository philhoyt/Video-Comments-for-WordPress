/**
 * Video Comments — front-end uploader script.
 *
 * Handles:
 *  - File selection & client-side validation (size, type)
 *  - Requesting a Mux direct-upload URL from WordPress REST
 *  - XHR PUT to Mux direct-upload URL with progress tracking
 *  - Polling WP REST for upload/asset status until ready
 *  - Setting hidden form fields with the upload ID and its token (the server
 *    resolves the playback/asset IDs from Mux when the comment is saved)
 *  - Blocking comment form submission while upload is in progress
 *
 * No build step required — plain vanilla JS (ES2017+ for async/await).
 * The global `vcSettings` object is injected via wp_add_inline_script().
 *
 * @typedef {Object} VcSettings
 * @property {string}  restUrl        Base REST URL for the plugin namespace.
 * @property {string}  nonce          Custom upload nonce.
 * @property {string}  restNonce      WP REST nonce.
 * @property {number}  maxSizeMb      Maximum allowed file size in MB.
 * @property {boolean} hasCredentials Whether Mux credentials are configured.
 * @property {boolean} allowGuests    Whether guests may upload.
 * @property {boolean} isLoggedIn     Whether the current user is logged in.
 * @property {boolean} allowVideoOnly Whether a comment may be a video with no text on this site.
 * @property {Object}  i18n           Localised strings.
 */

( function() {
	'use strict';

	/** @type {VcSettings} */
	const cfg = window.vcSettings || {};
	const i18n = cfg.i18n || {};

	// -------------------------------------------------------------------------
	// DOM refs (populated in init)
	// -------------------------------------------------------------------------
	let form = null;
	let fileInput = null;
	let dropzone = null;
	let dropzonePrimary = null;
	let dropzoneSecondary = null;
	let uploadBtn = null;
	let clearBtn = null;
	let progressWrap = null;
	let progressBar = null;
	let progressPct = null;
	let statusEl = null;
	let uploadIdField = null;
	let uploadTokenField = null;
	let submitBtn = null;
	let commentField = null; // The comment textarea (#comment).
	let commentRequiredMark = null; // Its label's required indicator, if any.

	// Original dropzone text — stored during init so resetState can restore them.
	let defaultPrimaryText = '';
	let defaultSecondaryText = '';

	// Whether the comment textarea started out required (and with aria-required),
	// so setTextRequired() only ever restores what the theme rendered.
	let commentWasRequired = false;
	let commentHadAriaRequired = false;

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------
	const state = {
		file: null, // File object selected by user
		uploading: false, // XHR in flight
		processing: false, // Waiting for Mux asset to be ready
		playbackId: '', // Resolved playback ID (preview only)
		uploadId: '', // Mux upload_id from WP REST
		uploadToken: '', // Per-upload secret from WP REST; proves ownership
		pollTimer: null, // setInterval handle for status polling
		xhr: null, // Active XHR for cancel support
		generation: 0, // Bumped on clear/reselect so stale async work bails out
	};

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	function init() {
		form = document.getElementById( 'commentform' );
		if ( ! form ) {
			return;
		}

		fileInput = document.getElementById( 'vc-file-input' );
		dropzone = document.getElementById( 'vc-dropzone' );
		dropzonePrimary = document.getElementById( 'vc-dropzone-primary' );
		dropzoneSecondary = document.getElementById( 'vc-dropzone-secondary' );
		uploadBtn = document.getElementById( 'vc-upload-btn' );
		clearBtn = document.getElementById( 'vc-clear-btn' );
		progressWrap = document.getElementById( 'vc-progress-wrap' );
		progressBar = document.getElementById( 'vc-progress' );
		progressPct = document.getElementById( 'vc-progress-pct' );
		statusEl = document.getElementById( 'vc-status' );
		uploadIdField = document.getElementById( 'vc-upload-id' );
		uploadTokenField = document.getElementById( 'vc-upload-token' );

		// Try inside the form first; block themes sometimes render the submit
		// button outside <form>, so fall back to a document-wide search.
		submitBtn = form.querySelector( 'input[type="submit"], button[type="submit"]' ) ||
			document.querySelector( '#submit, input[type="submit"], button[type="submit"]' );

		if ( ! fileInput ) {
			return;
		} // Uploader section not rendered (no creds / guest disabled).

		// The comment textarea and its required indicator: once a video is ready,
		// text becomes optional and the browser must stop blocking an empty submit.
		commentField = document.getElementById( 'comment' );
		if ( commentField ) {
			commentWasRequired = commentField.required;
			commentHadAriaRequired = commentField.hasAttribute( 'aria-required' );
			const label = form.querySelector( 'label[for="comment"]' ) || document.querySelector( 'label[for="comment"]' );
			commentRequiredMark = label ? label.querySelector( '.required' ) : null;
		}

		// Store the default drop zone text so resetState can restore it.
		if ( dropzonePrimary ) {
			defaultPrimaryText = dropzonePrimary.textContent.trim();
		}
		if ( dropzoneSecondary ) {
			defaultSecondaryText = dropzoneSecondary.textContent.trim();
		}

		fileInput.addEventListener( 'change', onFileChange );
		uploadBtn.addEventListener( 'click', onUploadClick );
		clearBtn.addEventListener( 'click', onClear );
		form.addEventListener( 'submit', onFormSubmit );

		// Drag-and-drop support on the drop zone.
		if ( dropzone ) {
			dropzone.addEventListener( 'dragover', function( e ) {
				e.preventDefault();
				dropzone.classList.add( 'vc-dropzone--dragover' );
			} );
			dropzone.addEventListener( 'dragleave', function() {
				dropzone.classList.remove( 'vc-dropzone--dragover' );
			} );
			dropzone.addEventListener( 'drop', function( e ) {
				e.preventDefault();
				dropzone.classList.remove( 'vc-dropzone--dragover' );
				const file = e.dataTransfer && e.dataTransfer.files[ 0 ];
				if ( file ) {
					handleFileSelection( file );
				}
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Event handlers
	// -------------------------------------------------------------------------

	function onFileChange() {
		const file = fileInput.files[ 0 ] || null;
		if ( ! file ) {
			resetState(); return;
		}
		handleFileSelection( file );
	}

	/**
	 * Validate and accept a file, updating the drop zone and showing the action row.
	 * Called by both onFileChange (picker) and the drop handler.
	 *
	 * @param {File} file
	 */
	function handleFileSelection( file ) {
		// Picking a new file while an upload is in flight or finished replaces it:
		// cancel and clean up the old one first so nothing stale lands in the form.
		if ( state.uploading || state.processing || state.uploadId ) {
			onClear();
		}

		// Type check.
		if ( ! file.type.startsWith( 'video/' ) ) {
			setStatus( i18n.invalidType || 'Please select a video file.', 'error' );
			return;
		}

		// Size check.
		const maxBytes = ( cfg.maxSizeMb || 50 ) * 1024 * 1024;
		if ( file.size > maxBytes ) {
			setStatus( i18n.fileTooLarge || 'File is too large.', 'error' );
			return;
		}

		// Update drop zone to reflect the selected file.
		state.file = file;
		if ( dropzone ) {
			dropzone.classList.add( 'vc-dropzone--has-file' );
		}
		if ( dropzonePrimary ) {
			dropzonePrimary.textContent = file.name;
		}
		if ( dropzoneSecondary ) {
			dropzoneSecondary.textContent = formatBytes( file.size );
		}

		// Enable upload, reveal remove, block form submission until video is ready.
		if ( uploadBtn ) {
			uploadBtn.disabled = false;
		}
		if ( clearBtn ) {
			clearBtn.hidden = false;
		}
		lockForm( true );

		setStatus( '' );
	}

	function onUploadClick() {
		if ( ! state.file ) {
			return;
		}
		startUpload( state.file );
	}

	function onClear() {
		// Invalidate any request that is still in flight for the old upload.
		state.generation++;

		if ( state.xhr ) {
			state.xhr.abort();
		}
		clearInterval( state.pollTimer );

		// Delete the uploaded asset from Mux (best-effort, fire-and-forget).
		// The server resolves the asset from the upload; the token proves ownership.
		if ( state.uploadId && state.uploadToken ) {
			const params = new URLSearchParams( {
				nonce: cfg.nonce,
				upload_id: state.uploadId,
				upload_token: state.uploadToken,
			} );
			fetch( cfg.restUrl + '/mux/video?' + params.toString(), {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': cfg.restNonce },
				credentials: 'same-origin',
			} ).catch( function() {} );
		}

		resetState();
	}

	/**
	 * Prevent form submission while an upload or processing step is pending.
	 *
	 * @param {SubmitEvent} e
	 */
	function onFormSubmit( e ) {
		if ( state.uploading || state.processing ) {
			e.preventDefault();
			setStatus( i18n.pleaseWait || 'Upload in progress. Please wait.', 'info' );
		}
	}

	// -------------------------------------------------------------------------
	// Upload flow
	// -------------------------------------------------------------------------

	/**
	 * Full upload pipeline:
	 *  1. Request upload URL from WP REST
	 *  2. PUT file to Mux
	 *  3. Poll WP REST until playback_id is available
	 *
	 * @param {File} file
	 */
	async function startUpload( file ) {
		const gen = state.generation;

		lockForm( true );
		state.uploading = true;
		if ( uploadBtn ) {
			uploadBtn.disabled = true;
		}
		showProgress( true );
		setStatus( i18n.uploading || 'Uploading…', 'info' );

		try {
			// Step 1: get direct upload URL.
			const uploadData = await requestDirectUploadUrl( file );
			if ( gen !== state.generation ) {
				return; // Cleared while waiting; the server will sweep the unused upload.
			}
			state.uploadId = uploadData.upload_id;
			state.uploadToken = uploadData.upload_token || '';

			// Step 2: upload file directly to Mux.
			await uploadToMux( uploadData.upload_url, file );
			if ( gen !== state.generation ) {
				return;
			}

			state.uploading = false;
			showProgress( false );
			state.processing = true;
			startProcessingAnimation();

			// Step 3: poll for playback_id.
			await pollForPlaybackId( state.uploadId, gen );
		} catch ( err ) {
			if ( gen !== state.generation ) {
				return; // Stale failure (e.g. the abort we triggered ourselves); UI already reset.
			}
			state.uploading = false;
			state.processing = false;
			showProgress( false );
			stopProcessingAnimation();
			lockForm( false );
			// Re-enable upload so the user can retry.
			if ( state.file && uploadBtn ) {
				uploadBtn.disabled = false;
			}
			setStatus( ( err && err.message ) || i18n.uploadError || 'Upload failed. Please try again.', 'error' );
		}
	}

	/**
	 * Call WP REST to get a Mux direct-upload URL.
	 *
	 * @param {File} file
	 * @return {Promise<{upload_id: string, upload_url: string, upload_token: string}>} Resolves with upload ID, URL and ownership token.
	 */
	async function requestDirectUploadUrl( file ) {
		const body = new FormData();
		body.append( 'nonce', cfg.nonce );
		body.append( 'file_name', file.name );
		body.append( 'file_size', String( file.size ) );

		const res = await fetch( cfg.restUrl + '/mux/direct-upload', {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.restNonce },
			body,
			credentials: 'same-origin',
		} );

		const json = await res.json();

		if ( ! res.ok ) {
			throw new Error( json.message || i18n.uploadError );
		}

		return json;
	}

	/**
	 * PUT the raw video file to the Mux direct-upload URL.
	 * Tracks progress via XHR so we can update the progress bar.
	 *
	 * @param {string} uploadUrl Mux direct upload URL (pre-signed).
	 * @param {File}   file      File to upload.
	 * @return {Promise<void>}
	 */
	function uploadToMux( uploadUrl, file ) {
		return new Promise( function( resolve, reject ) {
			const xhr = new XMLHttpRequest();
			state.xhr = xhr;

			xhr.open( 'PUT', uploadUrl, true );
			// Mux direct upload expects the raw binary via PUT.
			xhr.setRequestHeader( 'Content-Type', file.type || 'video/mp4' );

			xhr.upload.addEventListener( 'progress', function( e ) {
				if ( e.lengthComputable ) {
					const pct = Math.round( ( e.loaded / e.total ) * 100 );
					setProgress( pct );
				}
			} );

			xhr.addEventListener( 'load', function() {
				state.xhr = null;
				if ( xhr.status >= 200 && xhr.status < 300 ) {
					setProgress( 100 );
					resolve();
				} else {
					reject( new Error( i18n.uploadError || 'Upload failed.' ) );
				}
			} );

			xhr.addEventListener( 'error', function() {
				state.xhr = null;
				reject( new Error( i18n.uploadError || 'Upload failed.' ) );
			} );

			xhr.addEventListener( 'abort', function() {
				state.xhr = null;
				reject( new Error( i18n.cancelled || 'Upload cancelled.' ) );
			} );

			xhr.send( file );
		} );
	}

	/**
	 * Poll WP REST GET /mux/upload-status until we get a playback_id.
	 *
	 * @param {string} uploadId
	 * @param {number} gen      Generation this poll belongs to; results for an
	 *                          older generation are discarded.
	 * @return {Promise<void>}
	 */
	function pollForPlaybackId( uploadId, gen ) {
		return new Promise( function( resolve, reject ) {
			let attempts = 0;
			const maxAttempts = 60; // 60 × 3 s = 3 min timeout.

			state.pollTimer = setInterval( async function() {
				attempts++;

				if ( attempts > maxAttempts ) {
					clearInterval( state.pollTimer );
					state.processing = false;
					lockForm( false );
					reject( new Error( i18n.timeout || 'Timed out waiting for the video to process. Please try again.' ) );
					return;
				}

				try {
					const url = new URL( cfg.restUrl + '/mux/upload-status' );
					url.searchParams.set( 'upload_id', uploadId );
					url.searchParams.set( 'upload_token', state.uploadToken );
					url.searchParams.set( 'nonce', cfg.nonce );

					const res = await fetch( url.toString(), {
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.restNonce },
					} );
					const json = await res.json();

					if ( gen !== state.generation ) {
						return; // Cleared while this request was in flight.
					}

					if ( ! res.ok ) {
						// Non-fatal: keep polling unless explicitly errored.
						if ( json.code === 'vc_mux_api_error' ) {
							clearInterval( state.pollTimer );
							state.processing = false;
							lockForm( false );
							reject( new Error( json.message || i18n.uploadError ) );
						}
						return;
					}

					if ( json.status === 'ready' && json.playback_id ) {
						clearInterval( state.pollTimer );
						state.pollTimer = null;
						state.processing = false;
						state.playbackId = json.playback_id;
						if ( uploadIdField ) {
							uploadIdField.value = state.uploadId;
						}
						if ( uploadTokenField ) {
							uploadTokenField.value = state.uploadToken;
						}
						stopProcessingAnimation();
						lockForm( false );
						setTextRequired( false );
						setStatus(
							cfg.allowVideoOnly
								? ( i18n.readyVideoOnly || 'Video ready. You can post it on its own or add a comment below.' )
								: ( i18n.ready || 'Video ready' ),
							'success'
						);
						showPreviewPlayer( json.playback_id );
						resolve();
					} else if ( json.status === 'errored' ) {
						clearInterval( state.pollTimer );
						state.processing = false;
						stopProcessingAnimation();
						lockForm( false );
						reject( new Error( i18n.processingFailed || 'Video processing failed. Please try another file.' ) );
					}
					// 'waiting' | 'asset_created' → keep polling.
				} catch {
					// Network error during poll — keep trying.
				}
			}, 3000 );
		} );
	}

	// -------------------------------------------------------------------------
	// UI helpers
	// -------------------------------------------------------------------------

	/**
	 * Reset all state and UI back to the initial empty state.
	 */
	function resetState() {
		clearInterval( state.pollTimer );
		stopProcessingAnimation();
		state.file = null;
		state.uploading = false;
		state.processing = false;
		state.playbackId = '';
		state.uploadId = '';
		state.uploadToken = '';
		state.pollTimer = null;
		state.xhr = null;

		if ( fileInput ) {
			fileInput.value = '';
		}
		if ( uploadIdField ) {
			uploadIdField.value = '';
		}
		if ( uploadTokenField ) {
			uploadTokenField.value = '';
		}

		// Reset drop zone and buttons.
		if ( dropzone ) {
			dropzone.classList.remove( 'vc-dropzone--has-file', 'vc-dropzone--dragover' );
		}
		if ( dropzonePrimary ) {
			dropzonePrimary.textContent = defaultPrimaryText;
		}
		if ( dropzoneSecondary ) {
			dropzoneSecondary.textContent = defaultSecondaryText;
		}
		if ( uploadBtn ) {
			uploadBtn.disabled = true;
		}
		if ( clearBtn ) {
			clearBtn.hidden = true;
		}

		removePreviewPlayer();
		showProgress( false );
		setProgress( 0 );
		setStatus( '' );
		lockForm( false );
		setTextRequired( true );
	}

	/**
	 * Make the comment textarea required or optional. Text is optional only while
	 * a ready video is attached and the site allows video-only comments; the
	 * server enforces the same rule, so this only changes what the browser blocks.
	 * No-op when the theme did not render the field as required to begin with.
	 *
	 * @param {boolean} required
	 */
	function setTextRequired( required ) {
		if ( ! commentField || ! commentWasRequired || ! cfg.allowVideoOnly ) {
			return;
		}
		commentField.required = required;
		if ( commentHadAriaRequired ) {
			commentField.setAttribute( 'aria-required', required ? 'true' : 'false' );
		}
		if ( commentRequiredMark ) {
			commentRequiredMark.hidden = ! required;
		}
	}

	/**
	 * Disable or enable the comment form submit button.
	 *
	 * @param {boolean} locked
	 */
	function lockForm( locked ) {
		if ( submitBtn ) {
			submitBtn.disabled = locked;
			submitBtn.style.opacity = locked ? '0.4' : '';
			submitBtn.style.cursor = locked ? 'not-allowed' : '';
		}
	}

	/**
	 * Show or hide the progress bar wrapper.
	 *
	 * @param {boolean} visible
	 */
	function showProgress( visible ) {
		if ( progressWrap ) {
			progressWrap.style.display = visible ? 'flex' : 'none';
			progressWrap.setAttribute( 'aria-hidden', String( ! visible ) );
		}
	}

	/**
	 * Update progress bar to a percentage value.
	 *
	 * @param {number} pct 0–100
	 */
	function setProgress( pct ) {
		if ( progressBar ) {
			progressBar.value = pct;
		}
		if ( progressPct ) {
			progressPct.textContent = pct + '%';
		}
	}

	/**
	 * Set the status message text and optional type class.
	 *
	 * @param {string}                      msg
	 * @param {'info'|'success'|'error'|''} [type]
	 */
	function setStatus( msg, type ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = msg;
		statusEl.className = 'vc-status' + ( type ? ' vc-status--' + type : '' );
	}

	/**
	 * Show the processing status with a CSS spinner. The live region's text is
	 * written once; the spinner is a ::after pseudo-element, so screen readers
	 * are not re-announced several times a second.
	 */
	function startProcessingAnimation() {
		setStatus( i18n.processing || 'Processing video…', 'info' );
		if ( statusEl ) {
			statusEl.classList.add( 'vc-status--processing' );
		}
	}

	function stopProcessingAnimation() {
		if ( statusEl ) {
			statusEl.classList.remove( 'vc-status--processing' );
		}
	}

	/**
	 * Insert a live <mux-player> preview directly inside the uploader panel
	 * so the user can watch the video back before submitting.
	 *
	 * @param {string} playbackId Mux playback ID.
	 */
	function showPreviewPlayer( playbackId ) {
		removePreviewPlayer(); // Guard against duplicates.

		const wrap = document.getElementById( 'vc-uploader' );
		if ( ! wrap ) {
			return;
		}

		const preview = document.createElement( 'div' );
		preview.id = 'vc-preview';
		preview.className = 'vc-preview';

		const player = document.createElement( 'mux-player' );
		player.setAttribute( 'playback-id', playbackId );
		player.setAttribute( 'controls', '' );
		player.setAttribute( 'playsinline', '' );

		preview.appendChild( player );
		wrap.appendChild( preview );
	}

	function removePreviewPlayer() {
		const existing = document.getElementById( 'vc-preview' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
	}

	/**
	 * Format a byte count as a human-readable string (KB or MB).
	 *
	 * @param {number} bytes
	 * @return {string} Human-readable size string.
	 */
	function formatBytes( bytes ) {
		if ( bytes < 1024 * 1024 ) {
			return ( i18n.kb || '%s KB' ).replace( '%s', ( bytes / 1024 ).toFixed( 1 ) );
		}
		return ( i18n.mb || '%s MB' ).replace( '%s', ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) );
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
