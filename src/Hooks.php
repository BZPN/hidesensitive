<?php

namespace MediaWiki\Extension\HideSensitive;

use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Output\OutputPage;
use MediaWiki\Config\Config;
use Skin;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ImagePage;
use MediaWiki\File\File;

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

		$dbr = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getReplicaDatabase();

		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return false;
		}

		$exists = $dbr->selectField(
			'categorylinks',
			'1',
			[
				'cl_from' => $pageId,
				'cl_to'   => 'Sensitive_files'
			],
			__METHOD__
		);

		return (bool)$exists;
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

		$pageId = $file->getPageId();
		if ( !$pageId ) {
			return;
		}

		// Check if file is in Sensitive_files category
		$dbr = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getReplicaDatabase();

		$isSensitiveFromFile = (bool)$dbr->selectField(
			'categorylinks',
			'1',
			[
				'cl_from' => $pageId,
				'cl_to'   => 'Sensitive_files'
			],
			__METHOD__
		);

		// Also check parameters passed to the thumbnail itself (e.g. |sensitive=true in wikitext)
		$isSensitiveFromParams = isset( $attribs['sensitive'] ) && $attribs['sensitive'] === 'true';

		if ( !$isSensitiveFromFile && !$isSensitiveFromParams ) {
			return;
		}

		$fileTitle = Title::makeTitle( NS_FILE, $file->getName() );
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

	public static function onImagePageFindFile( ImagePage $imagePage, &$file ) {
		if ( !$file instanceof File ) {
			return;
		}

		$title = $imagePage->getTitle();

		if ( self::isSensitiveFilePage( $title )
			&& !self::shouldBypass( RequestContext::getMain()->getUser(), $title )
		) {
			RequestContext::getMain()->getOutput()->addModules( 'ext.hideSensitive.core' );

			// Mark page as sensitive for JS
			RequestContext::getMain()->getOutput()->addJsConfigVars( [
				'wgHideSensitiveImagePage' => true
			] );
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
