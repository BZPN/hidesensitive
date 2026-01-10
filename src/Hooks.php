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
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
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
	 * @param mixed &$attribs
	 * @param mixed &$linkAttribs
	 */
	public static function onThumbnailBeforeProduceHTML( $thumbnail, &$attribs, &$linkAttribs ) {
		$file = $thumbnail->getFile();
		$title = $file ? $file->getTitle() : null;

		if ( !self::isSensitive( [], $title ) ) {
			return;
		}

		if ( $title && self::shouldBypass( RequestContext::getMain()->getUser(), $title ) ) {
			return;
		}
		
		$config = RequestContext::getMain()->getConfig();
		try {
			$description = $config->get( 'wgSensitiveDefaultDescription' );
		} catch ( \ConfigException $e ) {
			$description = 'This content has been marked as sensitive.';
		}

		if (!is_array($linkAttribs)) $linkAttribs = [];
		$linkAttribs['data-sensitive'] = 'true';
		$linkAttribs['data-width'] = $thumbnail->getWidth();
		$linkAttribs['data-height'] = $thumbnail->getHeight();
		$linkAttribs['data-description'] = $description;

		if (!is_array($attribs)) $attribs = [];
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
			$user = $parser ? $parser->getUser() : RequestContext::getMain()->getUser();
			if ( !self::shouldBypass( $user, $title ) ) {
				$handlerParams['sensitive'] = 'true';
				// Also pass description if present
				if ( isset( $frameParams['description'] ) ) {
					$handlerParams['sensitive-description'] = $frameParams['description'];
				}
				if ( $parser ) {
					$parser->getOutput()->addModules( 'ext.hideSensitive.core' );
				}
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
			try {
				$description = $config->get( 'wgSensitiveDefaultDescription' );
			} catch ( \ConfigException $e ) {
				$description = 'This content has been marked as sensitive.';
			}
			$overlay = self::getOverlayHTML( [
				'width' => $imagepage->getFile()->getWidth(),
				'height' => $imagepage->getFile()->getHeight(),
				'description' => $description
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
		try {
			$buttonColor = $config->get( 'wgSensitiveButtonColor' );
			$infoPage = $config->get( 'wgSensitiveInfoPage' );
		} catch ( \ConfigException $e ) {
			$buttonColor = '#36c';
			$infoPage = 'Wikipedia:Sensitive_content';
		}
		$vars['wgSensitiveContent'] = [
			'buttonColor' => $buttonColor,
			'infoPage' => $infoPage,
		];
	}

	private static function shouldBypass( User $user, Title $title ): bool {
		$config = RequestContext::getMain()->getConfig();

		try {
			$allowedGroups = $config->get( 'wgSensitiveContentAllowedGroup' );
			if ( is_array($allowedGroups) && !empty( array_intersect( $user->getEffectiveGroups(), $allowedGroups ) ) ) {
				return true;
			}

			$allowedUsers = $config->get( 'wgSensitiveAllowedUsers' );
			if ( is_array($allowedUsers) && in_array( $user->getName(), $allowedUsers ) ) {
				return true;
			}

			$allowedNamespaces = $config->get( 'wgSensitiveAllowedNamespaces' );
			if ( is_array($allowedNamespaces) && in_array( $title->getNamespace(), $allowedNamespaces ) ) {
				return true;
			}
		} catch ( \ConfigException $e ) {
			// Config not set, use defaults
		}

		return false;
	}

	private static function getDefaultDescription(): string {
		$title = Title::newFromText( 'Sensitive-default-description', NS_MEDIAWIKI );
		if ( !$title || !$title->exists() ) {
			return 'This content has been marked as sensitive.';
		}
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$content = $page->getContent();
		if ( $content ) {
			return $content->getText();
		}
		return 'This content has been marked as sensitive.';
	}

	private static function getOverlayHTML( array $params ): string {
		$config = RequestContext::getMain()->getConfig();

		try {
			$descDefault = self::getDefaultDescription();
			$buttonText = $config->get( 'wgSensitiveButtonText' );
			$buttonColor = $config->get( 'wgSensitiveButtonColor' );
			$infoPage = $config->get( 'wgSensitiveInfoPage' );
		} catch ( \ConfigException $e ) {
			$descDefault = 'This content has been marked as sensitive.';
			$buttonText = 'Show';
			$buttonColor = '#36c';
			$infoPage = 'Wikipedia:Sensitive_content';
		}

		$desc = $params['description'] ?? $descDefault;
		$buttonText = htmlspecialchars( $buttonText );
		$buttonColor = htmlspecialchars( $buttonColor );
		$learnMoreUrl = Title::newFromText( $infoPage )->getLocalURL();

		$width = $params['width'] ?? '200';
		$height = $params['height'] ?? '200';

		return '<div class="sensitive-content-overlay-wrapper" style="background-color: #000; color: #fff; width: ' . $width . 'px; height: ' . $height . 'px; display: flex; flex-direction: column; justify-content: center; align-items: center; font-family: Arial, sans-serif;">' .
			'<div class="sensitive-content-icon" style="font-size: 60px; text-decoration: line-through;">👁️</div>' .
			'<div class="sensitive-content-text" style="margin: 20px 0; font-size: 16px;">' . htmlspecialchars( $desc ) . '</div>' .
			'<div class="sensitive-content-buttons" style="display: flex; gap: 20px;">' .
			'<a href="' . htmlspecialchars( $learnMoreUrl ) . '" class="sensitive-content-button-learn" style="padding: 10px 20px; background-color: white; color: #333; border: 1px solid #ccc; text-decoration: none; border-radius: 5px;">Learn More</a>' .
			'<button class="sensitive-content-button" style="padding: 10px 20px; background-color: ' . $buttonColor . '; color: white; border: none; border-radius: 5px; cursor: pointer;">' . $buttonText . '</button>' .
			'</div>' .
			'</div>';
	}
}


