
( function ( mw, $ ) {
	'use strict';

	function getOverlayHTML( link ) {
		const config = mw.config.get( 'wgSensitiveContent' ) || {};
		const description = link && link.dataset.description ? link.dataset.description : mw.msg( 'hidesensitive-default-description' );
		const buttonText = mw.msg( 'hidesensitive-button-text' );
		const buttonColor = config.buttonColor || '#36c';

		// Using a wrapper div to make event delegation easier
		return '<div class="sensitive-content-overlay-wrapper">' +
				'<div class="sensitive-content-overlay">' +
					'<div class="sensitive-content-icon"></div>' +
					'<div class="sensitive-content-text">' + mw.html.escape( description ) + '</div>' +
					'<button class="sensitive-content-button" style="background-color:' + buttonColor + ';">' + mw.html.escape( buttonText ) + '</button>' +
				'</div>' +
			'</div>';
	}

	function initThumbnails( container ) {
		$( container ).find( '[data-sensitive="true"]' ).each( function () {
			const $el = $( this );
			
			// If this is an image/video inside a sensitive link, skip it
			if ( $el.is( 'img, video' ) && $el.closest( 'a[data-sensitive="true"]' ).length > 0 ) {
				return;
			}

			// Only process if not already processed
			if ( $el.find( '.sensitive-content-overlay-wrapper' ).length > 0 || $el.hasClass( 'hs-processed' ) ) {
				return;
			}

			const $media = $el.is( 'img, video' ) ? $el : $el.find( '.thumbimage, img, video, .video-js' );
			if ( $media.length > 0 ) {
				$media.css( 'display', 'none' );
			}

			const overlayHTML = getOverlayHTML( this );
			const $overlay = $( overlayHTML );

			const width = $el.data( 'width' ) || $el.attr( 'width' );
			const height = $el.data( 'height' ) || $el.attr( 'height' );
			if ( width && height ) {
				$overlay.find( '.sensitive-content-overlay' ).css( { width: width, height: height } );
			}

			$overlay.find( '.sensitive-content-button' ).on( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$overlay.remove();
				$media.show();
			} );

			if ( $el.is( 'img, video' ) ) {
				$el.before( $overlay );
			} else {
				$el.prepend( $overlay );
			}
			$el.addClass( 'hs-processed' );
		} );
	}

	// --- Hooks and Initialization ---

	// For standard page loads and dynamic content (like VisualEditor)
	mw.hook( 'wikipage.content' ).add( function ( content ) {
		initThumbnails( content );
	} );

	// --- MultimediaViewer Integration ---
	let currentViewer = null;
	let currentSourceLink = null;

	mw.hook( 'mmv.viewer.before-opening' ).add( function ( viewer ) {
		currentViewer = viewer;
		currentSourceLink = viewer.element.closest( 'a[data-sensitive="true"]' );
		if ( currentSourceLink ) {
			// Mark as sensitive so we can act on it when the image loads
			viewer.element.dataset.mmvIsSensitive = 'true';
		}
	} );

	mw.hook( 'mmv.image.loaded' ).add( function ( image, viewer ) {
		if ( viewer.element.dataset.mmvIsSensitive !== 'true' ) {
			return;
		}

		const $viewerNode = $( viewer.getMediaNode() );
		if ( !$viewerNode || $viewerNode.parent().find( '.sensitive-content-overlay-wrapper' ).length > 0 ) {
			return;
		}
		
		const overlayHTML = getOverlayHTML( currentSourceLink );
		const $overlay = $( overlayHTML );
		
		$overlay.find('.sensitive-content-overlay').css({
			position: 'absolute',
			top: '50%',
			left: '50%',
			transform: 'translate(-50%, -50%)',
			zIndex: 1000,
			width: '300px',
			height: 'auto'
		});
		
		$viewerNode.parent().append( $overlay );

		$overlay.find( '.sensitive-content-button' ).on( 'click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$overlay.remove();
			// Unset sensitive flag so it doesn't re-appear when navigating gallery
			viewer.element.dataset.mmvIsSensitive = 'false'; 
		});
	});

}( mw, jQuery ) );

