
( function ( mw, $ ) {
	'use strict';

	const cfg = mw.config.get( 'wgSensitiveContent' ) || {};
	const infoPage = cfg.infoPage || 'Help:Sensitive_content';

	// Prioritize System Message > $wgSensitiveButtonText > 'Show'
	let buttonText = 'Show';
	if ( cfg.hasOnWikiButtonText ) {
		buttonText = mw.msg( 'hidesensitive-button-text' );
	} else if ( cfg.buttonText ) {
		buttonText = cfg.buttonText;
	}

	const buttonColor = cfg.buttonColor || '#36c';
	const learnMoreUrl = mw.util.getUrl( infoPage );

	function resolveContainer( marker ) {
		// Specific order: from most specific MediaWiki structure to generic
		return (
			marker.closest( 'li.gallerybox' ) ||
			marker.closest( '.thumbinner' ) ||
			marker.closest( 'figure[typeof^="mw:File"]' ) ||
			marker.closest( '.mw-file-element' ) ||
			marker.closest( 'a.hs-marker' ) ||
			marker
		);
	}

	/**
	 * Creates the HTML structure for the sensitive content overlay.
	 * @param {string} reason The reason for hiding the content.
	 * @return {jQuery} A jQuery object representing the overlay.
	 */
	function createOverlay( reason ) {
		const description = reason || mw.msg( 'hidesensitive-default-description' );
		const assetsPath = mw.config.get( 'wgExtensionAssetsPath' ) + '/HideSensitive/resources/images';

		const $overlay = $( '<div>' ).addClass( 'sensitive-content-overlay-wrapper' )
			.append(
				$( '<div>' ).addClass( 'sensitive-content-icon' )
					.css( 'background-image', 'url(' + assetsPath + '/icon.png)' )
			)
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
									.attr( 'src', assetsPath + '/info.png' )
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
	 * Attaches overlay to a container element.
	 * @param {HTMLElement} container The container element.
	 * @param {string} reason The reason for hiding.
	 */
	function attachOverlay( container, reason ) {
		const $container = $( container );

		if ( $container.find( '.sensitive-content-overlay-wrapper' ).length ) {
			return;
		}

		// Ensure container has relative positioning
		if ( $container.css( 'position' ) === 'static' ) {
			$container.css( 'position', 'relative' );
		}

		const $overlay = createOverlay( reason );
		$container.append( $overlay );

		const updateSizeClasses = function() {
			const width = $container.outerWidth();
			const height = $container.outerHeight();

			$overlay.removeClass( 'hs-compact hs-tiny' );
			if ( ( width > 0 && width < 120 ) || ( height > 0 && height < 100 ) ) {
				$overlay.addClass( 'hs-tiny' );
			} else if ( ( width > 0 && width < 200 ) || ( height > 0 && height < 160 ) ) {
				$overlay.addClass( 'hs-compact' );
			}
		};

		updateSizeClasses();

		$overlay.on( 'click', '.sensitive-content-button-show', function( e ) {
			e.preventDefault();
			e.stopPropagation();

			$overlay.remove();
			$container.removeClass( 'hs-container' );
			$container.find( '.hs-marker' ).removeClass( 'hs-marker' );

			$container.find( 'img, video, svg' )
				.css( 'opacity', '1' );

			// Fix for File: pages
			if ( $container.hasClass( 'fullImageLink' ) ) {
				$container.find( 'img' ).css( 'opacity', '1' );
			}
		} );
	}

	// --- Initialization ---

	function init( $content ) {
		$content.find( '[data-hs="1"], .hs-marker' ).each( function () {
			const marker = this;
			const reason = marker.dataset.hsReason;

			const container = resolveContainer( marker );
			if ( !container ) return;

			container.classList.add( 'hs-container' );
			attachOverlay( container, reason );
		} );

		// Special handling for File: pages
		if ( mw.config.get( 'wgHideSensitiveImagePage' ) ) {
			const $mainImg = $( '.fullImageLink' );
			if ( $mainImg.length ) {
				$mainImg.addClass( 'hs-container' );
				attachOverlay( $mainImg[0], mw.config.get( 'wgHideSensitiveReason' ) );
			}
		}
	}

	mw.hook( 'wikipage.content' ).add( init );

	// --- MultimediaViewer Integration ---
	mw.hook( 'mmv.viewer.before-opening' ).add( function ( viewer ) {
		const sourceElement = viewer.element.closest( '.hs-container' );
		if ( sourceElement ) {
			viewer.element.dataset.mmvIsSensitive = 'true';
			viewer.element.dataset.mmvReason = $( sourceElement ).find( '[data-hs-reason]' ).addBack( '[data-hs-reason]' ).first().data( 'hs-reason' ) || '';
		} else {
			viewer.element.dataset.mmvIsSensitive = 'false';
		}
	} );

	mw.hook( 'mmv.image.loaded' ).add( function ( image, viewer ) {
		if ( viewer.element.dataset.mmvIsSensitive !== 'true' ) {
			return;
		}

		const $viewerNode = $( viewer.getMediaNode() );
		const $container = $viewerNode.parent();

		if ( !$viewerNode.length || $container.find( '.sensitive-content-overlay-wrapper' ).length > 0 ) {
			return;
		}

		$viewerNode.css( 'opacity', '0' );
		const $overlay = createOverlay( viewer.element.dataset.mmvReason );

		$overlay.css( {
			position: 'absolute',
			inset: 0,
			zIndex: 1000
		} );

		$container.append( $overlay );

		$overlay.on( 'click', '.sensitive-content-button-show', function( e ) {
			e.preventDefault();
			e.stopPropagation();
			$overlay.remove();
			$viewerNode.css( 'opacity', '1' );
			viewer.element.dataset.mmvIsSensitive = 'false';
		} );
	} );

}( mw, jQuery ) );
