<?php
/**
* HelpCommons
*
* @package MediaWiki
* @subpackage Extensions
*
* @author: Tim 'SVG' Weyer <SVG@Wikiunity.com>
*
* @copyright Copyright (C) 2011 Tim Weyer, Wikiunity
* @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
*
*/

if (!defined('MEDIAWIKI')) {
	echo "HelpCommons extension";
	exit(1);
}

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'HelpCommons',
	'author'         => array( 'Tim Weyer' ),
	'url'            => 'https://www.mediawiki.org/wiki/Extension:HelpCommons',
	'descriptionmsg' => 'helpcommons-desc',
	'version'        => '1.4.0',
	'license-name'   => 'GPL-2.0-or-later',
);

// Internationalization
$wgMessagesDirs['HelpCommons'] = __DIR__ . '/i18n';

// Help wiki(s) where the help namespace is fetched from
// You only need to give a database if you use help pages from your own wiki family so help pages are not fetched for help wiki from help wiki
// Examples:
// $wgHelpCommonsFetchingWikis['en']['no-database']['https://meta.wikimedia.org']['w'] = 'Help Wiki'; // https://meta.wikimedia.org/w/api.php
// $wgHelpCommonsFetchingWikis['de']['dewiki']['http://de.community.wikiunity.com']['no-prefix'] = 'dem deutschsprachigen Hilfe Wiki'; // http://de.community.wikiunity.com/api.php
// $wgHelpCommonsFetchingWikis['fr']['no-database']['http://fr.wikiunity.com']['no-prefix'] = 'Wikiunity Aidé'; // http://fr.wikiunity.com/api.php
// $wgHelpCommonsFetchingWikis['ja']['no-database']['http://meta.wikimedia.org']['w'] = 'Help Wiki'; // http://meta.wikimedia.org/w/api.php
$wgHelpCommonsFetchingWikis = array();

// Enable local discussions and add an extra tab to help wiki's talk or redirect local discussions to help wiki?
$wgHelpCommonsEnableLocalDiscussions = true;

// Show re-localized categories from help wiki?
$wgHelpCommonsShowCategories = true;

// Protection levels for the help namespace and its talk namespace
// $wgHelpCommonsProtection = 'existing'; => all existing help pages and their discussions
// $wgHelpCommonsProtection = 'all'; => every help page and every help page discussion
$wgHelpCommonsProtection = false;

// HelpCommons' hooks included in Title.php
// Please see https://www.mediawiki.org/wiki/Extension:HelpCommons to include these hooks

/**
 * @param $helppage Article
 * @return bool
 */
$wgHooks['BeforePageDisplay'][] = function ( OutputPage &$helppage, Skin &$skin ) {
	global $wgRequest, $wgHelpCommonsFetchingWikis,
		$wgDBname, $wgLanguageCode, $wgOut, $wgHelpCommonsShowCategories;

	$title = $helppage->getTitle();

	$action = $wgRequest->getVal( 'action', 'view' );
	if ( $title->getNamespace() != NS_HELP || ( $action != 'view' && $action != 'purge' ) ) {
		return true;
	}

	$contentLanguage = \MediaWiki\MediaWikiServices::getInstance()->getContentLanguage();
	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return true;
					}

					$dbkey = $title->getDBkey();

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiResponse = $httpRequestFactory->get( $url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $dbkey );
					$apiData = unserialize( $apiResponse );

					if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
						return true;
					}

					foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
						if ( !isset( $pageData['missing'] ) ) {

							// remove noarticletext message and its <div> if not existent (does remove all content)
							if ( !$title->exists() ) {
								$wgOut->clearHTML();
							}

							$response = $httpRequestFactory->get(
								$url . $prefix .
								'/api.php?format=json&action=parse&prop=text|categorieshtml&redirects&disablepp&pst&text={{:Help:'
								. $dbkey . '}}'
							);
							$data = json_decode( $response, /*assoc=*/ true );
							$text = $data['parse']['text']['*'];
							$text_html = str_replace(
								'<span class="editsection">[<a href="'.$prefix.'/', '<span class="editsection">[<a href="'.$url.$prefix.'/', $text ); // re-locate [edit] links to help wiki
							if ( $wgHelpCommonsShowCategories ) {
								$categories = $data['parse']['categorieshtml']['*'];
								$categories_html = str_replace( '<a href="', '<a href="'.$url, $categories );
								$content = $text_html . $categories_html;
							} else {
								$content = $text_html;
							}
							$namespaceNames = $contentLanguage->getNamespaces();
							$wgOut->addHTML(
								'<div id="helpCommons" style="border: solid 1px; padding: 10px; margin: 5px;">' .
								'<div class="helpCommonsInfo" style="text-align: right; font-size: smaller;padding: 5px;">' .
								$helppage->msg(
									'helpcommons-info',
									$name
								)->rawParams(
									'<a href="' . $url . $prefix . '/index.php?title=Help:' . $dbkey . '" title="' . $namespaceNames[NS_HELP] . ':' . str_replace( '_', ' ', $dbkey ) . '">' . $namespaceNames[NS_HELP] . ':' . str_replace( '_', ' ', $dbkey ) . '</a>'
								)->inContentLanguage()->escaped() .
								'</div>' . $content . '</div>'
							);
							return false;
						} else {
							return true;
						}
					}
				}
			}
		}
	}

	return true;
};

/**
 * @param $helppage
 * @param $fields
 * @return bool
 */
$wgHooks['ArticleViewHeader'][] = function ( &$helppage, &$outputDone, &$pcache ) {
	global $wgHelpCommonsEnableLocalDiscussions, $wgHelpCommonsProtection, $wgHelpCommonsFetchingWikis,
		$wgLanguageCode, $wgDBname, $wgOut;

	$title = $helppage->getTitle();

	if (
		$title->getNamespace() != NS_HELP_TALK ||
		( $wgHelpCommonsEnableLocalDiscussions && $wgHelpCommonsProtection != 'all' && $wgHelpCommonsProtection != 'existing' )
	) {
		return true;
	}

	$dbkey = $title->getDBkey();

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return true;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiResponse = $httpRequestFactory->get(
						$url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $dbkey
					);
					$apiData = unserialize( $apiResponse );

					if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
						return true;
					}

					// check if page does exist
					foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
						if ( !isset( $pageData['missing'] ) && !$title->exists() ) {
							$helpCommonsRedirectTalk = $url . $prefix . '/index.php?title=Help_talk:' . $dbkey;
							$wgOut->redirect( $helpCommonsRedirectTalk );
							return false;
						} else {
							return true;
						}
					}
				}
			}
		}
	}

	return true;
};

/**
 * @param $skin Skin|SkinTemplate
 * @param $content_actions array
 * @return bool
 */
function fnHelpCommonsInsertTalkpageTab( $skin, &$content_actions ) {
	global $wgHelpCommonsEnableLocalDiscussions, $wgHelpCommonsProtection, $wgHelpCommonsFetchingWikis,
		$wgLanguageCode, $wgDBname;

	if ( !$skin->getTitle()->canExist() ||
		( $skin->getTitle()->getNamespace() != NS_HELP && $skin->getTitle()->getNamespace() != NS_HELP_TALK ) ||
		!$wgHelpCommonsEnableLocalDiscussions || $wgHelpCommonsProtection == 'all' || $wgHelpCommonsProtection == 'existing'
	) {
		return false;
	}

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return false;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiResponse = $httpRequestFactory->get(
						$url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $skin->getTitle()->getDBkey()
					);
					$apiData = unserialize( $apiResponse );

					if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
						return false;
					}

					// check if page does exist
					foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
						if ( !isset( $pageData['missing'] ) ) {

							$tab_keys = array_keys( $content_actions );

							// find the location of the 'talk' link, and
							// add the link to 'Discuss on help wiki' right before it
							$helpcommons_tab_talk = array(
								'class' => false,
								'text' => $skin->msg( 'helpcommons-discussion' )->text(),
								'href' => $url.$prefix.'/index.php?title=Help_talk:'.$skin->getTitle()->getDBkey(),
							);

							$tab_values = array_values( $content_actions );
							if ( $skin->getSkinName() == 'vector' ) {
								$tabs_location = array_search( 'help_talk', $tab_keys );
							} else {
								$tabs_location = array_search( 'talk', $tab_keys );
							}
							array_splice( $tab_keys, $tabs_location, 0, 'talk-helpwiki' );
							array_splice( $tab_values, $tabs_location, 0, array( $helpcommons_tab_talk ) );

							$content_actions = array();
							for ( $i = 0; $i < count( $tab_keys ); $i++ ) {
								$content_actions[$tab_keys[$i]] = $tab_values[$i];
							}
						}
					}
				}
			}
		}
	}

	return false;
}

/**
 * @param $skin Skin|SkinTemplate
 * @param $content_actions
 * @return bool
 */
function fnHelpCommonsInsertActionTab( $skin, &$content_actions ) {
	global $wgHelpCommonsFetchingWikis, $wgLanguageCode, $wgDBname, $wgVectorUseIconWatch;

	if ( !$skin->getTitle()->canExist() || $skin->getTitle()->getNamespace() != NS_HELP ) {
		return false;
	}

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return false;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiResponse = $httpRequestFactory->get( $url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $skin->getTitle()->getDBkey() );
					$apiData = unserialize( $apiResponse );

					if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
						return false;
					}

					// check if page does exist
					foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
						if ( !isset( $pageData['missing'] ) ) {

							$tab_keys = array_keys( $content_actions );

							// find the location of the 'edit' link or the 'watch' icon of vector, and
							// add the link to 'Edit on help wiki' right before it
							if ( array_search( 'edit', $tab_keys ) || array_search( 'watch', $tab_keys ) ) {

								$helpcommons_tab_edit = array(
									'class' => false,
									'text' => $skin->msg( 'helpcommons-edit' )->text(),
									'href' => $url.$prefix.'/index.php?title=Help:'.$skin->getTitle()->getDBkey().'&action=edit',
								);

								$tab_values = array_values( $content_actions );
								if ( $skin->getSkinName() == 'vector' && $wgVectorUseIconWatch && !$skin->getTitle()->exists() ) {
									$tabs_location = array_search( 'watch', $tab_keys );
								} else {
									$tabs_location = array_search( 'edit', $tab_keys );
								}
								array_splice( $tab_keys, $tabs_location, 0, 'edit-on-helpwiki' );
								array_splice( $tab_values, $tabs_location, 0, array( $helpcommons_tab_edit ) );

								$content_actions = array();
								for ( $i = 0; $i < count( $tab_keys ); $i++ ) {
									$content_actions[$tab_keys[$i]] = $tab_values[$i];
								}

							// find the location of the 'viewsource' link, and
							// add the link to 'Edit on help wiki' right before it
							} elseif ( array_search( 'viewsource', $tab_keys ) ) {

								$helpcommons_tab_edit = array(
									'class' => false,
									'text' => $skin->msg( 'helpcommons-edit' )->text(),
									'href' => $url.$prefix.'/index.php?title=Help:'.$skin->getTitle()->getDBkey().'&action=edit',
								);

								$tab_values = array_values( $content_actions );
								$tabs_location = array_search( 'viewsource', $tab_keys );
								array_splice( $tab_keys, $tabs_location, 0, 'edit-on-helpwiki' );
								array_splice( $tab_values, $tabs_location, 0, array( $helpcommons_tab_edit ) );

								$content_actions = array();
								for ( $i = 0; $i < count( $tab_keys ); $i++ ) {
									$content_actions[$tab_keys[$i]] = $tab_values[$i];
								}

							} else {

								$content_actions['edit-on-helpwiki'] = array(
									'class' => false,
									'text' => $skin->msg( 'helpcommons-edit' )->text(),
									'href' => $url.$prefix.'/index.php?title=Help:'.$skin->getTitle()->getDBkey().'&action=edit',
								);

							}

						} else {

							$tab_keys = array_keys( $content_actions );

							// find the location of the 'edit' link or the 'watch' icon of vector, and
							// add the link to 'Edit on help wiki' right before it
							if ( array_search( 'edit', $tab_keys ) || array_search( 'watch', $tab_keys ) ) {

								$helpcommons_tab_create = array(
									'class' => false,
									'text' => $skin->msg( 'helpcommons-create' )->text(),
									'href' => $url.$prefix.'/index.php?title=Help:'.$skin->getTitle()->getDBkey().'&action=edit',
								);

								$tab_values = array_values( $content_actions );
								if ( $skin->getSkinName() == 'vector' && $wgVectorUseIconWatch && !$skin->getTitle()->exists() ) {
									$tabs_location = array_search( 'watch', $tab_keys );
								} else {
									$tabs_location = array_search( 'edit', $tab_keys );
								}
								array_splice( $tab_keys, $tabs_location, 0, 'create-on-helpwiki' );
								array_splice( $tab_values, $tabs_location, 0, array( $helpcommons_tab_create ) );

								$content_actions = array();
								for ( $i = 0; $i < count( $tab_keys ); $i++ ) {
									$content_actions[$tab_keys[$i]] = $tab_values[$i];
								}

							} else {

								$content_actions['create-on-helpwiki'] = array(
									'class' => false,
									'text' => $skin->msg( 'helpcommons-create' )->text(),
									'href' => $url.$prefix.'/index.php?title=Help:'.$skin->getTitle()->getDBkey().'&action=edit',
								);
							}
						}
					}
				}
			}
		}
	}

	return false;
}

/**
 * @param $sktemplate SkinTemplate
 * @param $links array
 * @return bool
 */
$wgHooks['SkinTemplateNavigation::Universal'][] = function ( SkinTemplate &$sktemplate, array &$links ) {
	// the old '$content_actions' array is thankfully just a
	// sub-array of this one
	fnHelpCommonsInsertTalkpageTab( $sktemplate, $links['namespaces'] );
	fnHelpCommonsInsertActionTab( $sktemplate, $links['views'] );
	return true;
};

/**
 * @param $title Title
 * @param $user User
 * @param $action
 * @param $result
 * @return bool
 */
$wgHooks['getUserPermissionsErrors'][] = function ( &$title, &$user, $action, &$result ) {
	global $wgHelpCommonsFetchingWikis, $wgDBname, $wgLanguageCode, $wgHelpCommonsEnableLocalDiscussions, $wgHelpCommonsProtection;

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return true;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					$ns = $title->getNamespace();

					// don't protect existing pages
					if ( $title->exists() ) {
						return true;
					}

					// block actions 'create', 'edit' and 'protect'
					if ( $action != 'create' && $action != 'edit' && $action != 'protect' ) {
						return true;
					}

					if ( !$wgHelpCommonsEnableLocalDiscussions && $ns == NS_HELP_TALK ) {
						// error message if action is blocked
						$result = array( 'protectedpagetext' );
						// bail, and stop the request
						return false;
					}

					switch ( $wgHelpCommonsProtection ) {

						case 'all':
							if ( $ns == NS_HELP || $ns == NS_HELP_TALK ) {
								// error message if action is blocked
								$result = array( 'protectedpagetext' );
								// bail, and stop the request
								return false;
							}
							break;

						case 'existing':
							// check if requested page does exist
							$apiResponse = $httpRequestFactory->get( $url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $title->getDBkey() );
							$apiData = unserialize( $apiResponse );

							if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
								return true;
							}

							// check if page does exist
							foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
								if ( !isset( $pageData['missing'] ) && ( $ns == NS_HELP || $ns == NS_HELP_TALK ) ) {
									// error message if action is blocked
									$result = array( 'protectedpagetext' );
									// bail, and stop the request
									return false;
								}
							}
							break;

						default:
							return true;
					}
				}
			}
		}
	}

	return true;
};

/**
 * This hook is not needed when 'TitleHelpCommonsPageIsKnown' and 'TitleHelpCommonsTalkIsKnown' hooks are used but it does not need to be removed
 *
 * @param $skin
 * @param $target Title
 * @param $text
 * @param $customAttribs
 * @param $query
 * @param $options array
 * @param $ret
 * @return bool
 */
$wgHooks['LinkBegin'][] = function ( $skin, $target, &$text, &$customAttribs, &$query, &$options, &$ret ) {
	global $wgHelpCommonsEnableLocalDiscussions, $wgHelpCommonsFetchingWikis, $wgLanguageCode, $wgDBname;

	if ( is_null( $target ) ) {
		return true;
	}

	if ( ( $target->getNamespace() != NS_HELP && $target->getNamespace() != NS_HELP_TALK ) || $target->exists() ) {
		return true;
	}

	if ( $wgHelpCommonsEnableLocalDiscussions && $target->getNamespace() == NS_HELP_TALK ) {
		return true;
	}

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode == $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return true;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiResponse = $httpRequestFactory->get(
						$url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $target->getDBkey()
					);
					$apiData = unserialize( $apiResponse );

					if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
						return true;
					}

					// check if page does exist
					foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
						if ( !isset( $pageData['missing'] ) ) {

							// remove "broken" assumption/override
							$brokenKey = array_search( 'broken', $options, true );
							if ( $brokenKey !== false ) {
								unset( $options[$brokenKey] );
							}

							// make the link "blue"
							$options[] = 'known';

						}
					}
				}
			}
		}
	}

	return true;
};

/**
 * @param $page Article
 * @return bool
 */
$wgHooks['TitleHelpCommonsPageIsKnown'][] = function ( $page ) {
	global $wgHelpCommonsFetchingWikis, $wgLanguageCode, $wgDBname;

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return false;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiResponse = $httpRequestFactory->get( $url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $page->getDBkey() );
					$apiData = unserialize( $apiResponse );

					if ( !$apiResponse || !$apiData || !$apiData['query'] ) {
						return false;
					}

					// check if page does exist
					foreach( $apiData['query']['pages'] as $pageId => $pageData ) {
						return !isset( $pageData['missing'] );
					}
				}
			}
		}
	}

	return false;
};

/**
 * @param $talk Article
 * @return bool
 */
$wgHooks['TitleHelpCommonsTalkIsKnown'][] = function ( $talk ) {
	global $wgHelpCommonsEnableLocalDiscussions, $wgHelpCommonsFetchingWikis, $wgLanguageCode, $wgDBname;

	if ( $wgHelpCommonsEnableLocalDiscussions ) {
		return false;
	}

	$httpRequestFactory = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
	foreach ( $wgHelpCommonsFetchingWikis as $language => $dbs ) {
		foreach ( $dbs as $db => $urls ) {
			foreach ( $urls as $url => $helpWikiPrefixes ) {
				foreach ( $helpWikiPrefixes as $helpWikiPrefix => $name ) {
					if ( $wgLanguageCode != $language ) {
						continue;
					}

					if ( $db != 'no-database' && $wgDBname == $db ) {
						return false;
					}

					if ( $helpWikiPrefix != 'no-prefix' ) {
						$prefix = '/' . $helpWikiPrefix;
					} else {
						$prefix = '';
					}

					// check if requested page does exist
					$apiPageResponse = $httpRequestFactory->get( $url . $prefix . '/api.php?format=php&action=query&titles=Help:' . $talk->getDBkey() );
					$apiPageData = unserialize( $apiPageResponse );

					if ( !$apiPageResponse || !$apiPageData || !$apiPageData['query'] ) {
						return false;
					}

					// check if requested talkpage does exist
					$apiTalkResponse = $httpRequestFactory->get( $url . $prefix . '/api.php?format=php&action=query&titles=Help_talk:' . $talk->getDBkey() );
					$apiTalkData = unserialize( $apiTalkResponse );

					if ( !$apiTalkResponse || !$apiTalkData || !$apiTalkData['query'] ) {
						return false;
					}

					// check if page and its talk do exist
					foreach( $apiPageData['query']['pages'] as $pageId => $pageData ) {
						foreach( $apiTalkData['query']['pages'] as $talkId => $talkData ) {
							return !isset( $pageData['missing'] ) && !isset( $talkData['missing'] );
						}
					}
				}
			}
		}
	}

	return false;
};
