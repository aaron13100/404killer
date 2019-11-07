<?php

/*
	Plugin Name: 404 Solution
	Plugin URI:  http://www.ajexperience.com/wealthpsychology/404-solution/
	Description: Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors
	Author:      Aaron J
	Author URI:  http://www.ajexperience.com/wealthpsychology/404-solution/

	Version: 2.21.20

	License:     GPL2
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
	Domain Path: /languages
	Text Domain: 404-solution

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define('ABJ404_FILE', __FILE__);

// shortcode
add_shortcode('abj404_solution_page_suggestions', 'abj404_shortCodeListener');
function abj404_shortCodeListener($atts) {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($atts);
}

// admin
if (is_admin()) {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
}

// 404
add_action('template_redirect', 'abj404_404listener', 9);
function abj404_404listener() {
	// always ignore admin screens and login requests.
	$isLoginScreen = (false !== stripos(wp_login_url(), $_SERVER['SCRIPT_NAME']));
	$isAdmin = is_admin();
	if ($isAdmin || $isLoginScreen) {
        return;
    }
    
    if (!is_404()) {
    	// if we should redirect all requests then don't return.
    	$options = get_option('abj404_settings');
    	$arrayKeyExists = is_array($options) && array_key_exists('redirect_all_requests', $options);
    	if ($arrayKeyExists && $options['redirect_all_requests'] == 1) {
    		require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    		$connector = new ABJ_404_Solution_WordPress_Connector();
    		$connector->processRedirectAllRequests();
    		return;
    	}
    }
    
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $connector = new ABJ_404_Solution_WordPress_Connector();
    return $connector->process404();
}

function abj404_dailyMaintenanceCronJobListener() {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    $abj404dao->deleteOldRedirectsCron();
}
function abj404_updateLogsHitsTableListener() {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
	$abj404dao->createRedirectsForViewHitsTable();
}
function abj404_updatePermalinkCacheListener($maxExecutionTime, $executionCount = 1) {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$permalinkCache = new ABJ_404_Solution_PermalinkCache();
	$permalinkCache->updatePermalinkCache($maxExecutionTime, $executionCount);
}
add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
add_action('abj404_updateLogsHitsTableAction', 'abj404_updateLogsHitsTableListener');
add_action('abj404_updatePermalinkCacheAction', 'abj404_updatePermalinkCacheListener', 10, 2);

