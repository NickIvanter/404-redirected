<?php
/*
 * 404 Manager Front End Functions
 *
*/

function wbz404_suggestions() {
	if ( is_404() ) {
		$options = wbz404_getOptions();
		if ( $options['display_suggest'] == '1' ) {
			echo "<div class=\"404suggest\">";
			$requestedURL = $_SERVER['REQUEST_URI'];
			$urlParts     = parse_url( $requestedURL );
			$permalinks   = wbz404_rankPermalinks( $urlParts['path'], $options['suggest_cats'], $options['suggest_tags'] );

			echo $options['suggest_title'];
			$displayed = 0;

			foreach ( $permalinks as $k => $v ) {
				$permalink = wbz404_permalinkInfo( $k, $v );

				if ( $permalink['score'] >= $options['suggest_minscore'] ) {
					if ( $displayed == 0 ) {
						echo $options['suggest_before'];
					}

					echo $options['suggest_entrybefore'];
					echo "<a href=\"" . $permalink['link'] . "\" title=\"" . $permalink['title'] . "\">" . $permalink['title'] . "</a>";
					if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
						echo " (" . $permalink['score'] . ")";
					}
					echo $options['suggest_entryafter'];
					$displayed ++;
					if ( $displayed >= $options['suggest_max'] ) {
						break;
					}
				} else {
					break;
				}
			}
			if ( $displayed >= 1 ) {
				echo $options['suggest_after'];
			} else {
				echo $options['suggest_noresults'];
			}

			//Promote Plugin
			if ( $options['404_promote'] == "1" ) {
				echo wbz404_trans( 'Generated using the' ) . " <a href=\"" . WBZ404_HOME . "\" title=\"" . wbz404_trans( 'Wordpress 404 Manager Plugin' ) . "\" target=\"_blank\">";
				echo wbz404_trans( '404 Redirected' ) . "</a> " . wbz404_trans( 'plugin written by' ) . " <a href=\"http://www.weberz.com/\" title=\"Weberz Hosting\" target=\"_blank\">Weberz Hosting</a>.";
			}
			echo "</div>";
		}
	}
}

function wbz404_process404() {
	$options = wbz404_getOptions();

	$urlRequest   = $_SERVER['REQUEST_URI'];
	$urlParts     = parse_url( $urlRequest );
	$requestedURL = $urlParts['path'];

	$queryArgs = wbz404_SortQuery( $urlParts );

	/* The only case where we want the query string to be considered part of the URL we are processing
	 * is when it contains 'page_id'. In all other cases, we want to strip the query args -- they are typically
	 * the utm labels in those other situations, which we want to ignore.
	 */
	if ( ( strpos( $queryArgs, 'page_id' ) !== false ) ) {
		$requestedURL .= $queryArgs;
	}

	//Get URL data if it's already in our database
	$redirect = wbz404_loadRedirectData( $requestedURL );

	if ( is_404() && $requestedURL != "" ) {
		if ( $redirect['id'] != '0' ) {
			//A redirect record exists.
			wbz404_ProcessRedirect( $redirect );
		} else {
			//No redirect record.
			$found = 0;
			if ( $options['auto_redirects'] == '1' ) {
				//Site owner wants automatic redirects
				$permalinks = wbz404_rankPermalinks( $requestedURL, $options['auto_cats'], $options['auto_tags'] );
				$minScore   = $options['auto_score'];

				foreach ( $permalinks as $k => $v ) {
					$permalink = wbz404_permalinkInfo( $k, $v );

					if ( $permalink['score'] >= $minScore ) {
						$found = 1;
						break;
					} else {
						//Score not high enough
						break;
					}
				}

				if ( $found == 1 ) {
					//We found a permalink that will work!
					$type = 0;
					if ( $permalink['type'] == "POST" ) {
						$type = WBZ404_POST;
					} else if ( $permalink['type'] == "CAT" ) {
						$type = WBZ404_CAT;
					} else if ( $permalink['type'] == "TAG" ) {
						$type = WBZ404_TAG;
					}
					if ( $type != 0 ) {
						$redirect_id = wbz404_setupRedirect( $requestedURL, WBZ404_AUTO, $type, $permalink['id'], $options['default_redirect'], 0 );
					}
				}
			}
			if ( $found == 1 ) {
				//Perform actual redirect
				wbz404_logRedirectHit( $redirect_id, $permalink['link'] );
				wp_redirect( $permalink['link'], $options['default_redirect'] );
				exit;
			} else {
					/* Workaround for the issue where bbPress user profile pages would be
					 * marked as 404 by the function handle_404() in the Wordpress core.
					 */
				$forums_user_pattern = '/\/forums\/users\//';
				if ( preg_match( $forums_user_pattern, $requestedURL ) && function_exists( 'bbpress' ) ) {

					$bbp = bbpress();
					if ( !empty( $bbp->displayed_user ) ) {

						return;
					}
				}

				/* Redirect requests to non-existent category pages to the first page URL.
				 */
				$paged_pattern = '/\/page\/\d\d?\/?$/';

				if ( preg_match( $paged_pattern, $requestedURL ) ) {

					$target_url = preg_replace( $paged_pattern, '/', $requestedURL );

					error_log( "Redirecting $requestedURL to $target_url in " . __FILE__ );

					wp_redirect( $target_url, 302 );
				}

				//Check for incoming 404 settings
				if ( $options['capture_404'] == '1' ) {
					$redirect_id = wbz404_setupRedirect( $requestedURL, WBZ404_CAPTURED, 0, 0, $options['default_redirect'], 0 );
					wbz404_logRedirectHit( $redirect_id, '404' );
				}
			}
		}
	} else {
		if ( is_single() || is_page() ) {
			if ( ! is_feed() && ! is_trackback() && ! is_preview() ) {
				$theID     = get_the_ID();
				$permalink = wbz404_permalinkInfo( $theID . "|POST", 0 );

				$urlParts   = parse_url( $permalink['link'] );
				$perma_link = $urlParts['path'];

				$paged = get_query_var( 'page' ) ? get_query_var( 'page' ) : false;

				if ( ! $paged === false ) {
					if ( $urlParts[ query ] == "" ) {
						if ( substr( $perma_link, - 1 ) == "/" ) {
							$perma_link .= $paged . "/";
						} else {
							$perma_link .= "/" . $paged;
						}
					} else {
						$urlParts['query'] .= "&page=" . $paged;
					}
				}

				$perma_link .= wbz404_SortQuery( $urlParts );

				//Check for forced permalinks
				if ( $options['force_permalinks'] == '1' && $options['auto_redirects'] == '1' ) {
					if ( $requestedURL != $perma_link ) {
						if ( $redirect['id'] != '0' ) {
							wbz404_ProcessRedirect( $redirect );
						} else {
							$redirect_id = wbz404_setupRedirect( $requestedURL, WBZ404_AUTO, WBZ404_POST, $permalink['id'], $options['default_redirect'], 0 );
							wbz404_logRedirectHit( $redirect_id, $permalink['link'] );
							wp_redirect( $permalink['link'], $options['default_redirect'] );
							exit;
						}
					}
				}

				if ( $requestedURL == $perma_link ) {
					//Not a 404 Link. Check for matches
					if ( $options['remove_matches'] == '1' ) {
						if ( $redirect['id'] != '0' ) {
							wbz404_cleanRedirect( $redirect['id'] );
						}
					}
				}
			}
		}
	}
}

add_action( 'template_redirect', 'wbz404_process404' );
