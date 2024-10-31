<?php

/*
Plugin Name: Redirection PRO
Plugin URI: https://wordpress.org/plugins/redirection-pro/
Description: Redirection PRO is a plugin that enhances your WordPress website by fetching and displaying the link preview for each external link. It also creates a gateway for redirection that informs the user that they are leaving your website. It helps you improve the SEO performance of your website by preventing the user from opening broken external links through your website. This way, you can avoid losing traffic and ranking due to 404 errors and bad user experience.
Version: 1.0.0
Author: Rmanaf
Author URI: https://wordpress.org/support/users/rmanaf/
License: MIT License
Text Domain: redirection-pro
*/

use RedirectionPro\Core;

defined('ABSPATH') or die;

// Define the plugin file path
define('REDIRECTION_PRO_FILE' , __FILE__);

// Define the plugin directory URL
define('REDIRECTION_PRO_URL', plugin_dir_url(__FILE__));

// Define the plugin directory path
define('REDIRECTION_PRO_DIR', plugin_dir_path(__FILE__));


// Check if the WP_List_Table class exists
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}


// Load the plugin files 
require_once __DIR__ . "/includes/class-singleton.php";
require_once __DIR__ . "/includes/class-async-http-agent.php";
require_once __DIR__ . "/includes/class-async-http-table.php";
require_once __DIR__ . "/includes/class-page-loader.php";
require_once __DIR__ . "/includes/class-options.php";
require_once __DIR__ . "/includes/class-core.php";


Core::create();