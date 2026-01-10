
( function ( mw, $ ) {
	'use strict';

	/**
	 * Creates the HTML structure for the sensitive content overlay.
	 * @param {HTMLElement} sourceElement The element that triggered the overlay.
	 * @param {string} customDescription Optional custom description.
	 * @return {jQuery} A jQuery object representing the overlay.
	 */
	function createOverlay( sourceElement, customDescription ) {
		const config = mw.config.get( 'wgSensitiveContent' ) || {};
		const description = customDescription || sourceElement?.dataset.description || mw.msg( 'hidesensitive-default-description' );
		const infoPage = config.infoPage || 'Help:Sensitive_content';
		const learnMoreUrl = mw.util.getUrl( infoPage );
		const buttonText = mw.msg( 'hidesensitive-button-text' );
		const buttonColor = config.buttonColor || '#36c';

		const $overlay = $( '<div>' ).addClass( 'sensitive-content-overlay-wrapper' )
			.append( $( '<div>' ).addClass( 'sensitive-content-icon' ) )
			.append( $( '<div>' ).addClass( 'sensitive-content-text' ).text( description ) )
			.append(
				$( '<div>' ).addClass( 'sensitive-content-buttons' )
					.append(
						$( '<a>' )
							.addClass( 'sensitive-content-button-learn' )
							.attr( 'href', learnMoreUrl )
							.append(
								$( '<span>' ).addClass( 'hs-learn-text' )
									.text( mw.msg( 'hidesensitive-learn-more' ) )
							)
							.append(
								$( '<img>' )
									.addClass( 'hs-learn-icon' )
									.attr( 'src', mw.config.get( 'wgExtensionAssetsPath' ) +
										'/HideSensitive/resources/images/info.png'
									)
							)
					)
					.append(
						$( '<button>' ).addClass( 'sensitive-content-button-show' )
							.text( buttonText )
					)
			);
		$overlay.find( '.sensitive-content-button-show' ).css( '--hs-button-color', buttonColor );
		return $overlay;
	}

	/**
	 * Applies overlay to the container of the sensitive marker.
	 * @param {jQuery} $marker The element with data-sensitive marker.
	 */
	function applyOverlay( $marker ) {
		const $container = $marker.closest( '.thumbinner, .gallerybox, .mw-file-element, figure' );
		if ( !$container.length ) {
			return;
		}
		if ( $container.find('.sensitive-content-overlay-wrapper').length ) {
			return;
		}

		$container.css({
			position: 'relative',
			overflow: 'hidden'
		});
		$container.addClass('hs-processed');

		const $overlay = createOverlay( $marker[0] );
		$overlay.css({
			position: 'absolute',
			inset: 0,
			zIndex: 20
		});

		const width = $container.outerWidth();
		const height = $container.outerHeight();

		// zdjęcia typu dowód, portret, małe thumbs
		if ( width < 180 || height < 140 ) {
			$overlay.addClass( 'hs-compact' );
		}

		$container.append( $overlay );

		$overlay.on('click', '.sensitive-content-button-show', function(e) {
			e.preventDefault();
			$overlay.remove();
			$container.removeClass('hs-processed');
		});
	}

	function initThumbnails( container ) {
		$( container ).find( '[data-sensitive="true"]' ).each( function () {
			applyOverlay( $( this ) );
		} );
	}

	// --- Hooks and Initialization ---

	// Special handling for File: pages
	if ( mw.config.get( 'wgHideSensitiveImagePage' ) ) {
		const $file = $( '.fullImageLink img, .fullImageLink video' ).first();
		if ( $file.length ) {
			const $container = $file.parent();
			$container.css( 'position', 'relative' );

			const description =
				mw.config.get('wgHideSensitiveReason') ||
				mw.msg('hidesensitive-default-description');

			const $overlay = createOverlay( null, description );
			$overlay.css( {
				position: 'absolute',
				inset: 0,
				zIndex: 10
			} );

			$file.css( 'opacity', 0 );
			$container.append( $overlay );

			$overlay.on( 'click', '.sensitive-content-button-show', function ( e ) {
				e.preventDefault();
				$overlay.remove();
				$file.css( 'opacity', 1 );
			} );
		}
	}

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

	const target = document.querySelector('.mw-parser-output');
	if ( target ) {
		observer.observe(target, { childList: true, subtree: true });
	}


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

		$viewerNode.css('opacity', '0');

		const $overlay = createOverlay( currentSourceLink );

		// Special styling for MMV
		$overlay.css( {
			position: 'absolute',
			inset: 0,
			zIndex: 1000
		} );

		$viewerNode.parent().append( $overlay );

		$overlay.on( 'click', '.sensitive-content-button-show', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$overlay.remove();
			$viewerNode.css('opacity', '1');
			// Unset sensitive flag so it doesn't re-appear when navigating gallery
			viewer.element.dataset.mmvIsSensitive = 'false';
		});
	});

}( mw, jQuery ) );



