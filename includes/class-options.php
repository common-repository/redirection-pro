<?php

namespace RedirectionPro;

use Exception;

/**
 * The Options class is responsible for managing and providing access to the plugin's settings and options.
 *
 * This class acts as an interface to retrieve and manipulate various configuration settings used throughout
 * the Redirection PRO plugin. It encapsulates functions related to options retrieval, storage, and validation.
 *
 * @package RedirectionPro
 * @since 1.0.0
 * @final
 */
final class Options extends SingletonClass
{

    private static $_instance = null;
    private static $_httpHandler = null;
    private static $_options = array();



    private const NONCE_NAME   = '_rpnonce_settings';
    private const NONCE_ACTION = '_rpnonce_settings_action';

    private const OPTION_NAME = 'redirection-pro_settings';


    /**
     * Making the constructor private ensures that instances of the Options class can only 
     * be created through the create method, which provides additional control and validation.
     * 
     * @since 1.0.0
     */
    private function __construct($httpHandler)
    {

        self::$_httpHandler = $httpHandler;

        self::$_options = array(
            'enable_replace'    => true,
            'ssr_redirection'   => true,
            'distort_link'      => false,
            'get_og_info'       => true,
            'enable_preview'    => true,
            'enqueue_tippy'     => true,
            'enqueue_popper'    => true,
            'redirection_page'  => 0,
            'preview_animation' => array(
                'default' => 'fade',
                'options' => array('fade', 'shift-away', 'shift-toward', 'scale', 'perspective')
            ),
            'preview_theme'     => array(
                'default' => 'light-border',
                'options' => array('light', 'light-border', 'material', 'translucent', 'rp-light', 'rp-dark')
            ),
            'preview_delay' => array(
                'default' => 300,
                'min' => 100,
                'max' => 1500
            ),
            'cache_duration' => array(
                'default' => YEAR_IN_SECONDS,
                'min' => DAY_IN_SECONDS,
                'max' => YEAR_IN_SECONDS
            ),
            'lazy_mode'         => array(
                'default' => 'legacy',
                'options' => array('legacy')
            ),
            'trusted_domains'   => array(
                'default' => "# Domains :\nautomattic.com\nmicrosoft.com\n\n# IP Addresses:\n192.168.0.1",
                'validate_callback' => array($this, '_validate_trusted_domains')
            ),
            'timer' => array(
                'default'  => 8,
                'min' => 3,
                'max' => 15
            )
        );

    }


    /**
     * Factory method for creating or retrieving the Options instance.
     * 
     * @since 1.0.0
     * 
     * @param mixed ...$args The AsyncHttpAgent instance.
     * @return Options The created or existing Options instance.
     */
    public static function create(...$args)
    {
        // Check if arguments were provided and if an instance doesn't already exist.
        if ((count($args) > 0) && is_null(self::$_instance) && ($args[0] instanceof AsyncHttpAgent)) {
            // Create a new instance of Options using the provided HTTP handler.
            self::$_instance = new self($args[0]);

            self::$_instance->_init();

            // Return the newly created instance.
            return self::$_instance;
        } else {
            // If conditions are not met, display an error message and halt script execution.
            wp_die('Creating an instance requires a secret handshake and a password only known to penguins.');
        }
    }



    /**
     * Initializes the class.
     * 
     * @since 1.0.0
     */
    private function _init()
    {

        // Add the Redirection PRO options page to the admin menu.
        add_action('admin_menu', array($this, '_add_options_page'));

        // Register plugin settings and options.
        add_action('admin_init', array($this, '_register_settings'));

        // Enqueue necessary scripts and styles for the admin pages.
        add_action('admin_enqueue_scripts', array($this, '_admin_enqueue_scripts'));

        // Add custom post states to indicate the presence of a redirection page.
        add_filter('display_post_states', array($this, '_add_redirection_page_state'), 10, 2);

        // Add delete action to the queue row
        add_filter('redirection-pro_queue_row', array($this, '_custom_queue_row'), 10, 2);

        // Create the 'Redirect' page automatically upon plugin activation.
        register_activation_hook(REDIRECTION_PRO_FILE, array($this, '_create_redirect_page'));
    }



    /**
     * Adds the Redirection PRO options page to the WordPress admin menu and sets up subpages.
     *
     * This method adds the main options page and subpages to the WordPress admin menu.
     * It also handles clearing the cache and resetting options when the respective subpages are accessed.
     *
     * @since 1.0.0
     * @access private
     */
    public function _add_options_page()
    {


        // Add the main Redirection PRO options page to the admin menu.
        add_options_page(
            esc_html__('Redirection PRO', 'redirection-pro'),
            esc_html__('Redirection PRO', 'redirection-pro'),
            'manage_options',
            'redirection-pro-settings',
            array(&$this, '_render_options_page')
        );


        // Add a 'Queue' subpage to the Redirection PRO options page.
        add_submenu_page(
            'redirection-pro-settings',
            esc_html__('Queue', 'redirection-pro'),
            esc_html__('QUeue', 'redirection-pro'),
            'manage_options',
            'redirection-pro-queue',
            array(&$this, '_render_queue_page')
        );


        // Determine the current page for redirection purposes.
        $current_page = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : admin_url();


        // Add a 'Clear Cache' subpage with a callback for cache clearing.
        $ccpage = add_submenu_page('',  '', '',   'manage_options',  'redirection-pro-clear-cache', '__return_false',  null);
        add_action('load-' . $ccpage, function () use ($current_page) {

            // Verify the nonce for security.
            if ($this->_check_nonce()) {

                // Clear plugin caches.
                self::$_httpHandler->clear_caches();

                // Redirect back to the original page after cache clearing.
                wp_safe_redirect($current_page);

                exit;
            }
        });


        // Add a 'Reset' subpage with a callback for resetting options.
        $reset_page = add_submenu_page('',  '', '',   'manage_options',  'redirection-pro-reset', '__return_false',  null);
        add_action('load-' . $reset_page, function () use ($current_page) {

            // Verify the nonce for security.
            if ($this->_check_nonce()) {

                // Delete Redirection PRO options.
                delete_option(self::OPTION_NAME);

                // Redirect back to the original page after resetting options.
                wp_safe_redirect($current_page);

                exit;
            }
        });
    }



    /**
     * Registers plugin settings with WordPress, allowing for data validation during option updates.
     *
     * This method is responsible for registering the Redirection PRO plugin settings with WordPress.
     * It associates the settings with the plugin's option name, specifies a callback function,
     * '_validate_options', for data validation during option updates, and defines the settings' properties.
     *
     * @since 1.0.0
     * @access private
     */
    public  function _register_settings()
    {
        register_setting(
            self::OPTION_NAME,
            self::OPTION_NAME,
            array(
                'type' => 'array',
                'sanitize_callback' => array(&$this, '_validate_options'),
                'show_in_rest' => false,
                'default' => $this->_get_defaults()
            )
        );
    }



    /**
     * Enqueues styles for the Redirection PRO plugin in the WordPress admin panel.
     *
     * This method checks the current admin screen and enqueues the 'redirection-pro' stylesheet
     * when the screen corresponds to either the Redirection PRO settings page or the queue page.
     *
     * @since 1.0.0
     * @access private
     */
    public  function _admin_enqueue_scripts()
    {
        if (self::_is_rp_screen() ) {
            wp_enqueue_style('redirection-pro', REDIRECTION_PRO_URL . '/assets/css/admin.css');
        }
    }



    /**
     * Check if the current screen matches the defined Redirection PRO pages.
     * 
     * @since 1.0.0
     */

    private static function _is_rp_screen(){

        $screen = get_current_screen();

        $rpPages = array(
            'settings_page_redirection-pro-settings',
            'settings_page_redirection-pro-queue'
        );

        return in_array($screen->id, $rpPages);

    }




    /**
     * Adds a custom post state for the Redirection PRO redirection page.
     *
     * This method modifies the post states displayed in the WordPress admin panel for individual posts.
     * If a post has been configured as the redirection page in the Redirection PRO plugin settings,
     * it adds the 'Redirection Page' label to the list of post states.
     *
     * @param array   $post_states An array of post states for the current post.
     * @param WP_Post $post        The current post object.
     *
     * @return array An updated array of post states.
     *
     * @since 1.0.0
     * @access private
     */
    public  function _add_redirection_page_state($post_states, $post)
    {
        // Extract Redirection PRO settings.
        extract($this->get_options());

        // Check if the current post is designated as the redirection page.
        if ($redirection_page && $redirection_page == $post->ID) {
            $post_states['redirection-pro_redirection_page'] = esc_html__('Redirection Page', 'redirection-pro');
        }

        return $post_states;
    }




    /**
     * Creates the 'Redirect' page automatically upon plugin activation if it doesn't already exist.
     *
     * This method checks if the 'Redirect' page exists within the WordPress pages and, if not found,
     * it creates a new page with the title 'Redirect' and inserts the '[redirection_pro_timer]' shortcode
     * content into it. This page is used for redirection purposes.
     *
     * @since 1.0.0
     * @access private
     */
    public function _create_redirect_page()
    {
        $options = $this->get_options();

        // Check if the 'redirection_page' option is already set
        if (!empty($options['redirection_page']) && $options['redirection_page'] > 0) {
            return;
        }

        $title = esc_html__('Redirecting…' , 'redirection-pro');

        // Attempt to find an existing page with the title 'Redirect'
        $generatedPage = get_posts([
            'title' => $title,
            'post_type' => 'page',
        ]);

        // Create the 'Redirect' page if it doesn't exist
        if (empty($generatedPage)) {
            $pageId = wp_insert_post(array(
                'post_title'   => $title,
                'post_content' => '<!-- wp:shortcode -->[redirection_pro_timer]<!-- /wp:shortcode -->',
                'post_status'  => 'publish',
                'post_type'    => 'page'
            ));

            // Update the 'redirection_page' option with the created page ID
            $options['redirection_page'] = $pageId;

            update_option(self::OPTION_NAME, $options);

            // Flush rewrite rules to update permalinks
            flush_rewrite_rules();
        }
    }



    /**
     * Validates and sanitizes the plugin's options based on defined configuration.
     *
     * This method is responsible for validating and sanitizing the input options
     * provided for the plugin based on the predefined configuration.
     * It iterates through each option, checks its type and constraints, and applies
     * appropriate validation and sanitization methods. The method ensures that the input
     * options adhere to the specified data types, allowable values, and validation
     * callbacks. If any option is invalid or out of bounds, it is adjusted to its default
     * or allowable value.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $input The input options to validate and sanitize.
     * @return array The validated and sanitized options.
     */
    public  function _validate_options($input)
    {
        // Loop through each option defined in the plugin's configuration.
        foreach (self::$_options as $key => $value) {
            // Determine the default value for the option.
            $default = is_array($value) && isset($value['default']) ? $value['default'] : $value;
            // Check if there are allowable options.
            $options = is_array($value) && isset($value['options']) ? $value['options'] : array();
            // Check if there's a custom validation callback.
            $validate_callback = is_array($value) && isset($value['validate_callback']) ? $value['validate_callback'] : null;

            // Determine the data type of the default value.
            $type = gettype($default);

            // Initialize a variable to store the new validated and sanitized value.
            $newVal = $default;

            // Perform validation and sanitization based on data type.
            switch ($type) {
                case 'string':
                    // Sanitize and validate strings using WordPress's sanitize_textarea_field.
                    $newVal = isset($input[$key]) ? sanitize_textarea_field($input[$key]) : '';
                    break;
                case 'boolean':
                    // Cast input values to boolean if they exist, otherwise use the default.
                    $newVal = isset($input[$key]) ? (bool)$input[$key] : false;
                    break;
                case 'integer':
                    // Validate integers and apply minimum and maximum constraints if defined.
                    $min = is_array($value) && isset($value['min']) ? $value['min'] : 0;
                    $max = is_array($value) && isset($value['max']) ? $value['max'] : PHP_INT_MAX;

                    $newVal = isset($input[$key]) ? absint($input[$key]) : $default;

                    // Ensure the integer falls within defined constraints.
                    if ($newVal < $min) {
                        $newVal = $min;
                    }

                    if ($newVal > $max) {
                        $newVal = $max;
                    }

                    break;
            }

            // Apply custom validation callback if provided.
            if (!is_null($validate_callback)) {
                $newVal = $validate_callback($newVal);
            }

            // Check if there are allowable options and switch to the first if the new value is not in the list.
            if (!empty($options) && !in_array($newVal, $options)) {
                $newVal = $options[0];
            }

            // Update the input array with the validated and sanitized value.
            $input[$key] = $newVal;
        }

        return $input;
    }



    /**
     * Validates a list of trusted domains or IP addresses separated by newline characters.
     *
     * This method is responsible for validating a list of trusted domains or IP addresses
     * provided as input. The input value is expected to contain domains or IP addresses separated
     * by newline characters.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $value The input value containing domains or IP addresses.
     * @return string The validated input value.
     */
    public  function _validate_trusted_domains($value)
    {

        // Define the regular expression pattern to match valid domains or IP addresses separated by newline
        $pattern = '/(?:^#[^\n]*\s*$)|(?:^\s+$)|(?:^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\s*$)|(?:^[a-zA-Z0-9-]+\.[a-zA-Z]+\s*$)|(?:\\n)/m';

        $replaceCheck = preg_replace($pattern, '' , $value);

        if (strlen($replaceCheck)) {

            add_action('admin_notices', function () {

                if(!self::_is_rp_screen()){
                    return;
                }

                echo wp_kses_post(
                    sprintf(
                        '<div class="error"><p>%1$s</p></div>',
                        esc_html__('Your input for trusted domains is not valid. Please enter domains or IPs in each line, and you can add comments by starting a line with #.', 'redirection-pro')
                    )
                );
            });
        }

        return $value;
    }




    /**
     * Private method used to check the validity of the settings page nonce.
     *
     * @since 1.0.0
     *
     * @return bool True if the nonce is valid, false otherwise.
     */
    private  function _check_nonce()
    {
        // Retrieve the nonce value from the request, if it exists.
        $nonce = isset($_REQUEST[self::NONCE_NAME]) ? sanitize_text_field($_REQUEST[self::NONCE_NAME]) : '';

        // Verify the nonce using the specified nonce action.
        return wp_verify_nonce($nonce, self::NONCE_ACTION);
    }




    /**
     * Private method used to retrieve default values for the plugin options.
     *
     * @since 1.0.0
     *
     * @return array An associative array containing default values for plugin options.
     */
    private  function _get_defaults()
    {
        return array_map(function ($item) {
            if (is_array($item)) {
                return $item['default'];
            }
            return $item;
        }, self::$_options);
    }




    /**
     * Public method to retrieve and validate the plugin options.
     *
     * @since 1.0.0
     *
     * @return array An associative array containing validated plugin options.
     */
    public  function get_options()
    {
        return $this->_validate_options(get_option(self::OPTION_NAME, $this->_get_defaults()));
    }




    /**
     * Private method to render navigation tabs for Redirection PRO plugin settings pages.
     *
     * @since 1.0.0
     *
     * @param string $active The identifier of the currently active tab.
     * @param bool $echo Whether to echo the generated HTML or return it.
     *
     * @return string|null If 'echo' is false, returns the HTML for the navigation tabs; otherwise, prints it.
     */
    private  function _render_tabs($active = 'settings', $echo = true)
    {
        // Prefix for menu pages
        $prefix = 'redirection-pro';

        // Template for individual tab links
        $tabTemplate = '<a href="%1$s" class="nav-tab %2$s">%3$s</a>';

        $wrapTemplate = '<h2 class="nav-tab-wrapper">%s</h2><br class="clear">';

        // Define the tabs and their labels
        $tabs = array(
            "settings" => esc_html__('General Settings', 'redirection-pro'),
            "queue"    => esc_html__('Queue', 'redirection-pro')
        );

        $items = '';

        // Generate HTML for each tab link
        foreach ($tabs as $key => $value) {
            $items .= sprintf( $tabTemplate , menu_page_url("$prefix-$key", false), $active === $key ? 'nav-tab-active' : '', $value);
        }

        // Combine tab links and wrap them in a container
        $result = sprintf($wrapTemplate , $items);

        // Output or return the generated HTML based on the 'echo' parameter
        if ($echo) {
            echo wp_kses_post($result);
        }

        return $result;
    }



    /**
     * Private method to customize actions for a row in the queue table.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $item An associative array representing the item/row in the queue.
     * @param array $actions An array of action links associated with the item.
     *
     * @return array An updated array of action links for the queue item.
     */
    public  function _custom_queue_row($item_id, $actions)
    {
        // Get the current page identifier from the query string, if available
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        // Construct the URL for deleting the queue item and add a nonce for security
        $deleteUrl = sprintf('?page=%1$s&action=%2$s&element=%3$s', $page, 'delete', $item_id);
        $deleteUrl = $this->_nonce_url($deleteUrl);

        // Add a 'Delete' action link to the item's actions
        $actions['delete'] =  sprintf('<a href="%1$s">%2$s</a>', esc_attr($deleteUrl), esc_html__('Delete', 'redirection-pro'));

        return $actions;
    }




    /**
     * Render the queue page for the plugin.
     *
     * @since 1.0.0
     * @access private
     */
    public  function _render_queue_page()
    {
        // Initialize variables and setup the page template
        $search_query = '';
        $items_per_page =  10;
        $template = '<div class="wrap"><h1>%1$s</h1>%2$s<form method="post">%3$s</form></div>';

        // Create an instance of the AsyncHttpTable class to display the queue table
        $table = new AsyncHttpTable();

        // Get the current action being performed
        $action = $table->current_action();

        // Check if the security nonce is valid
        if ($this->_check_nonce()) {
            // Handle actions based on user interaction
            if (isset($_POST['s'])) {
                // Sanitize and retrieve the search query
                $search_query = sanitize_text_field($_POST['s']);
            }

            switch ($action) {
                case 'delete':
                    if (isset($_GET['element'])) {
                        // Retrieve and sanitize the element ID, then clear its cache
                        $element = sanitize_text_field($_GET['element']);
                        self::$_httpHandler->clear_caches($element);
                    }
                    break;
                case 'delete_all':
                    // Check if any items were selected for bulk deletion
                    if (isset($_POST['bulk-action']) && is_array($_POST['bulk-action'])) {
                        // Process each selected item and clear its cache
                        foreach ($_POST['bulk-action'] as $item_id) {
                            $element = sanitize_text_field($item_id);
                            self::$_httpHandler->clear_caches($element);
                        }
                    }
                    break;
            }
        }

        $table->items_per_page = $items_per_page;
        
        // Retrieve and set the items in the table
        $table->items = self::$_httpHandler->get_queue('', $search_query, $table->get_pagenum() - 1 ,  $items_per_page );

        // Start output buffering for rendering the page
        ob_start();

        // Generate a security nonce field
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        // Prepare and display the table
        $table->prepare_items();
        $table->search_box(esc_html__('Search', 'redirection-pro'), 'queue_search');
        $table->display();

        // Get the HTML content from the output buffer
        $form = ob_get_clean();

        // Render the final page with appropriate headings and tabs
        echo wp_kses_post( sprintf( $template , esc_html__('Redirection PRO', 'redirection-pro'), $this->_render_tabs('queue', false), $form) );
    }





    /**
     * Private method for creating a URL with a WordPress security nonce.
     *
     * @since 1.0.0
     *
     * @param string $url The URL to which the security nonce should be added.
     *
     * @return string The URL with the appended security nonce.
     */
    private function _nonce_url($url)
    {
        return wp_nonce_url($url, self::NONCE_ACTION, self::NONCE_NAME);
    }




    /**
     * Render the options page.
     *
     * @since 1.0.0
     * @access private
     */
    public  function _render_options_page()
    {

        extract($this->get_options());

        $pages = get_pages();

        $adminUrl = admin_url('admin.php');

        $clearCacheUrl = add_query_arg('page', 'redirection-pro-clear-cache', $adminUrl);
        $clearCacheUrl = $this->_nonce_url($clearCacheUrl);

        $resetSettingsUrl = add_query_arg('page', 'redirection-pro-reset', $adminUrl);
        $resetSettingsUrl = $this->_nonce_url($resetSettingsUrl);

?>

        <div class="wrap rp-wrap">

            <h1><?php esc_html_e('Redirection PRO', 'redirection-pro'); ?></h1>

            <?php $this->_render_tabs('settings') ?>

            <form method="post" action="options.php">

                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <?php settings_fields(self::OPTION_NAME); ?>

                <?php do_settings_sections(self::OPTION_NAME); ?>

                <table class="form-table">

                    <tr valign="top">

                        <th scope="row">

                            <?php esc_html_e('Replace External Links', 'redirection-pro'); ?>

                        </th>

                        <td>

                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enable_replace]" value="1" <?php checked($enable_replace, 1); ?> />

                            <label for="<?php echo esc_attr(self::OPTION_NAME); ?>[enable_replace]">
                                <?php esc_html_e('Enable external link replacing', 'redirection-pro'); ?>
                            </label>

                            <p class="description">
                                <?php esc_html_e('Enable this option to automatically replace external links on the page with internal links. Each internal link will include a parameter used for redirecting users to the corresponding external URL. Not recommended for optimal SEO practices.', 'redirection-pro'); ?>
                            </p>

                        </td>

                    </tr>

                    <tr>
                        <th scope="row">

                            <?php esc_html_e('Distort External Links', 'redirection-pro'); ?>

                        </th>

                        <td>

                            <label for="redirection_pro_distort_link">

                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[distort_link]" id="redirection_pro_distort_link" value="1" <?php checked($distort_link, true); ?>>

                                <?php esc_html_e('Apply link obfuscation to external links.', 'redirection-pro'); ?>

                            </label>

                            <p class="description">

                                <?php esc_html_e('Please note that while this can prevent search engines from easily discovering external links on your website, it may have negative implications for SEO.', 'redirection-pro'); ?>

                            </p>

                        </td>
                    </tr>

                    <tr>
                        <th scope="row">

                            <?php esc_html_e('Trusted Domains', 'redirection-pro'); ?>

                        </th>

                        <td>

                            <textarea rows="5" class="large-text" name="<?php echo esc_attr(self::OPTION_NAME); ?>[trusted_domains]" id="redirection_pro_trusted_domains"><?php echo esc_html($trusted_domains) ?></textarea>

                            <p class="description">
                                <?php esc_html_e("To add trusted domains, enter each domain on a new line, without including the scheme (e.g., http:// or https://), subdomain, or path. Only the root domain should be entered. (e.g. example.com)", 'redirection-pro'); ?>
                            </p>

                        </td>
                    </tr>

                </table>

                 <!-- section: Redirection Settings -->

                <h2 class="title">
                    <?php esc_html_e('Redirection Settings', 'redirection-pro'); ?>
                </h2>
                <p>
                    <?php esc_html_e('Configure the redirection behavior for external links. Choose the redirection page containing a countdown timer, set the initial timer value, and optionally enable direct redirection without displaying the countdown page to visitors.', 'redirection-pro'); ?>
                </p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <?php esc_html_e('Server-Side Redirection', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[ssr_redirection]" value="1" <?php checked($ssr_redirection, 1); ?> />
                            <label for="<?php echo esc_attr(self::OPTION_NAME); ?>[ssr_redirection]">Redirect before page loads</label>
                            <p class="description">
                                <?php esc_html_e('If enabled, the redirection will happen before the page finishes loading. If disabled, the countdown page will be displayed.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">
                            <?php esc_html_e('Redirection Page', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[redirection_page]">
                                <option value="0"><?php esc_html_e('Select a page', 'redirection-pro'); ?></option>
                                <?php foreach ($pages as $page) { ?>
                                    <option value="<?php echo esc_attr($page->ID); ?>" <?php selected(intval($redirection_page) == $page->ID); ?>><?php echo esc_html($page->post_title); ?></option>
                                <?php } ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Select a page to display the countdown timer before redirection (if server-side redirection is disabled).', 'redirection-pro'); ?>
                                <strong>
                                    <?php esc_html_e('Use the timer shortcode', 'redirection-pro'); ?>
                                    &nbsp
                                    <kbd>[redirection_pro_timer]</kbd>
                                </strong>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">
                            <?php esc_html_e('Countdown Timer (seconds)', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[timer]" min="3" max="15" value="<?php echo esc_attr($timer); ?>" />
                            <p class="description">
                                <?php esc_html_e('Set the duration of the countdown timer between 3 and 15 seconds.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- section: Link Preview Settings -->

                <h2 class="title">
                    <?php esc_html_e('Link Preview Settings', 'redirection-pro'); ?>
                </h2>
                <p>
                    <?php esc_html_e('Customize the appearance and behavior of link previews using Tippy.js. Configure the theme, animation, and delay for the link previews. Additionally, control whether the link previews display Open Graph data such as title, description, and image, and choose whether the Open Graph data pops up when hovering over the links.', 'redirection-pro'); ?>
                </p>

                <table class="form-table">

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Open Graph', 'redirection-pro'); ?>
                        </th>
                        <td>

                            <label for="redirection_pro_get_og_info">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[get_og_info]" id="redirection_pro_get_og_info" value="1" <?php checked($get_og_info, true); ?>>
                                <?php esc_html_e('Retrieve Open Graph information', 'redirection-pro'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Enable this option to automatically fetch Open Graph title, image, and description for the link tag.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Enable Link Preview', 'redirection-pro'); ?>
                        </th>
                        <td>

                            <label for="redirection_pro_enable_preview">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enable_preview]" id="redirection_pro_enable_preview" value="1" <?php checked($enable_preview, true); ?>>
                                <?php esc_html_e('Enables link preview on mouse hover.', 'redirection-pro'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Theme', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[preview_theme]">
                                <option value="light" <?php selected($preview_theme == 'light'); ?>>Light</option>
                                <option value="light-border" <?php selected($preview_theme == 'light-border'); ?>>Light + Border</option>
                                <option value="material" <?php selected($preview_theme == 'material'); ?>>Material</option>
                                <option value="translucent" <?php selected($preview_theme == 'translucent'); ?>>Translucent</option>
                                <option value="rp-light" <?php selected($preview_theme == 'rp-light'); ?>>RP Light</option>
                                <option value="rp-dark" <?php selected($preview_theme == 'rp-dark'); ?>>RP Dark</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Animation', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[preview_animation]">
                                <option <?php selected($preview_animation == 'fade'); ?> value="fade">
                                    <?php esc_html_e('Fade', 'redirection-pro'); ?>
                                </option>
                                <option <?php selected($preview_animation == 'shift-away'); ?> value="shift-away">
                                    <?php esc_html_e('Shift Away', 'redirection-pro'); ?>
                                </option>
                                <option <?php selected($preview_animation == 'shift-toward'); ?> value="shift-toward">
                                    <?php esc_html_e('Shift Toward', 'redirection-pro'); ?>
                                </option>
                                <option <?php selected($preview_animation == 'scale'); ?> value="scale">
                                    <?php esc_html_e('Scale'); ?>
                                </option>
                                <option <?php selected($preview_animation == 'perspective'); ?> value="perspective">
                                    <?php esc_html_e('Perspective'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Delay', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[preview_delay]" min="100" max="1500" value="<?php echo esc_attr($preview_delay); ?>" />
                            <p class="description">
                                <?php esc_html_e('The delay for displaying the link preview, in milliseconds.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <!-- section: Asset Control -->

                <h2 class="title">
                    <?php esc_html_e('Asset Control', 'redirection-pro'); ?>
                </h2>
                <p>
                    <?php esc_html_e('Manage the assets used by the plugin, such as Tippy.js and Popper.js, to avoid conflicts with other plugins. Enable or disable Tippy.js and Popper.js based on your requirements.', 'redirection-pro'); ?>
                </p>

                <table class="form-table">

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Tippy.js', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <label for="redirection_pro_enqueue_tippy">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enqueue_tippy]" id="redirection_pro_enqueue_tippy" value="1" <?php checked($enqueue_tippy, true); ?>>
                                <?php esc_html_e('Enqueue Tippy.js', 'redirection-pro'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Disable this option if Tippy.js is already present in your theme or other plugins.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>


                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Popper.js', 'redirection-pro'); ?>
                        </th>

                        <td>
                            <label for="redirection_pro_enqueue_popper">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enqueue_popper]" id="redirection_pro_enqueue_popper" value="1" <?php checked($enqueue_popper, true); ?>>
                                <?php esc_html_e('Enqueue Popper.js', 'redirection-pro'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Disable this option if Popper.js is already present in your theme or other plugins.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <!-- section: Cache Control -->

                <h2 class="title">
                    <?php esc_html_e('Cache Control', 'redirection-pro'); ?>
                </h2>
                <p>
                    <?php esc_html_e('Manage the cached data by the plugin, such as headers and open graph data.', 'redirection-pro'); ?>
                </p>

                <table class="form-table">

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Cache Duration', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <label for="redirection_pro_cache_duration">
                                <input type="number" list="cache_duration_suggestions" name="<?php echo esc_attr(self::OPTION_NAME); ?>[cache_duration]" min="<?php echo esc_attr(DAY_IN_SECONDS); ?>" max="<?php echo esc_attr(YEAR_IN_SECONDS); ?>" value="<?php echo esc_attr($cache_duration); ?>" />
                                <datalist id="cache_duration_suggestions" style="display: none;">
                                    <option value="<?php echo esc_attr(DAY_IN_SECONDS); ?>" label="<?php esc_attr_e('1 Day', 'redirection-pro'); ?>">
                                    <option value="<?php echo esc_attr(WEEK_IN_SECONDS); ?>" label="<?php esc_attr_e('1 Week', 'redirection-pro'); ?>">
                                    <option value="<?php echo esc_attr(MONTH_IN_SECONDS); ?>" label="<?php esc_attr_e('1 Month', 'redirection-pro'); ?>">
                                    <option value="<?php echo esc_attr(YEAR_IN_SECONDS); ?>" label="<?php esc_attr_e('1 Year', 'redirection-pro'); ?>">
                                </datalist>
                            </label>

                            <p class="description">
                                <?php esc_html_e('The cache duration in seconds.', 'redirection-pro'); ?>
                            </p>
                        </td>
                    </tr>


                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Cached Data', 'redirection-pro'); ?>
                        </th>
                        <td>
                            <p class="description">
                                <?php esc_html_e('Here you can clear the cached data.', 'redirection-pro'); ?>
                            </p>
                            <p>
                                <a class="button" role="button" referrerpolicy="same-origin" href="<?php echo esc_attr($clearCacheUrl); ?>">
                                    <?php esc_html_e('Clear Cache', 'redirection-pro'); ?>
                                </a>
                            </p>
                        </td>
                    </tr>

                </table>

                <div class="form-actions">

                    <p class="reset">
                        <a class="button" role="button" referrerpolicy="same-origin" href="<?php echo esc_attr($resetSettingsUrl); ?>">
                            <?php esc_html_e('Reset to Default', 'redirection-pro'); ?>
                        </a>
                    </p>

                    <?php submit_button(); ?>

                </div>

            </form>

        </div>

<?php

    }
}
