<?php

namespace RedirectionPro;

/**
 * Manages asynchronous HTTP response checking through a queue mechanism.
 * Enables the plugin to efficiently process HTTP responses in the background
 * and trigger callbacks when responses are received.
 *
 * @package RedirectionPro
 * @since 1.0.0
 * @final
 */
final class AsyncHttpAgent extends SingletonClass
{

    // Private variable to hold the instance of the class
    private static $_instance = null;

    private const PREFIX_QUEUE  = 'redirection-pro_queue_';
    private const EVENT_HOOK = 'redirection-pro_get_queued_urls';


    /**
     * Making the constructor private ensures that instances of the AsyncHttpAgent class can only 
     * be created through the create method, which provides additional control and validation.
     * 
     * @since 1.0.0
     */
    private function __construct()
    {
    }

    

    /**
     * The `create` method is a factory method used to instantiate the `AsyncHttpAgent` class. 
     * It follows the Singleton pattern, ensuring that only one instance of the class exists 
     * throughout the application's lifecycle.
     * 
     * @return AsyncHttpAgent The `AsyncHttpAgent` instance, either existing or newly created.
     *
     * @since 1.0.0
     */
    public static function create(...$args)
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();

            self::$_instance->_init();

            return self::$_instance;
        } else {
            wp_die('Creating an instance requires a secret handshake and a password only known to penguins.');
        }
    }



    /**
     * The `_init` method initializes the `AsyncHttpAgent` class by setting up various actions, 
     * filters, and hooks used throughout the plugin.
     *
     * @since 1.0.0
     * @access private
     */
    private function _init()
    {
        
        add_action(self::EVENT_HOOK, array($this, '_get_queued_urls'));

        // Add filters for http_response and cron_schedules and set up their respective callbacks.
        add_filter('http_response', array($this, '_response_handler'), 10, 3);
        add_filter('cron_schedules', array($this, '_cron_schedules'));

        // Register activation and deactivation hooks for background job management.
        register_activation_hook(REDIRECTION_PRO_FILE, array($this, '_start_job'));
        register_deactivation_hook(REDIRECTION_PRO_FILE, array($this, '_stop_job'));

        // Register an uninstall hook to handle cleanup during plugin uninstallation.
        register_uninstall_hook(REDIRECTION_PRO_FILE, array(__CLASS__, '_uninstall'));
    }



    /**
     * The `_get_queued_urls` method is responsible for processing a queue of pending HTTP requests.
     * 
     * This method efficiently processes a queue of URLs, handling both valid and invalid URLs and 
     * making HTTP GET requests to retrieve the content from those URLs.
     *
     * @since 1.0.0
     * @access private
     */
    public function _get_queued_urls()
    {
        // Retrieve the queue of pending items using the 'get_queue' method.
        $queue = $this->get_queue('pending' , '');

        // Define request arguments for the HTTP request.
        $request_args = array(
            'timeout'   => 15,  // Set the timeout for the HTTP request to 15 seconds.
            'sslverify' => apply_filters('https_local_ssl_verify', false), // Determine whether SSL verification is enabled.
            'referer'   => home_url(), // Set the referer URL for the HTTP request.
            'headers'   => array(
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ) // Define additional headers for the HTTP request.
        );

        // Iterate through each item in the queue.
        foreach ($queue as $item) {
            // Extract information from the queue item.
            $url        = $item['to'];      // URL to be retrieved.
            $queue_id   = $item['id'];      // ID of the queue item.
            $queue_info = $item;            // Complete information of the queue item.

            // Check if the URL is a valid URL.
            if (!filter_var($url, FILTER_VALIDATE_URL , FILTER_VALIDATE_IP )) {
                // If the URL is not valid, mark the queue item as an error and set a transient.
                $queue_info['status'] = 'error';
                set_transient($queue_id, $queue_info);

                // Skip processing this item and continue with the next.
                continue;
            }

            // Perform an HTTP GET request to the specified URL using wp_remote_get.
            wp_remote_get($url, $request_args);
        }
    }



    /**
     * The `_cron_schedules` method adds a custom cron schedule interval of every 90 seconds.
     *
     * @param array $schedules The existing list of cron schedules.
     * @since 1.0.0
     * @access private
     */
    public function _cron_schedules($schedules)
    {
        // Checks if a custom schedule named '90sec' is already defined.
        if (!isset($schedules['90sec'])) {
            // If not defined, adds a new schedule with an interval of 90 seconds and a display name.
            $schedules['90sec'] = array(
                'interval' => 90,
                'display' => __('Every 90 seconds', 'redirection-pro')
            );
        }
        // Returns the updated list of schedules.
        return $schedules;
    }



    /**
     * The `_start_job` method schedules a recurring event if it is not already scheduled.
     *
     * This method ensures that the 'redirection-pro_http-response' event is scheduled to run 
     * periodically, specifically every 90 seconds. This event is associated with the `_get_queued_urls` 
     * method, which retrieves and processes URLs from a queue.
     *
     * @since 1.0.0
     * @access private
     */
    public function _start_job()
    {
        // Checks if the event hook 'redirection-pro_http-response' is already scheduled.
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            // If not scheduled, schedules the event to run.
            wp_schedule_event(time(), '90sec', self::EVENT_HOOK); // Schedules the event to occur every 90 seconds.
        }
    }




    /**
     * The `_stop_job` method cancels a scheduled recurring event.
     * 
     * This method is designed to stop the scheduled event associated with processing URLs from 
     * a queue. When called, it ensures that the event will no longer run at its scheduled intervals.
     *
     * @since 1.0.0
     * @access private
     */
    public function _stop_job()
    {
        // Cancels the scheduled event by clearing it from the hook.
        wp_clear_scheduled_hook(self::EVENT_HOOK);
    }



    


    /**
     * The `_response_handler` method processes HTTP responses for URLs.
     *
     * This method is used to handle HTTP responses for URLs fetched during the processing of 
     * a queue. It triggers specific actions based on the response status and content.
     *
     * @param mixed $response     The original HTTP response.
     * @param array $parsed_args  Parsed arguments for the HTTP request.
     * @param string $url         The URL of the HTTP request.
     *
     * @since 1.0.0
     * @access private
     */
    public function _response_handler($response, $parsed_args, $url)
    {
        // Calculate a unique queue identifier using an MD5 hash of the URL.
        $hash   = md5($url);
        $queue  = self::PREFIX_QUEUE . $hash;

        // Check if a transient with the queue identifier exists. If not, return the original response.
        if (false === $queue_info = get_transient($queue)) {
            return $response;
        }

        // Check the validity of the HTTP response.
        if (!$response || is_wp_error($response) || empty($status = wp_remote_retrieve_response_code($response)) || empty($body = wp_remote_retrieve_body($response))) {
            // Trigger the 'redirection-pro_http-error' action.
            do_action('redirection-pro_http-error', $queue_info, $url);
        } else {
            // Trigger the 'redirection-pro_http-response' action, providing information about the processed URL, its status, and the response body.
            do_action('redirection-pro_http-response', $queue_info, $url, $status, $body);
        }

        // Return the original response.
        return $response;
    }




    /**
     * The `add_to_queue` method adds a URL to the processing queue for later retrieval and analysis.
     * 
     * This method is used to enqueue URLs for processing later, typically for retrieving Open Graph data from external links or for other purposes.
     *
     * @param string $url      The URL to be added to the processing queue.
     * @param string $type     The type of the URL (default is 'link').
     *
     * @since 1.0.0
     */
    public function add_to_queue($url, $type = 'link')
    {
        global $wp;

        if (!filter_var($url, FILTER_VALIDATE_URL , FILTER_VALIDATE_IP)) {
            return;
        }

        // Calculate a unique queue identifier using an MD5 hash of the URL.
        $hash  = md5($url);
        $url   = esc_url_raw($url); // Escape and sanitize the URL.
        $queue = self::PREFIX_QUEUE . $hash;

        

        // Check if a transient with the queue identifier already exists. If it exists, the URL is already in the queue, so return without adding it again.
        if (get_transient($queue) !== false) {
            return;
        }

        // If the transient does not exist, create a new transient with information about the URL, including its status, type (default is 'link'), and other metadata.
        set_transient($queue, [
            'id'      => $queue,
            'start'   => time(),
            'to'      => $url,
            'from'    => home_url($wp->request),
            'status'  => 'pending',
            'preview' => false,
            'type'    => $type
        ]);
    }



    /**
     * The `get_queue` method retrieves a list of queued items from the database based on specified status and search criteria.
     *
     * This method is used to retrieve queued items, which are typically URLs waiting for processing, with the option to filter them by status or search criteria.
     *
     * @param string $status  Optional. Filter items by status (e.g., 'pending', 'completed').
     * @param string $search  Optional. Filter items by a search term.
     *
     * @since 1.0.0
     *
     * @return array An array of queued items with specific fields such as ID, source URL, destination URL, start time, status, preview, and type.
     */
    public function get_queue($status = '', $search = '', $skip = 0 , $limit = 10)
    {
        global $wpdb;

        // Define the transient name used for the queue.
        $transient_name = '_transient_' . self::PREFIX_QUEUE;
        
        // Construct the base SQL query to select options from the WordPress database where the option name is like the queue transient name.
        $query = "SELECT * FROM $wpdb->options WHERE option_name LIKE '%$transient_name%'";

        // Sanitize and filter the search and status parameters.
        $search = sanitize_text_field($search);
        $status = sanitize_text_field($status);

        // If a search term is provided, add a condition to the query to filter results based on the search term.
        if (!empty($search)) {
            $query .= " AND option_value LIKE '%$search%'";
        }
        

        $query .= " LIMIT $skip,$limit";

        // Prepare and execute the SQL query to get the queue.
        $query = $wpdb->prepare($query);

        // Retrieve the queue from the database and transform the options into an array of queue items with specific fields.
        $transients = $wpdb->get_results($query, ARRAY_A);

        $result = array_map(function (&$item) {

            $value = maybe_unserialize($item['option_value']);

            return array(
                'id'      => str_replace('_transient_', '', $item['option_name']),
                'from'    => $value['from'],
                'to'      => $value['to'],
                'start'   => $value['start'],
                'status'  => $value['status'],
                'preview' => $value['preview'],
                'type'    => $value['type']
            );
        }, $transients);


        // Filter the result array to include only items with a specific status if the status parameter is provided.
        if (!empty($status)) {
            $result = array_filter($result, function ($item) use ($status) {
                return $item['status'] === $status;
            });
        }
       
        // Return the resulting array of queue items.
        return $result;
    }




    /**
     * The `get_item` method retrieves a specific item from the queue based on the provided URL.
     *
     * This method is useful for retrieving detailed information about a specific item in the queue, 
     * typically a URL that is waiting for processing.
     *
     * @param string $url The URL for which to retrieve the queue item.
     *
     * @since 1.0.0
     *
     * @return array|false An array containing information about the queue item (ID, source URL, destination URL, start time, status, preview, and type) or `false` if the URL is not found in the queue.
     */
    public function get_item($url)
    {

        $hash   = md5($url);
        $key    = self::PREFIX_QUEUE . $hash;

        if (false === $value  = get_transient($key)) {
            return false;
        }

        return array(
            'id'      => $key,
            'from'    => $value['from'],
            'to'      => $value['to'],
            'start'   => $value['start'],
            'status'  => $value['status'],
            'preview' => $value['preview'],
            'type'    => $value['type']
        );
    }



    /**
     * The `clear_caches` method clears cached data in WordPress transients.
     *
     * This method provides a way to clear specific cached data stored in WordPress transients. 
     * You can pass a search term to clear only transients that match the search term.
     *
     * @param string $search (Optional) A search term to filter transients by name before clearing. Leave empty to clear all matching transients.
     *
     * @since 1.0.0
     *
     * @return int The total number of transients that were successfully deleted.
     */
    public function clear_caches($search = '')
    {
        global $wpdb;

        $count = 0;
        $query = "SELECT * FROM $wpdb->options WHERE option_name LIKE '_transient_redirection-pro_%'";

        $search = sanitize_text_field($search);

        if (!empty($search)) {
            $query = "SELECT * FROM $wpdb->options WHERE option_name LIKE '%$search%'";
        }

        $query = $wpdb->prepare($query);

        $transients = $wpdb->get_results($query, ARRAY_A);

        foreach ($transients as $transient) {
            if (delete_transient(str_replace('_transient_', '', $transient['option_name']))) {
                $count++;
            }
        }

        return $count;
    }



    /**
     * The `_uninstall` method is a private method used for cleaning up data when the plugin is uninstalled.
     *
     * This method ensures that cached data created by the plugin is removed when the plugin is uninstalled, 
     * preventing any remnants of cached information from persisting in the WordPress database.
     *
     * @since 1.0.0
     *
     * @access private
     */
    public static function _uninstall(){
        self::$_instance->clear_caches();
    }
}
