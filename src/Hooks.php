<?php

namespace MediaWiki\Extension\HideSensitive;

use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\ThumbnailBeforeProduceHTMLHook;
use MediaWiki\Hook\ImageOpenShowImageInlineBeforeHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use OutputPage;
use Skin;
use MediaWiki\MediaWikiServices;

class Hooks implements
	ThumbnailBeforeProduceHTMLHook,
	ImageOpenShowImageInlineBeforeHook,
	BeforePageDisplayHook,
	ResourceLoaderGetConfigVarsHook
{
	private static function isSensitive( array $params ): bool {
		return isset( $params['sensitive'] ) && $params['sensitive'] === 'true';
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

	public function onThumbnailBeforeProduceHTML( $thumbnail, &$attribs, &$linkAttribs ): void {
		$params = $thumbnail->getParams();
		if ( !self::isSensitive( $params ) ) {
			return;
		}

		$file = $thumbnail->getFile();
		if ( self::shouldBypass( RequestContext::getMain()->getUser(), $file->getTitle() ) ) {
			return;
		}
		
		$config = RequestContext::getMain()->getConfig();
		$description = $params['description'] ?? $config->get( 'SensitiveDefaultDescription' );

		$linkAttribs['data-sensitive'] = 'true';
		$linkAttribs['data-width'] = $thumbnail->getWidth();
		$linkAttribs['data-height'] = $thumbnail->getHeight();
		$linkAttribs['data-description'] = $description;
	}

	public function onImageOpenShowImageInlineBefore(
		$title, $file, &$frameParams, &$handlerParams, &$time, &$res, $parser, $parserOutput
	) {
		if ( !self::isSensitive( $frameParams ) ) {
			return true;
		}
		
		if ( self::shouldBypass( RequestContext::getMain()->getUser(), $title ) ) {
			return true;
		}

		$res = self::getOverlayHTML( $frameParams );
		// Add the core module to ensure JS/CSS is loaded for this element.
		$parserOutput->addModules( 'ext.hideSensitive.core' );
		return false; // Prevent default rendering
	}

	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		$user = $out->getUser();

		// Check for sensitive thumbnails or videos on any page
		$out->addModules( 'ext.hideSensitive.core' );

		// Specific logic for File: pages
		if ( $title && $title->inNamespace( NS_FILE ) ) {
			if ( self::shouldBypass( $user, $title ) ) {
				return; // Don't hide for privileged users
			}
			
			if ( self::isSensitiveFilePage( $title ) ) {
				$out->addJsConfigVars( 'wgIsSensitiveFilePage', true );
			}
		}
	}

	public function onResourceLoaderGetConfigVars( array &$vars, $skin, \Config $config ): void {
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

