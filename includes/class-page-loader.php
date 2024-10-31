<?php

namespace RedirectionPro;

/**
 * The `PageLoader` class is responsible for parsing WordPress pages to identify external links and 
 * retrieve Open Graph (OG) data from these links. It employs the Singleton design pattern to ensure 
 * that only one instance of this class exists at a time.
 *
 * This class plays a crucial role in scanning page content for external URLs and subsequently fetching 
 * relevant Open Graph data from those links. It helps in gathering metadata such as titles, descriptions, 
 * images, and other OG tags from external sources.
 *
 * @package RedirectionPro
 * @since 1.0.0
 * @final
 */
final class PageLoader extends SingletonClass
{

    // Private  variable to hold the instance of the class
    private static $_instance = null;
    private static $_cache_duration = YEAR_IN_SECONDS;


    /**
     * @since 1.0.0
     */
    private function __construct( $cacheDuration )
    {
        self::$_cache_duration  = $cacheDuration;
    }


    /**
     * Create a new instance of the PageLoader class.
     *
     * This static method is used to create a new instance of the PageLoader class.
     * It ensures that only one instance of PageLoader can exist throughout the application.
     *
     * @since 1.0.0
     *
     * @param integer ...$args The cache duration.
     * @return PageLoader|null Returns the newly created instance of PageLoader.
     */
    public static function create(...$args)
    {
        // Check if an instance already exists.
        if (is_null(self::$_instance) && count($args) > 0 && (gettype($args[0]) === 'integer')) {
            // Create a new instance of PageLoader.
            self::$_instance = new self($args[0]);

            // Initialize the newly created instance.
            self::$_instance->_init();

            // Return the newly created instance.
            return self::$_instance;
        } else {
            // Display an error message and terminate the script.
            wp_die('Creating an instance requires a secret handshake and a password only known to penguins.');
        }
    }



    /**
     * Initialize the PageLoader instance.
     *
     * This private method is called when a new instance of PageLoader is created.
     * It sets up necessary actions and hooks required for the PageLoader's functionality.
     *
     * @since 1.0.0
     */
    private function _init()
    {
        // Add an action hook to handle HTTP responses using the '_http_response' method.
        add_action('redirection-pro_http-response', array($this, '_http_response'), 10, 4);
    }




    /**
     * Handle HTTP responses and cache the results.
     *
     * This private method is responsible for processing HTTP responses and caching the relevant information.
     * It receives information about the queue item, the URL, HTTP status, and the response body.
     *
     * @param array  $queue_info Information about the queue item.
     * @param string $url        The URL for which the response was received.
     * @param int    $status     The HTTP status code of the response.
     * @param string $body       The body of the HTTP response.
     *
     * @since 1.0.0
     * @access private
     */
    function _http_response($queue_info, $url, $status, $body)
    {

        // Update the queue item's status with the received HTTP status.
        $queue_info['status'] = $status;

        // If the queue item represents a link, parse the page body to extract relevant information.
        if ($queue_info['type'] === 'link') {
            $queue_info['preview'] = $this->_parse_page($body);
        }

        // Cache the updated queue item using a transient.
        set_transient($queue_info['id'], $queue_info, self::$_cache_duration );
    }





    /**
     * Parse an HTML page to extract Open Graph (OG) meta tags and other relevant information.
     *
     * This private method uses regular expressions to locate and capture OG meta tags in the HTML content.
     * The extracted information is then filtered to include only specific OG properties defined as allowed.
     * Additionally, it retrieves the page title and description from the HTML if available.
     *
     * @param string $html The HTML content of the page to parse.
     *
     * @return array An associative array containing extracted information, including OG properties, title, and description.
     *
     * @since 1.0.0
     * @access private
     */
    private function _parse_page($html)
    {
        // Regular expression pattern to match `og:` meta tags
        $pattern = '/<meta[^>]*(?:content=["\']([^"\']*?)["\'][^>]*property=["\'](og:[^"\'>]+)["\'][^>]*|property=["\'](og:[^"\'>]*)["\'][^>]*content=["\']([^"\']*?)["\'][^>]*)>/i';

        $matches = array();
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        $page_info = array();
        $allowed_info = array('image', 'site-name', 'title', 'description', 'locale');

        // Allow filtering of allowed OG properties.
        $allowed_info = apply_filters('redirection-pro_og-meta-array', $allowed_info);

        foreach ($matches as $match) {
            $prop_first = empty($match[1]);
            $property = str_replace(array('og:', '_'), array('', '-'), ($prop_first ? $match[3] : $match[2]));
            $content = $prop_first ? $match[4] : $match[1];

            if (!in_array($property, $allowed_info)) {
                continue;
            }

            $page_info[$property] = esc_attr($content);
        }

        // Retrieve the page title and description from the HTML.
        $page_title = $this->get_page_title($html);
        $page_description = $this->get_page_description($html);

        if (!empty($page_title)) {
            $page_info['title'] = $page_title;
        }

        if (!empty($page_description)) {
            $page_info['description'] = $page_description;
        }

        // Trim the title and description to a specified length.
        if (isset($page_info['title'])) {
            $page_info['title'] = wp_trim_words($page_info['title'], 10, '…');
        }

        if (isset($page_info['description'])) {
            $page_info['description'] = wp_trim_words($page_info['description'], 20, '…');
        }

        return $page_info;
    }




    /**
     * Extract the page title from the HTML content.
     *
     * This private method uses a regular expression to locate and extract the page title from the provided HTML content.
     * It searches for the <title> tag in the HTML and captures the text within it as the page title.
     * If a title tag is found, it returns the extracted title; otherwise, it returns false.
     *
     * @param string $html The HTML content from which to extract the page title.
     *
     * @return string|false The extracted page title as a string, or false if no title tag is found.
     *
     * @since 1.0.0
     * @access private
     */
    private function get_page_title($html)
    {
        $matches = array();
        $pattern = '/<title\b[^>]*>([\s\S]*?)<\/title>/i';

        // Use a regular expression to match the title tag and capture its content.
        preg_match($pattern, $html, $matches);

        if (isset($matches[1])) {
            // Return the captured title content.
            return $matches[1];
        }

        // Return false if no title tag is found.
        return false;
    }



    /**
     * Extract the page description from the HTML content.
     *
     * This private method uses a regular expression to locate and extract the page description from the provided HTML content.
     * It searches for the <meta> tag with the attribute `name="description"` and captures the content attribute value as the page description.
     * If such a <meta> tag is found, it returns the extracted description; otherwise, it returns false.
     *
     * @param string $html The HTML content from which to extract the page description.
     *
     * @return string|false The extracted page description as a string, or false if no matching <meta> tag is found.
     *
     * @since 1.0.0
     * @access private
     */
    private function get_page_description($html)
    {
        $matches = array();
        $pattern = '/<meta[^>]*(?:content=["\'][^"\']*?["\'][^>]*name=["\']description["\'][^>]*|name=["\']description["\'][^>]*content=["\'][^"\']*?["\'][^>]*)>/i';

        // Use a regular expression to match the meta tag with name="description" and capture its content attribute value.
        preg_match($pattern, $html, $matches);

        if (isset($matches[1])) {
            // Return the captured description content.
            return $matches[1];
        }

        // Return false if no matching meta tag is found.
        return false;
    }

}
