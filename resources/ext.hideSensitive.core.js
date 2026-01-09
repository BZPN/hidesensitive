
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
		$( container ).find( 'a[data-sensitive="true"]' ).each( function () {
			const $link = $( this );
			// Only process if not already processed
			if ( $link.find( '.sensitive-content-overlay-wrapper' ).length > 0 ) {
				return;
			}

			const $thumb = $link.find( '.thumbimage' );
			if ( $thumb.length > 0 ) {
				$thumb.css( 'display', 'none' );
			}

			const overlayHTML = getOverlayHTML( this );
			const $overlay = $( overlayHTML );

			const width = $link.data( 'width' );
			const height = $link.data( 'height' );
			if ( width && height ) {
				$overlay.find( '.sensitive-content-overlay' ).css( { width: width, height: height } );
			}

			$link.prepend( $overlay );
		} );
	}

	function initFilePage() {
		if ( mw.config.get( 'wgIsSensitiveFilePage' ) ) {
			const $file = $( '#file' );
			if ( $file.length > 0 && $file.find( '.sensitive-content-overlay-wrapper' ).length === 0 ) {
				const overlayHTML = getOverlayHTML( null ); // No link, so use default description
				const $overlay = $( overlayHTML );
				const $img = $file.find( 'a > img' );
				
				if ($img.length > 0) {
					$overlay.find( '.sensitive-content-overlay' ).css( { 
						width: $img.width(), 
						height: $img.height(),
						position: 'absolute',
						top: 0,
						left: 0
					} );
					$img.css('display', 'none');
					$file.find('a').prepend($overlay);
				}
			}
		}
	}
	
	// --- Event Delegation for all "Show" buttons ---
	// This handles thumbnails, videos, and file pages in one go.
	$( document ).on( 'click', '.sensitive-content-button', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		const $wrapper = $( this ).closest( '.sensitive-content-overlay-wrapper' );
		const $container = $wrapper.parent();
		
		// For thumbnails and file pages, we unhide the image
		$container.find( '.thumbimage, #file img' ).css( 'display', 'block' );

		// For videos, there's no hidden element, we need to replace the overlay
		// This part is complex and would require loading the video player dynamically.
		// For now, we just remove the overlay. A full implementation would use mw.loader.load
		// to get the video player modules and re-render.
		
		$wrapper.remove();
	});


	// --- Hooks and Initialization ---

	// For standard page loads and dynamic content (like VisualEditor)
	mw.hook( 'wikipage.content' ).add( function ( content ) {
		initThumbnails( content );
	} );

	// Run on DOM ready for file pages
	$( initFilePage );

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

