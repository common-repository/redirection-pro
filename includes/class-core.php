<?php

namespace RedirectionPro;

/**
 * Core Class
 *
 * @package RedirectionPro
 * @since 1.0.0
 * @final
 */
final class Core extends SingletonClass
{


    const PREFIX_LINK_HASH = 'redirection-pro_link_hash_';
    const QUERY_ARG        = '_rpexurl';


    // Private static variable to hold the instance of the class
    private static $_instance = null;
    private static $_httpHandler = null;
    private static $_options = null;


    /**
     * @since 1.0.0
     */
    private function __construct()
    {

        // Initialize the plugin classes

        // Create an instance of AsyncHttpAgent to handle asynchronous HTTP requests.
        self::$_httpHandler = AsyncHttpAgent::create();

        // Create an instance of the Options class for managing plugin options.
        // It takes the AsyncHttpAgent instance as a parameter.
        self::$_options = Options::create(self::$_httpHandler);

        // Get the plugin options, including the cache duration.
        $optionValues = self::$_options->get_options();

        // Create an instance of PageLoader with the cache duration from the options.
        PageLoader::create($optionValues['cache_duration']);

    }



    /**
     * @since 1.0.0
     */
    public static function create(...$args)
    {
        // Check if an instance of Core does not already exist.
        if (is_null(self::$_instance)) {
            // Create a new instance of the Core class.
            self::$_instance = new self();

            // Initialize the newly created instance.
            self::$_instance->_init();

            // Return the created instance.
            return self::$_instance;
        } else {
            // If an instance already exists, terminate the application with a message.
            wp_die('Creating an instance requires a secret handshake and a password only known to penguins.');
        }
    }



    /**
     * @since 1.0.0
     */
    private function _retrieve_url_info($url)
    {
        // Check if information about the URL is available in the queue.
        if (false === ($info = self::$_httpHandler->get_item($url))) {
            // If not, add the URL to the queue for asynchronous processing.
            self::$_httpHandler->add_to_queue($url);
            return false; // Return false to indicate that the information is not available yet.
        }

        // Return the information if it's already in the queue.
        return $info;
    }




    /**
     * @since 1.0.0
     */
    private function _is_url_broken($url)
    {
        // Check if information about the URL is available.
        if ($info = $this->_retrieve_url_info($url)) {
            $status = absint($info['status']);

            // Return true if the HTTP status code indicates a broken link (400-599 range).
            return $status >= 400 && $status < 600;
        }

        // Return false if information about the URL is not available.
        return false;
    }




    /**
     * @since 1.0.0
     */
    private function _get_preview($url)
    {
        if($info = $this->_retrieve_url_info($url)){
            return $info['preview'];
        }
        return false;
    }




    /**
     * Initialize the plugin.
     *
     * @since 1.0.0
     */
    private function _init()
    {

        add_shortcode('redirection_pro_timer', array($this  , '_render_timer_section'));

        add_action('template_redirect', array($this, '_check_redirect_param'));
        add_action('wp_enqueue_scripts', array($this, '_enqueue_scripts'));
        add_action('wp_footer', array($this, '_print_templates'));

        add_filter('the_content', array($this, '_filter_content'));
        add_filter('comment_text', array($this, '_filter_content'));
        add_filter('wp_nav_menu_items', array($this, '_filter_content'));
        add_filter('render_block_core/navigation-link', array($this, '_filter_content'));

        /*
            TODO:

            add_filter('block_core_navigation_render_inner_blocks' , function($blocks){   
                foreach($blocks as $block){
                    if($block->name == 'core/navigation-link'){
                        $block->parsed_block['attrs']['url'] = self::_get_redirection_url( $block->parsed_block['attrs']['url'] );
                    }
                }
                return $blocks;
            });
        */


        

    }


    /**
     * @since 1.0.0
     * @access private
     */
    public function _enqueue_scripts()
    {

        extract(self::$_options->get_options());

        $redirect_param = isset($_GET[self::QUERY_ARG]) ? sanitize_text_field($_GET[self::QUERY_ARG]) : '';

        if ($distort_link) {
            $redirect_param = get_transient(self::PREFIX_LINK_HASH . $redirect_param);
        }

        $data = array(
            'timer' => $timer,
            'href' => $redirect_param,
            'delay' => $preview_delay,
            'animation' => $preview_animation,
            'theme' => $preview_theme
        );

        // Enqueue Tippy.js only if 'enable_preview' option is enabled
        if ($enable_preview) {

            if ($enqueue_popper) {
                wp_enqueue_script('popper', REDIRECTION_PRO_URL . '/assets/libs/@popperjs/dist/umd/popper.min.js');
            }
            if ($enqueue_tippy) {
                wp_enqueue_script('tippy', REDIRECTION_PRO_URL . '/assets/libs/tippy.js/dist/tippy-bundle.umd.min.js', $enqueue_popper ? array('popper') : array());
            }

            if ($preview_animation !== 'fade') {
                wp_enqueue_style('tippy-animation', REDIRECTION_PRO_URL . '/assets/libs/tippy.js/animations/' . $preview_animation . '.css');
            }

            if (!in_array($preview_theme, array('rp-light', 'rp-dark'))) {
                wp_enqueue_style('tippy-theme', REDIRECTION_PRO_URL . '/assets/libs/tippy.js/themes/' . $preview_theme . '.css');
            }

            wp_enqueue_style('redirection-pro', REDIRECTION_PRO_URL . '/assets/css/styles.css');
        }

        // Enqueue plugin scripts
        wp_register_script('redirection-pro', REDIRECTION_PRO_URL . '/assets/js/scripts.js',  array(), false, true);
        wp_localize_script('redirection-pro', 'REDIRECTION_PRO_DATA', $data);
        wp_enqueue_script('redirection-pro');
    }


    /**
     * @since 1.0.0
     */
    private static function _get_redirection_url($url)
    {

        extract(self::$_options->get_options());

        $url = strtolower($url);

        // Get the site URL for comparison
        $site_url = home_url();

        if (!empty($redirection_page)) {
            $redirection_page = get_permalink($redirection_page);
        }


        // Check if the link points to an external URL
        if (strpos($url, $site_url) === false && filter_var($url, FILTER_VALIDATE_URL)) {

            $host = parse_url($url, PHP_URL_HOST);
            $trusted = array_map('strtolower', array_map('trim', explode(PHP_EOL, $trusted_domains)));
            $trusted = apply_filters('redirection-pro_trusted-domains-array', $trusted);


            if ($enable_replace && !in_array($host, $trusted)) {

                $maybe_hashed = $distort_link ? md5($url) : $url;

                if ($distort_link) {
                    set_transient(self::PREFIX_LINK_HASH . md5($url), $url, $cache_duration);
                }

                // Append the redirect parameter to the link URL
                if (filter_var($redirection_page, FILTER_VALIDATE_URL) === false) {
                    return add_query_arg(self::QUERY_ARG, urlencode($maybe_hashed), $site_url);
                }

                return add_query_arg(self::QUERY_ARG, urlencode($maybe_hashed), $redirection_page);
            }
        }

        return $url;
    }


    /**
     * Filter the content and replace external links with internal links containing the redirect parameter.
     *
     * @since 1.0.0
     * 
     * @access private
     *
     * @param string $content The post content.
     * @return string Filtered content with modified links.
     */
    public function _filter_content($content)
    {

        extract(self::$_options->get_options());


        // Create a regular expression pattern to match links
        $pattern = '/<a([\s\S].*?)href=["\']((?!.*(?:mailto|tel):)[^"\']*)["\']([\s\S]*?)>/i';


        if (!empty($redirection_page)) {
            $redirection_page = get_permalink($redirection_page);
        }


        // Callback function to modify the matched links
        $callback = function ($matches) use ($enable_replace, $cache_duration, $enable_preview, $get_og_info, $distort_link) {

            $link_url = strtolower($matches[2]);

            $redirect_url = self::_get_redirection_url($link_url);

            $link_attributes = $matches[1] . ' ' . $matches[3];


            // Check if the link points to an external URL
            if ($redirect_url !== $link_url) {

                // Check if 'rel' attribute exists in link_attributes
                if (strpos($link_attributes, 'rel=') === false) {
                    // If 'rel' attribute does not exist, add it
                    $link_attributes .= ' rel=""';
                }

                // Keep the referrer by removing the 'noreferrer'
                $link_attributes = str_replace('noreferrer', '', $link_attributes);


                // Parameters to add
                $params_to_add = array('external', 'noopener', 'nofollow');
                foreach ($params_to_add as $param) {
                    // Check if the parameter exists in the 'rel' attribute
                    if (!preg_match('/\b' . preg_quote($param, '/') . '\b/', $link_attributes)) {
                        // If the parameter does not exist, add it to the 'rel' attribute
                        $link_attributes = preg_replace('/(rel="[^"]*)(")/', '$1 ' . $param . '$2', $link_attributes);
                    }
                }

                // Check if the link is distorted and add data-rp-distorted attribute
                if ($enable_replace && $distort_link) {
                    $link_attributes .= ' data-rp-distorted="true"';
                    // $link_attributes .= ' data-rp-original="' . esc_attr($link_url) . '"';
                }

                // Check if the link is broken and add data-rp-broken attribute
                if ($this->_is_url_broken($link_url, 3, $cache_duration)) {
                    $link_attributes .= ' data-rp-broken="true"';
                }

                $link_attributes .= ' data-rp-url="' . esc_attr($link_url) . '"';

                $link_attributes .= ' referrerpolicy="same-origin"';

                if ($get_og_info && ($preview = $this->_get_preview($link_url)) !== false) {

                    // Add Open Graph attributes to the link_attributes
                    foreach ($preview as $key => $value) {
                        if (!empty($preview[$key])) {
                            $link_attributes .= ' data-og-' . $key . '="' . esc_attr($value) . '"';
                        }
                    }

                    if ($enable_preview) {
                        $link_attributes .= ' data-rp-preview="true"';
                    }
                } elseif ($enable_preview) {

                    $link_attributes .= ' data-rp-preview="' . esc_attr($link_url) . '"';
                }

                return '<a' . $link_attributes . 'href="' . esc_attr($redirect_url) . '">';
            }

            // Return the original link unchanged
            return $matches[0];
        };

        // Perform the replacement using the callback function
        $filtered_content = preg_replace_callback($pattern, $callback, $content);

        return $filtered_content;
    }



    /**
     * Replace an external link with an internal link using a redirect parameter.
     *
     * @since 1.0.0
     *
     * @param array $matches An array of matches from the preg_replace_callback.
     * @return string The replaced link.
     */
    public static function replace_external_link($matches)
    {
        $external_url = $matches[1];
        $internal_url = site_url('?' . self::QUERY_ARG . '=' . urlencode($external_url));

        return str_replace($external_url, $internal_url, $matches[0]);
    }




    /**
     * Check for the redirect parameter and perform the redirection if present.
     *
     * @since 1.0.0
     * @access private
     */
    public function _check_redirect_param()
    {

        if (is_customize_preview() || is_admin() || is_preview() || !$this->_is_valid_referer()) {
            // Return early if in customize preview or admin area
            return;
        }

        extract(self::$_options->get_options());

        $redirect_param = isset($_GET[self::QUERY_ARG]) ? sanitize_text_field( $_GET[self::QUERY_ARG] ) : '';


        if (empty($redirect_param)) {
            return;
        }

        if ($distort_link) {
            if ($hashed_link = get_transient(self::PREFIX_LINK_HASH . $redirect_param)) {
                $redirect_param = $hashed_link;
            }
        }


        if (filter_var($redirect_param, FILTER_VALIDATE_URL) === false) {
            return;
        }


        if (empty($redirection_page) || $ssr_redirection) {
            wp_redirect($redirect_param, 301); exit;
        }


        if (is_page() && get_queried_object_id() === intval($redirection_page)) {
            return;
        }


        $redirect_param = isset($_GET[self::QUERY_ARG]) ? sanitize_text_field($_GET[self::QUERY_ARG]) : '';

        $redirection_url = add_query_arg(self::QUERY_ARG, urlencode($redirect_param), get_permalink($redirection_page));

        wp_redirect($redirection_url); exit;

    }


    /**
     * Render the timer section with countdown and redirection.
     *
     * @since 1.0.0
     * 
     * @access private
     *
     * @param array $atts Shortcode attributes.
     * @return string The rendered timer section.
     */
    public function _render_timer_section($atts)
    {

        extract(self::$_options->get_options());

        $redirect_param = isset($_GET[self::QUERY_ARG]) ? sanitize_text_field( $_GET[self::QUERY_ARG] ) : '';

        if ($distort_link) {
            $redirect_param = get_transient(self::PREFIX_LINK_HASH . $redirect_param);
        }

        ob_start();

        echo '<div id="redirection-pro-timer-section"><h3>';

        if (is_customize_preview() || is_admin() || is_preview() || empty($redirect_param) || !$this->_is_valid_referer()) {

            esc_html_e('Oops! Something went wrong!', 'redirection-pro');

        } else {

            $message = sprintf(
                esc_html__('You will be redirected to %1$s in %2$s seconds.', 'redirection-pro'),
                sprintf('<span id="redirection-pro-exeternal-url">%1$s</span>', esc_html($redirect_param)),
                sprintf('<span id="redirection-pro-timer-countdown">%1$s</span>',  esc_html($timer))
            );

            $message = apply_filters('redirection-pro_timer-message', $message);

            echo wp_kses_post($message);
        }

        echo  '</h3></div>';

        return ob_get_clean();
    }



    /** 
     * 
     * Checks if the referer is valid.
     * 
     * The function uses the **wp_get_referer()** function to get the referer and then 
     * compares it with the **home_url()** function, which returns the URL of the site’s 
     * homepage. If the referer is not empty and contains the home URL, the function returns 
     * true. Otherwise, it returns false. 
     * 
     * *This function can be used to verify that a request is coming from the same site 
     * and not from an external source, to prevent abusing the redirection page by passing 
     * arbitrary destination links in the query string.* 
     * 
     * @since 1.0.0
     * 
     * @return bool 
     */ 
    private function _is_valid_referer()
    {
        $referer = wp_get_referer();
        return $referer && (strpos($referer, home_url()) !== false);
    }


    /**
     * @since 1.0.0
     * @access private
     */
    public static function _print_templates()
    {

        $template = file_get_contents(rtrim(REDIRECTION_PRO_DIR, '/') . '/assets/temp/og-preview.temp');

        $allowedHtml =  wp_kses_allowed_html( 'post' );

        $allowedHtml = array_merge($allowedHtml , array(
            'template'  => array(
                'id'    => array()
            )
        ));

        echo wp_kses(apply_filters('redirection-pro_og-preview-template', $template ) , $allowedHtml );
    }

}
