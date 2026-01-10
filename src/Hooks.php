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
use MediaWiki\Parser\Parser;
class Hooks
{
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
		// Check if the file page is in 'Category:Sensitive files'
		$category = Title::newFromText( 'Sensitive files', NS_CATEGORY );
		if ( !$category || !$category->exists() ) {
			return false; // Category doesn't exist, so no files can be in it.
		}
		$dbr = MediaWikiServices::getInstance()->getDBConnectionProvider()->getReplicaDatabase();
		$res = $dbr->selectField(
			'categorylinks',
			'cl_from',
			[
				'cl_from' => $title->getArticleID(),
				'cl_to' => $category->getDBkey()
			],
			__METHOD__
		);

		return $res !== false;
	}

	/**
	 * @param mixed $thumbnail
	 * @param array &$attribs
	 * @param array &$linkAttribs
	 */
	public static function onThumbnailBeforeProduceHTML( $thumbnail, array &$attribs, array &$linkAttribs ) {
		$file = $thumbnail->getFile();
		$title = $file ? $file->getTitle() : null;

		if ( !self::isSensitive( $thumbnail->getParams(), $title ) ) {
			return;
		}

		if ( $title && self::shouldBypass( RequestContext::getMain()->getUser(), $title ) ) {
			return;
		}
		
		$config = RequestContext::getMain()->getConfig();
		$params = $thumbnail->getParams();
		$description = $params['sensitive-description'] ?? $params['alt'] ?? $params['description'] ?? $config->get( 'SensitiveDefaultDescription' );

		$linkAttribs['data-sensitive'] = 'true';
		$linkAttribs['data-width'] = $thumbnail->getWidth();
		$linkAttribs['data-height'] = $thumbnail->getHeight();
		$linkAttribs['data-description'] = $description;

		$attribs['data-sensitive'] = 'true';
		$attribs['data-description'] = $description;
	}

	/**
	 * @param Parser $parser
	 * @param Title $title
	 * @param \File $file
	 * @param array &$frameParams
	 * @param array &$handlerParams
	 * @param int &$time
	 * @param string &$res
	 * @return bool
	 */
	public static function onImageBeforeProduceHTML( $parser, $title, $file, &$frameParams, &$handlerParams, &$time, &$res ) {
		if ( self::isSensitive( $frameParams, $title ) ) {
			if ( !self::shouldBypass( $parser->getUser(), $title ) ) {
				$handlerParams['sensitive'] = 'true';
				// Also pass description if present
				if ( isset( $frameParams['description'] ) ) {
					$handlerParams['sensitive-description'] = $frameParams['description'];
				}
				$parser->getOutput()->addModules( 'ext.hideSensitive.core' );
			}
		}
		return true;
	}

	/**
	 * @param ImagePage $imagepage
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onImageOpenShowImageInlineBefore( $imagepage, $out ) {
		$title = $imagepage->getTitle();
		if ( self::shouldBypass( $out->getUser(), $title ) ) {
			return true;
		}

		if ( self::isSensitiveFilePage( $title ) ) {
			$config = RequestContext::getMain()->getConfig();
			$overlay = self::getOverlayHTML( [ 
				'width' => $imagepage->getFile()->getWidth(), 
				'height' => $imagepage->getFile()->getHeight(),
				'description' => $config->get( 'SensitiveDefaultDescription' )
			] );
			$out->addHTML( $overlay );
			$out->addModules( 'ext.hideSensitive.core' );
			return false;
		}
		
		return true;
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		$out->addModules( 'ext.hideSensitive.core' );
	}

	/**
	 * @param array &$vars
	 * @param string $skin
	 * @param Config $config
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars, string $skin, Config $config ) {
		$vars['wgSensitiveContent'] = [
			'buttonColor' => $config->get( 'SensitiveButtonColor' ),
		];
	}

	private static function shouldBypass( User $user, Title $title ): bool {
		$config = RequestContext::getMain()->getConfig();

		$allowedGroups = $config->get( 'SensitiveContentAllowedGroup' );
		if ( is_array($allowedGroups) && !empty( array_intersect( $user->getEffectiveGroups(), $allowedGroups ) ) ) {
			return true;
		}

		$allowedUsers = $config->get( 'SensitiveAllowedUsers' );
		if ( is_array($allowedUsers) && in_array( $user->getName(), $allowedUsers ) ) {
			return true;
		}

		$allowedNamespaces = $config->get( 'SensitiveAllowedNamespaces' );
		if ( is_array($allowedNamespaces) && in_array( $title->getNamespace(), $allowedNamespaces ) ) {
			return true;
		}

		return false;
	}

	private static function getOverlayHTML( array $params ): string {
		$config = RequestContext::getMain()->getConfig();

		$desc = htmlspecialchars( $params['description'] ?? $config->get( 'SensitiveDefaultDescription' ) );
		$buttonText = htmlspecialchars( $config->get( 'SensitiveButtonText' ) );
		$buttonColor = htmlspecialchars( $config->get( 'SensitiveButtonColor' ) );

		$width = $params['width'] ?? '200';
		$height = $params['height'] ?? '200';

		return '<div class="sensitive-content-overlay-wrapper">' .
			'<div class="sensitive-content-overlay" style="width: ' . $width . 'px; height: ' . $height . 'px;">' .
			'<div class="sensitive-content-icon"></div>' .
			'<div class="sensitive-content-text">' . $desc . '</div>' .
			'<button class="sensitive-content-button" style="background-color:' . $buttonColor . ';">' . $buttonText . '</button>' .
			'</div></div>';
	}
}


