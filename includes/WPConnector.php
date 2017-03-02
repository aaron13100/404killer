<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

    /** Setup. */
    static function init() {
        add_filter( "plugin_action_links_" . ABJ404_NAME, 'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage' );
        add_action('template_redirect', 'ABJ_404_Solution_WordPress_Connector::process404', 9999);
        add_filter('redirect_canonical', 'ABJ_404_Solution_WordPress_Connector::redirectCanonical', 10, 2);
        
        add_action('abj404_duplicateCronAction', 'ABJ_404_Solution_DataAccess::removeDuplicatesCron');
        add_action('abj404_cleanupCronAction', 'ABJ_404_Solution_DataAccess::deleteOldRedirectsCron');
        
        register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::doUnregisterCrons');
        register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginActivation');
        
        add_action('admin_notices', 'ABJ_404_Solution_WordPress_Connector::echoDashboardNotification');
        add_action('admin_menu', 'ABJ_404_Solution_WordPress_Connector::addMainSettingsPageLink');
    }

    /** 
     * Add the Settings link to the WordPress plugins page (next to activate/deactivate and edit).
     * @param type $links
     * @return type
     */
    static function addSettingsLinkToPluginPage($links) {
        if (!is_admin() || !current_user_can('administrator')) { return $links; }
        
        $settings_link = '<a href="options-general.php?page=abj404_solution&subpage=abj404_options">' . __( 'Settings' ) . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /** 
     * @global type $abj404spellChecker
     */
    function suggestions() {
        global $abj404spellChecker;
        
        if (is_404()) {
            $options = $abj404logic->getOptions();
            if (isset($options['display_suggest']) && $options['display_suggest'] == '1') {
                echo "<div class=\"suggest-404s\">";
                $requestedURL = esc_url(filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_SANITIZE_URL));

                $urlParts = parse_url($requestedURL);
                $permalinks = $abj404spellChecker->findMatchingPosts($urlParts['path'], $options['suggest_cats'], $options['suggest_tags']);

                // Allowing some HTML.
                echo wp_kses($options['suggest_title'], array(
                    'h1' => array(),
                    'h2' => array(),
                    'h3' => array(),
                    'h4' => array(),
                    'h5' => array(),
                    'h6' => array(),
                    'i' => array(),
                    'em' => array(),
                    'strong' => array(),
                        )
                );
                $displayed = 0;

                foreach ($permalinks as $k => $v) {
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($k, $v);

                    if ($permalink['score'] >= $options['suggest_minscore']) {
                        if ($displayed == 0) {
                            // No need to escape since we're expecting HTML
                            echo wp_kses($options['suggest_before'], array(
                                'ul' => array(),
                                'ol' => array(),
                                'li' => array(),
                                    )
                            );
                        }

                        echo wp_kses($options['suggest_entrybefore'], array(
                            'ul' => array(),
                            'ol' => array(),
                            'li' => array(),
                                )
                        );
                        echo "<a href=\"" . esc_url($permalink['link']) . "\" title=\"" . esc_attr($permalink['title']) . "\">" . esc_attr($permalink['title']) . "</a>";
                        if (is_user_logged_in() && current_user_can('manage_options')) {
                            echo " (" . esc_html($permalink['score']) . ")";
                        }
                        echo wp_kses($options['suggest_entryafter'], array(
                            'ul' => array(),
                            'ol' => array(),
                            'li' => array(),
                                )
                        );
                        $displayed++;
                        if ($displayed >= $options['suggest_max']) {
                            break;
                        }
                    } else {
                        break;
                    }
                }
                if ($displayed >= 1) {
                    echo wp_kses($options['suggest_after'], array(
                        'ul' => array(),
                        'ol' => array(),
                        'li' => array(),
                            )
                    );
                } else {
                    echo wp_kses($options['suggest_noresults'], $allowedtags);
                }

                echo "</div>";
            }
        }
    }

    /**
     * Process the 404s
     */
    static function process404() {
        // Bail out if not on 404 error page.
        if (!is_404()) {
            return;
        }

        global $abj404dao;
        global $abj404logic;
        global $abj404spellChecker;
        
        $options = $abj404logic->getOptions();

        $urlRequest = esc_url(preg_replace('/\?.*/', '', filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_SANITIZE_URL)));
        $urlParts = parse_url($urlRequest);
        $requestedURL = $urlParts['path'];
        $requestedURL .= $this->sortQueryParts($urlParts);

        //Get URL data if it's already in our database
        $redirect = $abj404dao->getRedirectDataFromURL($requestedURL);

        if (ABJ_404_Solution_Functions::isDebug()) {
            ABJ_404_Solution_Functions::debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " . 
                    wp_kses(json_encode($redirect), array()) . " | is_single(): " . is_single() . " | " . "is_page(): " . is_page() . 
                    " | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " . 
                    is_preview() . " | options: " . wp_kses(json_encode($options), array()));
        }

        if ($requestedURL != "") {
            if ($redirect['id'] != '0') {
                // A redirect record exists.
                $this->processRedirect($redirect);
            } else {
                // No redirect record.
                $found = 0;
                if (isset($options['auto_redirects']) && $options['auto_redirects'] == '1') {
                    // Site owner wants automatic redirects.
                    $permalinks = $abj404spellChecker->findMatchingPosts($requestedURL, $options['auto_cats'], $options['auto_tags']);
                    $minScore = $options['auto_score'];

                    foreach ($permalinks as $key => $value) {
                        $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, $value);

                        if ($permalink['score'] >= $minScore) {
                            $found = 1;
                            // TODO: this should use the highest score, not the first found?
                            break;
                        } else {
                            // Score not high enough.
                            // TODO: verify: why is this in a loop if both cases break??
                            break;
                        }
                    }

                    if ($found == 1) {
                        // We found a permalink that will work!
                        $type = 0;
                        if ($permalink['type'] == "POST") {
                            $type = ABJ404_POST;
                        } else if ($permalink['type'] == "CAT") {
                            $type = ABJ404_CAT;
                        } else if ($permalink['type'] == "TAG") {
                            $type = ABJ404_TAG;
                        }
                        if ($type != 0) {
                            $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_AUTO, $type, $permalink['id'], $options['default_redirect'], 0);
                        } else {
                            ABJ_404_Solution_Functions::errorMessage("Unhandled permalink type: " . esc_html($permalink['type']));
                        }
                    }
                }
                if ($found == 1) {
                    // Perform actual redirect.
                    $abj404dao->logRedirectHit($redirect_id, $permalink['link']);
                    wp_redirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                    exit;

                } else {
                    // Check for incoming 404 settings.
                    if (isset($options['capture_404']) && $options['capture_404'] == '1') {
                        $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_CAPTURED, 0, 0, $options['default_redirect'], 0);
                        $abj404dao->logRedirectHit($redirect_id, '404');

                    } else {
                        if (ABJ_404_Solution_Functions::isDebug()) {
                            ABJ_404_Solution_Functions::debugMessage("No permalink found to redirect to. capture_404 is off. Requested URL: " . $requestedURL . 
                                    " | Redirect: " . wp_kses(json_encode($redirect), array()) . " | is_single(): " . is_single() . " | " . 
                                    "is_page(): " . is_page() . " | is_feed(): " . is_feed() . " | is_trackback(): " . 
                                    is_trackback() . " | is_preview(): " . is_preview() . " | options: " . wp_kses(json_encode($options), array()));
                        }
                    }
                }
            }
        } else {
            if (is_single() || is_page()) {
                if (!is_feed() && !is_trackback() && !is_preview()) {
                    $theID = get_the_ID();
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($theID . "|POST", 0);

                    $urlParts = parse_url($permalink['link']);
                    $perma_link = $urlParts['path'];

                    $paged = get_query_var('page') ? esc_html(get_query_var('page')) : FALSE;

                    if (!$paged === FALSE) {
                        if ($urlParts[query] == "") {
                            if (substr($perma_link, -1) == "/") {
                                $perma_link .= $paged . "/";
                            } else {
                                $perma_link .= "/" . $paged;
                            }
                        } else {
                            $urlParts['query'] .= "&page=" . $paged;
                        }
                    }

                    $perma_link .= $this->sortQueryParts($urlParts);

                    // Check for forced permalinks.
                    if (isset($options['force_permalinks']) && isset($options['auto_redirects']) && $options['force_permalinks'] == '1' && $options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $this->processRedirect($redirect);
                            } else {
                                $redirect_id = $abj404dao->setupRedirect(esc_url($requestedURL), ABJ404_AUTO, ABJ404_POST, $permalink['id'], $options['default_redirect'], 0);
                                $abj404dao->logRedirectHit($redirect_id, $permalink['link']);
                                wp_redirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                                exit;
                            }
                        }
                    }

                    if ($requestedURL == $perma_link) {
                        // Not a 404 Link. Check for matches.
                        if ($options['remove_matches'] == '1') {
                            if ($redirect['id'] != '0') {
                                $abj404dao->deleteRedirect($redirect['id']);
                            }
                        }
                    }
                }
            }
        }

        // if there's a default 404 page specified then use that.
        $userSelected = (isset($options['dest404page']) ? $options['dest404page'] : 'none');
        if ($userSelected != "none") {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($userSelected . "|POST", 0);
            $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_AUTO, ABJ404_POST, $permalink['id'], $options['default_redirect'], 0);
            // Perform actual redirect.
            $abj404dao->logRedirectHit($redirect_id, $permalink['link']);
            wp_redirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
            exit;
        }
    }

    /** Sort each of the URL arguments by key. 
     * TODO: why even do this? the functionality should not change based on the order.
     * @param type $urlParts
     * @return string
     */
    function sortQueryParts($urlParts) {
        return;
        
        if (!isset($urlParts['query']) || $urlParts['query'] == "") {
            return "";
        }
        $url = "";

        $queryString = array();
        $urlQuery = $urlParts['query'];
        $queryParts = preg_split("/[;&]/", $urlQuery);
        foreach ($queryParts as $query) {
            if (strpos($query, "=") === false) {
                $queryString[$query] = '';
            } else {
                $stringParts = preg_split("/=/", $query);
                $queryString[$stringParts[0]] = $stringParts[1];
            }
        }
        ksort($queryString);
        $x = 0;
        $newQS = "";
        foreach ($queryString as $key => $value) {
            if ($x != 0) {
                $newQS .= "&";
            }
            $newQS .= $key;
            if ($value != "") {
                $newQS .= "=" . $value;
            }
            $x++;
        }

        if ($newQS != "") {
            $url .= "?" . $newQS;
        }

        return esc_url($url);
    }

    
    /**
     * Redirect canonicals
     */
    static function redirectCanonical($redirect, $request) {
        global $abj404dao;
        
        if (is_single() || is_page()) {
            if (!is_feed() && !is_trackback() && !is_preview()) {
                $options = $abj404logic->getOptions();


                // Sanitizing options.
                foreach ($options as $key => $value) {
                    $key = wp_kses_post($key);
                    $options[$key] = wp_kses_post($value);
                }

                $urlRequest = esc_url(filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_SANITIZE_URL));
                $urlParts = parse_url($urlRequest);

                $requestedURL = $urlParts['path'];
                $requestedURL .= $this->sortQueryParts($urlParts);

                // Get URL data if it's already in our database.
                $data = $abj404dao->getRedirectDataFromURL($requestedURL);

                if ($data['id'] != '0') {
                    $this->processRedirect($data);
                } else {
                    if ($options['auto_redirects'] == '1' && $options['force_permalinks'] == '1') {
                        $theID = get_the_ID();
                        $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($theID . "|POST", 0);
                        $urlParts = parse_url($permalink['link']);

                        $perma_link = $urlParts['path'];
                        $paged = get_query_var('page') ? esc_html(get_query_var('page')) : FALSE;

                        if (!$paged === FALSE) {
                            if ($urlParts[query] == "") {
                                if (substr($perma_link, -1) == "/") {
                                    $perma_link .= $paged . "/";
                                } else {
                                    $perma_link .= "/" . $paged;
                                }
                            } else {
                                $urlParts['query'] .= "&page=" . $paged;
                            }
                        }

                        $perma_link .= $this->sortQueryParts($urlParts);

                        if ($requestedURL != $perma_link) {
                            $redirect_id = $abj404dao->setupRedirect($requestedURL, ABJ404_AUTO, ABJ404_POST, $theID, $options['default_redirect'], 0);
                            $abj404dao->logRedirectHit($redirect_id, $perma_link);
                            wp_redirect(esc_url($perma_link), esc_html($options['default_redirect']));
                            exit;
                        }
                    }
                }
            }
        }

        if (is_404()) {
            return false;
        }

        return $redirect;
    }

    /** Redirect to the page specified. 
     * @global type $abj404dao
     * @param type $redirect
     */
    function processRedirect($redirect) {
        global $abj404dao;

        //A redirect record has already been found.
        if (( $redirect['status'] == ABJ404_MANUAL || $redirect['status'] == ABJ404_AUTO ) && $redirect['disabled'] == 0) {
            //It's a redirect, not a captured or ignored URL
            if ($redirect['type'] == ABJ404_EXTERNAL) {
                //It's a external url setup by the user
                $abj404dao->logRedirectHit($redirect['id'], $redirect['final_dest']);
                wp_redirect(esc_url($redirect['final_dest']), esc_html($redirect['code']));
                exit;
            }
            
            $key = "";
            if ($redirect['type'] == ABJ404_POST) {
                $key = $redirect['final_dest'] . "|POST";
            } else if ($redirect['type'] == ABJ404_CAT) {
                $key = $redirect['final_dest'] . "|CAT";
            } else if ($redirect['type'] == ABJ404_TAG) {
                $key = $redirect['final_dest'] . "|TAG";
            } else {
                ABJ_404_Solution_Functions::errorMessage("Unrecognized redirect type: " . esc_html($redirect['type']));
            }
            
            if ($key != "") {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, 0);
                $abj404dao->logRedirectHit($redirect['id'], $permalink['link']);
                wp_redirect(esc_url($permalink['link']), esc_html($redirect['code']));
                exit;
            }

        } else {
            $abj404dao->logRedirectHit(esc_html($redirect['id']), '404');
        }
    }

    /** Display an admin dashboard notification.
     * e.g. There are 29 captured 404 URLs that need to be processed.
     * @global type $pagenow
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404view
     */
    static function echoDashboardNotification() {
        if (!is_admin() || !current_user_can('administrator')) { return; }
        
        global $pagenow;
        global $abj404dao;
        global $abj404logic;
        global $abj404view;

        if (current_user_can('manage_options')) {
            if (( isset($_GET['page']) && $_GET['page'] == "abj404_solution" ) || ( $pagenow == 'index.php' && (!isset($_GET['page']) ) )) {
                $options = $abj404logic->getOptions();
                if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
                    $captured = $abj404dao->getCapturedCount();
                    if ($captured >= $options['admin_notification']) {
                        echo $abj404view->getDashboardNotification($captured);
                    }
                }
            }
        }
    }
    

    static function addMainSettingsPageLink() {
        if (!is_admin() || !current_user_can('administrator')) { return; }
        
        global $menu;
        global $abj404dao;
        global $abj404logic;

        $options = $abj404logic->getOptions();
        $pageName = "404 Solution";

        // Admin notice
        if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            $captured = $abj404dao->getCapturedCount();
            if (isset($options['admin_notification']) && $captured >= $options['admin_notification']) {
                $pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . esc_html($captured) . "</span></span>";
                $pos = strpos($menu[80][0], 'update-plugins');
                if ($pos === false) {
                    $menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>";
                }
            }
        }

        // this adds the settings link at Settings->404 Solution.
        add_options_page('404 Solution', $pageName, 'manage_options', 'abj404_solution', 
                'ABJ_404_Solution_View::handleAdminPageActionAndDisplay');
    }    

}

ABJ_404_Solution_WordPress_Connector::init();
