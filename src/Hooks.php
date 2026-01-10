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
use MediaWiki\Hook\BeforePageDisplayHook;

class Hooks implements BeforePageDisplayHook {
	private static ?array $blacklist = null;

	private static function getSensitiveBlacklist(): array {
		if ( self::$blacklist !== null ) {
			return self::$blacklist;
		}

		$title = Title::newFromText( 'MediaWiki:SensitiveImagesBlacklist.json' );
		if ( !$title || !$title->exists() ) {
			return self::$blacklist = [];
		}

		$services = MediaWikiServices::getInstance();
		$rev = $services->getRevisionLookup()->getRevisionByTitle( $title );

		if ( !$rev ) {
			return self::$blacklist = [];
		}

		$content = $rev->getContent( SlotRecord::MAIN );
		if ( !$content ) {
			return self::$blacklist = [];
		}

		$json = json_decode( $content->getText(), true );
		if ( !is_array( $json ) ) {
			return self::$blacklist = [];
		}

		$map = [];
		foreach ( $json as $entry ) {
			if ( isset( $entry['file'] ) ) {
				$map[ $entry['file'] ] = trim($entry['reason'] ?? '') ?: null;
			}
		}

		return self::$blacklist = $map;
	}

	private static function isBlacklistedFile( $file ) {
		if ( !$file || !method_exists( $file, 'getName' ) ) {
			return false;
		}

		$list = self::getSensitiveBlacklist();
		return array_key_exists( $file->getName(), $list )
			? ($list[ $file->getName() ] ?? null)
			: false;
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
		if ( $reason === false ) {
			return;
		}

		if ( self::shouldBypass(
			RequestContext::getMain()->getUser(),
			$file->getTitle()
		) ) {
			return;
		}

		$attribs['class'] =
			( $attribs['class'] ?? '' ) . ' hs-container';

		$attribs['data-hs'] = '1';
		$attribs['data-hs-reason'] = $reason;

		$out = RequestContext::getMain()->getOutput();
		$out->addModuleStyles( 'ext.hideSensitive.styles' );
		$out->addModules( 'ext.hideSensitive.core' );
	}

	public static function onImagePageFindFile( ImagePage $imagePage, &$file ) {
		if ( !$file ) return;

		$reason = self::isBlacklistedFile( $file );
		if ( $reason === false ) return;

		if ( self::shouldBypass(
			RequestContext::getMain()->getUser(),
			$file->getTitle()
		) ) {
			return;
		}

		$out = RequestContext::getMain()->getOutput();

		$out->addModules( 'ext.hideSensitive.core' );
		$out->addJsConfigVars( [
			'wgHideSensitiveImagePage' => true,
			'wgHideSensitiveReason' => $reason
		] );
	}

	public function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$out->addModuleStyles( 'ext.hideSensitive.styles' );
		$out->addModules( 'ext.hideSensitive.core' );
	}

	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$vars['wgSensitiveContent'] = [
			'infoPage' => $config->has( 'wgSensitiveInfoPage' )
				? $config->get( 'wgSensitiveInfoPage' )
				: 'Help:Sensitive_content',

			'buttonText' => $config->has( 'wgSensitiveButtonText' )
				? $config->get( 'wgSensitiveButtonText' )
				: 'Show',

			'buttonColor' => $config->has( 'wgSensitiveButtonColor' )
				? $config->get( 'wgSensitiveButtonColor' )
				: '#36c',
		];

		// Expose blacklist for JS scanning of categories
		$vars['wgSensitiveBlacklist'] = self::getSensitiveBlacklist();
	}

	private static function shouldBypass( User $user, Title $title ): bool {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		try {
			$allowedGroups = $config->get( 'wgSensitiveContentAllowedGroup' );
			if ( !is_array( $allowedGroups ) || $allowedGroups === [] ) {
				return false;
			}

			$groupManager = $services->getUserGroupManager();
			$userGroups = $groupManager->getUserEffectiveGroups( $user );

			return (bool)array_intersect( $userGroups, $allowedGroups );

		} catch ( \ConfigException $e ) {
			return false;
		}
	}
}

