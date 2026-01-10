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

		// Check if the file page is in 'Category:Sensitive'
		$categoryName = 'Category:Sensitive';
		$categories = MediaWikiServices::getInstance()->getCategoryFinder()->getCategories( $title );
		foreach ( $categories as $category ) {
			if ( $category->getText() === $categoryName ) {
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
		$title = $file->getTitle();

		// Check sensitivity from file metadata (e.g., categories)
		$isSensitiveFromFile = self::isSensitive( [], $title );
		
		// Also check parameters passed to the thumbnail itself (e.g. |sensitive=true in wikitext)
		$isSensitiveFromParams = isset( $linkAttribs['data-sensitive'] ) && $linkAttribs['data-sensitive'] === 'true';

		if ( !$isSensitiveFromFile && !$isSensitiveFromParams ) {
			return;
		}

		if ( self::shouldBypass( RequestContext::getMain()->getUser(), $title ) ) {
			return;
		}

		$description = wfMessage( 'sensitive-default-description' )->text();

		if ( !is_array( $linkAttribs ) ) {
			$linkAttribs = [];
		}
		$linkAttribs['data-sensitive'] = 'true';
		$linkAttribs['data-width'] = $thumbnail->getWidth();
		$linkAttribs['data-height'] = $thumbnail->getHeight();
		// Use description from parameter if available, otherwise default
		$linkAttribs['data-description'] = $linkAttribs['data-description'] ?? $description;

		if ( !is_array( $attribs ) ) {
			$attribs = [];
		}
		$attribs['data-sensitive'] = 'true';
		$attribs['data-description'] = $linkAttribs['data-description'];

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
			$linkAttribs['data-description'] = wfMessage( 'sensitive-default-description' )->text();
			RequestContext::getMain()->getOutput()->addModules( 'ext.hideSensitive.core' );
		}
	}


	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		// This ensures the JS is loaded on every page, which is needed for MutationObserver to work.
		$out->addModules( 'ext.hideSensitive.core' );
	}

	/**
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$vars['wgSensitiveContent'] = [
			'infoPage' => $config->get( 'SensitiveInfoPage' ),
		];
		// Pass i18n messages to JavaScript
		$vars['wgSensitiveMessages'] = [
			'sensitive-default-description' => wfMessage( 'sensitive-default-description' )->text(),
			'sensitive-learn-more' => wfMessage( 'sensitive-learn-more' )->text(),
			'sensitive-show-content' => wfMessage( 'sensitive-show-content' )->text(),
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

