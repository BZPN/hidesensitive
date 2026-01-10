
( function ( mw, $ ) {
	'use strict';

	/**
	 * Creates the HTML structure for the sensitive content overlay.
	 * @param {HTMLElement} sourceElement The element that triggered the overlay.
	 * @return {jQuery} A jQuery object representing the overlay.
	 */
	function createOverlay( sourceElement ) {
		const config = mw.config.get( 'wgSensitiveContent' ) || {};
		const messages = mw.config.get( 'wgSensitiveMessages' ) || {};
		const description = sourceElement && sourceElement.dataset.description
			? sourceElement.dataset.description
			: messages['sensitive-default-description'] || 'This content has been marked as sensitive.';
		const infoPage = config.infoPage || 'Help:Sensitive_content';
		const learnMoreUrl = mw.util.getUrl( infoPage );

		const $overlay = $( '<div>' ).addClass( 'sensitive-content-overlay-wrapper' )
			.append( $( '<div>' ).addClass( 'sensitive-content-icon' ) )
			.append( $( '<div>' ).addClass( 'sensitive-content-text' ).text( description ) )
			.append(
				$( '<div>' ).addClass( 'sensitive-content-buttons' )
					.append(
						$( '<a>' ).addClass( 'sensitive-content-button-learn' )
							.attr( 'href', learnMoreUrl )
							.text( messages['sensitive-learn-more'] || 'Learn More' )
					)
					.append(
						$( '<button>' ).addClass( 'sensitive-content-button-show' )
							.text( messages['sensitive-show-content'] || 'Show Content' )
					)
			);
		return $overlay;
	}

	/**
	 * Hides the media element and prepends the overlay.
	 * @param {jQuery} $el The element to apply the overlay to.
	 */
	function applyOverlay( $el ) {
		// Only process if not already processed
		if ( $el.hasClass( 'hs-processed' ) || $el.find( '.sensitive-content-overlay-wrapper' ).length > 0 ) {
			return;
		}

		const $media = $el.is( 'img, video' ) ? $el : $el.find( '.thumbimage, img, video, .video-js' );
		if ( $media.length > 0 ) {
			$media.css( 'visibility', 'hidden' );
		}

		const $overlay = createOverlay( $el[ 0 ] );

		// Event handler to show the content
		const showContent = function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			$overlay.remove();
			if ( $media.length > 0 ) {
				$media.css( 'visibility', 'visible' );
			}
			$el.removeClass( 'hs-processed' ); // Allow re-application if needed
		};

		$overlay.on( 'click', '.sensitive-content-button-show', showContent );
		$overlay.on( 'click', showContent ); // Click anywhere on the overlay to show

		if ( $el.is( 'img, video' ) ) {
			$el.before( $overlay );
		} else {
			$el.prepend( $overlay );
		}
		$el.addClass( 'hs-processed' );
	}

	function initThumbnails( container ) {
		$( container ).find( '[data-sensitive="true"]' ).each( function () {
			applyOverlay( $( this ) );
		} );
	}

	// --- Hooks and Initialization ---

	// For standard page loads and dynamic content
	mw.hook( 'wikipage.content' ).add( function ( content ) {
		initThumbnails( content );
	} );

	// Use MutationObserver to catch dynamically added content
	const observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			if ( mutation.addedNodes.length ) {
				$( mutation.addedNodes ).each( function () {
					const $node = $( this );
					if ( $node.is( '[data-sensitive="true"]' ) ) {
						applyOverlay( $node );
					}
					$node.find( '[data-sensitive="true"]' ).each( function () {
						applyOverlay( $( this ) );
					} );
				} );
			}
		} );
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true
	} );


	// --- MultimediaViewer Integration ---
	let currentViewer = null;
	let currentSourceLink = null;

	mw.hook( 'mmv.viewer.before-opening' ).add( function ( viewer ) {
		currentViewer = viewer;
		// The source link can be the element itself or a parent anchor
		const $sourceElement = viewer.element.closest( '[data-sensitive="true"]' );
		if ( $sourceElement.length > 0 ) {
			// Mark as sensitive so we can act on it when the image loads
			viewer.element.dataset.mmvIsSensitive = 'true';
			currentSourceLink = $sourceElement[0];
		} else {
			viewer.element.dataset.mmvIsSensitive = 'false';
			currentSourceLink = null;
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

		const $overlay = createOverlay( currentSourceLink );

		// Special styling for MMV
		$overlay.css( {
			position: 'absolute',
			top: 0,
			left: 0,
			width: '100%',
			height: '100%',
			zIndex: 1000
		} );

		$viewerNode.parent().append( $overlay );

		$overlay.on( 'click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$overlay.remove();
			// Unset sensitive flag so it doesn't re-appear when navigating gallery
			viewer.element.dataset.mmvIsSensitive = 'false';
		});
	});

}( mw, jQuery ) );

