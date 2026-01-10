
( function ( mw, $ ) {
	'use strict';

	const cfg = mw.config.get( 'wgSensitiveContent' ) || {};
	const infoPage = cfg.infoPage || 'Help:Sensitive_content';
	const buttonText = cfg.buttonText || mw.msg( 'hidesensitive-button-text' );
	const buttonColor = cfg.buttonColor || '#36c';
	const learnMoreUrl = mw.util.getUrl( infoPage );

	/**
	 * Creates the HTML structure for the sensitive content overlay.
	 * @param {string} reason The reason for hiding the content.
	 * @return {jQuery} A jQuery object representing the overlay.
	 */
	function createOverlay( reason ) {
		const description = reason || mw.msg( 'hidesensitive-default-description' );

		const $overlay = $( '<div>' ).addClass( 'sensitive-content-overlay-wrapper' )
			.append(
				$( '<div>' ).addClass( 'sensitive-content-icon' )
					.css( 'background-image',
						'url(' + mw.config.get( 'wgExtensionAssetsPath' ) +
						'/HideSensitive/resources/images/icon.png)'
					)
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
	 * Attaches overlay to a container element.
	 * @param {HTMLElement} container The container element.
	 * @param {string} reason The reason for hiding.
	 */
	function attachOverlay( container, reason ) {
		const $container = $( container );

		if ( $container.find( '.sensitive-content-overlay-wrapper' ).length ) {
			return;
		}

		const $overlay = createOverlay( reason );
		$overlay.css( {
			position: 'absolute',
			inset: 0,
			zIndex: 20
		} );

		$container.append( $overlay );

		// wymuś layout – krytyczne
		$container[0].getBoundingClientRect();

		const width = $container.outerWidth();
		const height = $container.outerHeight();

		// zdjęcia typu dowód, portret, małe thumbs
		if ( width < 180 || height < 140 ) {
			$overlay.addClass( 'hs-compact' );
		}

		$overlay.on( 'click', '.sensitive-content-button-show', function( e ) {
			e.preventDefault();
			e.stopPropagation();

			$overlay.remove();
			$container.removeClass( 'hs-container' );

			$container.children( 'img, video, svg' )
				.css( 'opacity', '1' );
		} );
	}

	// --- Initialization ---

	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		// Attach overlays to all containers marked by PHP
		$content.find( 'a.hs-container[data-hs]' ).each( function() {
			const reason = this.dataset.hsReason;
			attachOverlay( this, reason );
		} );

		// Special handling for File: pages
		if ( mw.config.get( 'wgHideSensitiveImagePage' ) ) {
			const container = document.querySelector( '.fullImageLink' );
			if ( container ) {
				container.classList.add( 'hs-container' );
				attachOverlay( container, mw.config.get( 'wgHideSensitiveReason' ) );
			}
		}

		// Scan category galleries for sensitive files
		const blacklist = mw.config.get( 'wgSensitiveBlacklist' ) || {};
		$content.find( '.gallerybox .thumbinner' ).each( function () {
			const $inner = $( this );
			const $img = $inner.find( 'img' );

			if ( !$img.length ) {
				return;
			}

			const fileName = mw.util.getParamValue( 'file', $img.attr( 'src' ) );
			if ( !fileName || !blacklist[fileName] ) {
				return;
			}

			$inner.addClass( 'hs-container' );
			attachOverlay( $inner[0], blacklist[fileName] );
		} );

		// Scan all image links for sensitive files
		$content.find( 'a.image, a.mw-file-description' ).each( function () {
			const $a = $( this );
			const $img = $a.find( 'img' );
			if ( !$img.length ) return;

			const src = $img.attr( 'src' );
			if ( !src ) return;

			const fileName = mw.util.getParamValue( 'file', src );
			if ( !fileName || !blacklist[fileName] ) return;

			$a.addClass( 'hs-container' );
			$a.attr( 'data-hs', '1' );
			$a.attr( 'data-hs-reason', blacklist[fileName] );

			attachOverlay( $a[0], blacklist[fileName] );
		} );
	} );

	// --- MultimediaViewer Integration ---
	let currentViewer = null;
	let currentSourceLink = null;

	mw.hook( 'mmv.viewer.before-opening' ).add( function ( viewer ) {
		currentViewer = viewer;
		// The source link can be the element itself or a parent anchor
		const sourceElement = viewer.element.closest( '.hs-container[data-hs]' );
		if ( sourceElement ) {
			// Mark as sensitive so we can act on it when the image loads
			viewer.element.dataset.mmvIsSensitive = 'true';
			currentSourceLink = sourceElement.dataset.hsReason;
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

		$viewerNode.css( 'opacity', '0' );

		const $overlay = createOverlay( currentSourceLink );

		// Special styling for MMV
		$overlay.css( {
			position: 'absolute',
			inset: 0,
			zIndex: 1000
		} );

		$viewerNode.parent().append( $overlay );

		$overlay.on( 'click', '.sensitive-content-button-show', function( e ) {
			e.preventDefault();
			e.stopPropagation();
			$overlay.remove();
			$viewerNode.css( 'opacity', '1' );
			// Unset sensitive flag so it doesn't re--appear when navigating gallery
			viewer.element.dataset.mmvIsSensitive = 'false';
		} );
	} );

}( mw, jQuery ) );

