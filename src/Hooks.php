<?php

namespace MediaWiki\Extension\HideSensitive;

use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Output\OutputPage;
use MediaWiki\Config\Config;
use Skin;
use MediaWiki\MediaWikiServices;
use ImagePage;
use MediaWiki\Linker\LinkRenderer;

class Hooks {
	private static function isSensitive( array $params, Title $title = null ): bool {
		if ( isset( $params['sensitive'] ) && $params['sensitive'] === 'true' ) {
			return true;
		}
		if ( $title && self::isSensitiveFilePage( $title ) ) {
			return true;
		}
		return false;
	}

	private static function isSensitiveFilePage( Title $title ): bool {
		if ( !$title->inNamespace( NS_FILE ) || !$title->exists() ) {
			return false;
		}

		// Get categories of File: page
		$cats = $title->getParentCategories();

		foreach ( $cats as $catTitleText => $_ ) {
			$catTitle = Title::newFromText( $catTitleText );
			if ( $catTitle && $catTitle->getText() === 'Sensitive_files' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param \ThumbnailImage $thumbnail
	 * @param array &$attribs
	 * @param array &$linkAttribs
	 */
	public static function onThumbnailBeforeProduceHTML( $thumbnail, &$attribs, &$linkAttribs ) {
		$file = $thumbnail->getFile();
		if ( !$file ) {
			return;
		}
		$fileTitle = Title::makeTitle( NS_FILE, $file->getName() );

		// Check sensitivity from file metadata (e.g., categories)
		$isSensitiveFromFile = self::isSensitive( [], $fileTitle );
		
		// Also check parameters passed to the thumbnail itself (e.g. |sensitive=true in wikitext)
		$isSensitiveFromParams = isset( $linkAttribs['data-sensitive'] ) && $linkAttribs['data-sensitive'] === 'true';

		if ( !$isSensitiveFromFile && !$isSensitiveFromParams ) {
			return;
		}

		if ( self::shouldBypass( RequestContext::getMain()->getUser(), $fileTitle ) ) {
			return;
		}

		if ( !is_array( $linkAttribs ) ) {
			$linkAttribs = [];
		}
		$linkAttribs['data-sensitive'] = 'true';
		$linkAttribs['data-width'] = $thumbnail->getWidth();
		$linkAttribs['data-height'] = $thumbnail->getHeight();

		// Removed: Do not mark <img> as sensitive

		RequestContext::getMain()->getOutput()->addModules( 'ext.hideSensitive.core' );
	}

	/**
	 * @param ImagePage $imagepage
	 * @param LinkRenderer $linkRenderer
	 * @param \File $fileToLink
	 * @param array &$linkAttribs
	 * @param string &$html
	 */
	public static function onImagePageFindFile( $imagepage, $linkRenderer, $fileToLink, &$linkAttribs, &$html ) {
		$title = $imagepage->getTitle();
		if ( self::isSensitiveFilePage( $title ) && !self::shouldBypass( RequestContext::getMain()->getUser(), $title ) ) {
			$linkAttribs['data-sensitive'] = 'true';
			RequestContext::getMain()->getOutput()->addModules( 'ext.hideSensitive.core' );
		}
	}


	/**
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		try {
			$infoPage = $config->get( 'wgSensitiveInfoPage' );
			$buttonText = $config->get( 'wgSensitiveButtonText' );
			$buttonColor = $config->get( 'wgSensitiveButtonColor' );
		} catch ( \ConfigException $e ) {
			$infoPage = 'Wikipedia:Sensitive_content';
			$buttonText = 'Show';
			$buttonColor = '#36c';
		}
		$vars['wgSensitiveContent'] = [
			'infoPage' => $infoPage,
			'buttonText' => $buttonText,
			'buttonColor' => $buttonColor,
		];
	}

	private static function shouldBypass( User $user, Title $title ): bool {
		$config = RequestContext::getMain()->getConfig();

		try {
			$allowedGroups = $config->get( 'wgSensitiveContentAllowedGroup' );
			if ( is_array( $allowedGroups ) && !empty( array_intersect( $user->getEffectiveGroups(), $allowedGroups ) ) ) {
				return true;
			}
		} catch ( \ConfigException $e ) {
			// group not set
		}

		return false;
	}
}
