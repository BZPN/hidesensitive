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
use Closure;

class Hooks {
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
				$name = str_replace( ' ', '_', $entry['file'] );
				$map[$name] = trim( $entry['reason'] ?? '' ) ?: null;
			}
		}

		return self::$blacklist = $map;
	}

	private static function isBlacklistedFile( $file ) {
		if ( !$file || !method_exists( $file, 'getName' ) ) {
			return false;
		}

		$list = self::getSensitiveBlacklist();
		$name = str_replace( ' ', '_', $file->getName() );
		return array_key_exists( $name, $list )
			? ( $list[$name] ?? null )
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

		if ( !is_array( $linkAttribs ) ) {
			$linkAttribs = [];
		}

		$linkAttribs['class'] =
			( $linkAttribs['class'] ?? '' ) . ' hs-marker';

		$linkAttribs['data-hs'] = '1';
		if ( $reason ) {
			$linkAttribs['data-hs-reason'] = $reason;
		}
	}

	/**
	 * @param \Linker $linker
	 * @param \Title $title
	 * @param \File $file
	 * @param array &$frameParams
	 * @param array &$handlerParams
	 * @param array &$attribs
	 * @param array &$customAugmentLink
	 * @param array &$linkAttribs
	 * @param mixed &$res
	 */
	public static function onImageBeforeProduceHTML( $linker, $title, $file, &$frameParams, &$handlerParams, &$attribs, &$customAugmentLink, &$linkAttribs, &$res ) {
		if ( !$file ) {
			return;
		}

		$reason = self::isBlacklistedFile( $file );
		$isSensitive = $reason !== false;

		if ( isset( $handlerParams['sensitive'] ) && $handlerParams['sensitive'] === 'true' ) {
			$isSensitive = true;
			if ( isset( $handlerParams['hs_description'] ) && $handlerParams['hs_description'] ) {
				$reason = $handlerParams['hs_description'];
			}
		}

		if ( !$isSensitive ) {
			return;
		}

		if ( self::shouldBypass(
			RequestContext::getMain()->getUser(),
			$file->getTitle()
		) ) {
			return;
		}

		if ( !is_array( $linkAttribs ) ) {
			$linkAttribs = [];
		}

		$linkAttribs['class'] =
			( $linkAttribs['class'] ?? '' ) . ' hs-marker';

		$linkAttribs['data-hs'] = '1';
		if ( $reason ) {
			$linkAttribs['data-hs-reason'] = $reason;
		}
	}

	public static function onImagePageFindFile( ImagePage $imagePage, &$file ) {
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

		$out = RequestContext::getMain()->getOutput();

		$out->addJsConfigVars( [
			'wgHideSensitiveImagePage' => true,
			'wgHideSensitiveReason' => $reason
		] );
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( $out, $skin ): void {
		$out->addModuleStyles( 'ext.hideSensitive.styles' );
		$out->addModules( 'ext.hideSensitive.core' );
	}

	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$context = RequestContext::getMain();

		$msgButtonText = $context->msg( 'hidesensitive-button-text' );
		$buttonText = $msgButtonText->exists() && !$msgButtonText->isDisabled()
			? $msgButtonText->plain()
			: ( $config->has( 'wgSensitiveButtonText' ) ? $config->get( 'wgSensitiveButtonText' ) : 'Show' );

		$vars['wgSensitiveContent'] = [
			'infoPage' => $config->has( 'wgSensitiveInfoPage' )
				? $config->get( 'wgSensitiveInfoPage' )
				: 'Help:Sensitive_content',

			'buttonText' => $buttonText,

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
			if ( !$config->has( 'wgSensitiveContentAllowedGroup' ) ) {
				return false;
			}
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

	/**
	 * @param Title $title
	 * @param mixed $fileOrOffsets
	 * @param mixed &$offsetsOrParams
	 * @param mixed &$paramsOrParser
	 * @param mixed $parser
	 * @return bool
	 */
	public static function onParserMakeImageParams( $title, $fileOrOffsets, &$offsetsOrParams, &$paramsOrParser = null, $parser = null ) {
		if ( is_array( $fileOrOffsets ) ) {
			// Old signature: $title, $magicWordOffsets, &$params, $parser
			return self::realParserMakeImageParams( $fileOrOffsets, $offsetsOrParams, $paramsOrParser );
		} else {
			// New signature: $title, $file, $magicWordOffsets, &$params, $parser
			return self::realParserMakeImageParams( $offsetsOrParams, $paramsOrParser, $parser );
		}
	}

	/**
	 * @param array $magicWordOffsets
	 * @param array &$params
	 * @param \Parser $parser
	 * @return bool
	 */
	private static function realParserMakeImageParams( $magicWordOffsets, &$params, $parser ) {
		if ( isset( $magicWordOffsets['sensitive'] ) ) {
			$params['handler']['sensitive'] = 'true';
		}

		if ( isset( $magicWordOffsets['hs_description'] ) ) {
			$descOffsets = $magicWordOffsets['hs_description'];
			if ( is_array( $descOffsets ) && count( $descOffsets ) > 0 ) {
				$getText = Closure::bind( function( $p ) {
					return $p->mText;
				}, null, $parser );

				$fullText = $getText( $parser );
				foreach ( $descOffsets as $offset ) {
					$paramText = substr( $fullText, $offset );
					if ( preg_match( '/^[^|\]]+\s*=\s*([^|\]]+)/i', $paramText, $matches ) ) {
						$params['handler']['hs_description'] = trim( $matches[1] );
						break;
					}
				}
			}
		}
		return true;
	}
}
