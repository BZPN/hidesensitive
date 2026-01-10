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
use MediaWiki\Revision\SlotRecord;

class Hooks {
	private static function getSensitiveBlacklist(): array {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}

		$title = Title::newFromText( 'MediaWiki:SensitiveImagesBlacklist.json' );
		if ( !$title || !$title->exists() ) {
			return $cache = [];
		}

		$services = MediaWikiServices::getInstance();
		$rev = $services->getRevisionLookup()->getRevisionByTitle( $title );

		if ( !$rev ) {
			return $cache = [];
		}

		$content = $rev->getContent( SlotRecord::MAIN );
		if ( !$content ) {
			return $cache = [];
		}

		$json = json_decode( $content->getText(), true );
		if ( !is_array( $json ) ) {
			return $cache = [];
		}

		$map = [];
		foreach ( $json as $entry ) {
			if ( isset( $entry['file'] ) ) {
				$map[ $entry['file'] ] = $entry['reason'] ?? '';
			}
		}

		return $cache = $map;
	}

	private static function isBlacklistedFile( $file ): ?string {
		if ( !$file || !method_exists( $file, 'getName' ) ) {
			return null;
		}

		$list = self::getSensitiveBlacklist();
		return $list[ $file->getName() ] ?? null;
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

		$reason = self::isBlacklistedFile( $file );
		if ( $reason === null ) {
			return;
		}

		if ( self::shouldBypass( RequestContext::getMain()->getUser(), $file->getTitle() ) ) {
			return;
		}

		if ( !is_array( $linkAttribs ) ) {
			$linkAttribs = [];
		}
		$linkAttribs['data-sensitive'] = 'true';
		$linkAttribs['data-description'] = $reason;

		RequestContext::getMain()->getOutput()->addModules( 'ext.hideSensitive.core' );
	}

	public static function onImagePageFindFile( ImagePage $imagePage, &$file ) {
		if ( !$file || !method_exists( $file, 'getName' ) ) {
			return;
		}

		$reason = self::isBlacklistedFile( $file );
		if ( $reason === null ) {
			return;
		}

		if ( self::shouldBypass( RequestContext::getMain()->getUser(), $file->getTitle() ) ) {
			return;
		}

		RequestContext::getMain()->getOutput()->addModules( 'ext.hideSensitive.core' );

		RequestContext::getMain()->getOutput()->addJsConfigVars( [
			'wgHideSensitiveImagePage' => true,
			'wgHideSensitiveReason' => $reason
		] );
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
