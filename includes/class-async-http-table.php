<?php

namespace RedirectionPro;

use WP_List_Table;


/**
 * AsyncHttpTable Class
 *
 * @package RedirectionPro
 * @since 1.0.0
 * @final
 */
final class AsyncHttpTable extends WP_List_Table
{


    public $items_per_page = 10; // Number of items to display per page


    /**
     * Initialize the AsyncHttpTable instance.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        parent::__construct(array(
            'ajax'      => false   // Whether the table supports AJAX.
        ));
    }




    /**
     * Define the columns to be displayed in the table.
     *
     * @since 1.0.0
     *
     * @return array An associative array with column identifiers and their corresponding headers.
     */
    public function get_columns()
    {
        $columns = array(
            'cb'       => '<input type="checkbox" />', // Checkbox for bulk actions
            'from'     => esc_html__('From', 'redirection-pro'), 
            'to'       => esc_html__('To', 'redirection-pro'), 
            'start'    => esc_html__('Start', 'redirection-pro'),
            'status'   => esc_html__('Status', 'redirection-pro')
        );
        return $columns;
    }




    /**
     * Prepare the items for display in the table.
     *
     * @since 1.0.0
     */
    function prepare_items()
    {
        $hidden = array(); // Columns to hide
        $primary  = 'from'; // The primary column used for sorting
        $data = $this->items; // The data to be displayed

        $current_page = $this->get_pagenum(); // Current page number
        $columns = $this->get_columns(); // Define the columns for the table
        $sortable = $this->get_sortable_columns(); // Define sortable columns

        $this->_column_headers = array($columns, $hidden, $sortable, $primary);

        // Sort the data based on the chosen sorting column
        usort($data, array($this, '_usort_reorder'));

        // Pagination
        $total_items = count($data); // Total number of items
        $data = array_slice($data, (($current_page - 1) * $this->items_per_page), $this->items_per_page); // Items to display on the current page

        $this->set_pagination_args(array(
            'total_items' => $total_items, // Total number of items
            'per_page'    =>  $this->items_per_page, // Items to show on a page
            'total_pages' => ceil($total_items /  $this->items_per_page) // Use ceil to round up
        ));

        $this->items = $data; // Set the items for display
    }




    /**
     * Define the sortable columns for the table.
     *
     * @since 1.0.0
     */
    protected function get_sortable_columns()
    {
        $sortable_columns = array(
            'from'    => array('from', true),    
            'to'      => array('to', true),      
            'status'  => array('status', true),  
            'start'   => array('start', true)  
        );

        return $sortable_columns;
    }




    /**
     * Callback function for sorting the items.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $a The first item to compare.
     * @param array $b The second item to compare.
     *
     * @return int The comparison result for sorting.
     */
    function _usort_reorder($a, $b)
    {
        // Determine the sorting order (ascending or descending)
        $order = empty($_GET['order']) ? 'asc' : sanitize_text_field($_GET['order']);

        // Determine the column to sort by
        $orderby = empty($_GET['orderby']) ? 'status' : sanitize_text_field($_GET['orderby']);

        if ($orderby === 'status') {
            // Convert 'pending' status to 0 for proper sorting
            $astatus = $a[$orderby] === 'pending' ? 0 : $a[$orderby];
            $bstatus = $b[$orderby] === 'pending' ? 0 : $b[$orderby];
            $result = $astatus - $bstatus;
        } elseif ($orderby === 'start') {
            // Sort by the 'Start' timestamp
            $result = $a[$orderby] - $b[$orderby];
        } else {
            // Default sorting behavior for other columns
            $result = strcmp($a[$orderby], $b[$orderby]);
        }

        // Adjust the sorting direction based on user choice (asc or desc)
        return ($order === 'asc') ? $result : -$result;
    }



    /**
     * Retrieve the bulk actions available for the table.
     *
     * @since 1.0.0
     *
     * @return array An associative array of bulk actions and their labels.
     */
    function get_bulk_actions()
    {
        // Define the available bulk actions with their respective labels.
        $actions = array(
            'delete_all' => esc_html__('Delete', 'redirection-pro')
        );

        return $actions;
    }



    
   /**
     * Display the content for the 'From' column in the table.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current item in the queue.
     * @return string HTML content for the 'From' column.
     */
    function column_from($item)
    {
        // Extract relevant item data.
        $id = $item['id'];
        $from = $item['from'];
        $to = $item['to'];
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        // Check if the current page matches the 'redirection-pro' prefix.
        if (!str_starts_with($page, 'redirection-pro')) {
            return; // If not, don't display anything for this item.
        }

        // Define custom row actions for the item.
        $actions = apply_filters(
            'redirection-pro_queue_row',
            $id,
            array(
                'view_page' => sprintf(
                    '<a rel="external noreferrer noopener nofollow" target="_blank" href="%1$s">%2$s</a>',
                    esc_attr($from),
                    esc_html__('View Page', 'redirection-pro')
                ),
                'view_target' => sprintf(
                    '<a rel="external noreferrer noopener nofollow" target="_blank" href="%1$s">%2$s</a>',
                    esc_attr($to),
                    esc_html__('View Target', 'redirection-pro')
                )
            )
        );

        // Return the formatted HTML content for the 'From' column along with row actions.
        return  ( sprintf('%1$s %2$s', $from, $this->row_actions($actions)) );
    }



    /**
     * Render a single row in the table.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current item in the queue.
     */
    function single_row($item)
    {
        // Extract and sanitize the status from the item data.
        $status = absint($item['status']);

        // Determine the row style based on the status code.
        $style = ($status >= 400 && $status < 600) ? 'background-color: #ffeaea;' : '';

        // Define the HTML template for a table row.
        $template = '<tr style="%1$s">%2$s</tr>';

        $allowedHtml =  wp_kses_allowed_html( 'post' );

        $allowedHtml = array_merge($allowedHtml , array('input' => array(
            'id'        => array(),
            'class'     => array(),
            'type'      => array(),
            'name'      => array(),
            'value'     => array(),
            'checked'   => array()
        )));

        // Start output buffering to capture the row columns.
        ob_start();

        // Generate the columns for the row.
        $this->single_row_columns($item);

        // Get the row content and clear the output buffer.
        $row = ob_get_clean();

        // Print the row using the defined template and style.
        echo wp_kses( sprintf( $template , esc_attr($style), $row)  , $allowedHtml);
    }



    /**
     * Handle the display of default columns in the table.
     *
     * @since 1.0.0
     *
     * @param array  $item         The data for the current item in the queue.
     * @param string $column_name  The name of the column being displayed.
     *
     * @return string The HTML content to be displayed in the specified column.
     */
    function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'status':
                // Check if the status is 'pending' and display an appropriate message.
                if ($item[$column_name] === 'pending') {
                    return esc_html__('Pending...', 'redirection-pro');
                }
                return $item[$column_name];
            case 'start':
                // Format the 'start' timestamp as a date and time using site settings.
                $date_format = get_option('date_format');
                $time_format = get_option('time_format');
                return date_i18n($date_format . ' ' . $time_format, absint($item[$column_name]));
            default:
                // For other columns, return the item's value for the specified column.
                return $item[$column_name];
        }
    }




    /**
     * Render the checkbox column for bulk actions.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current item in the queue.
     *
     * @return string The HTML content containing a checkbox input element.
     */
    function column_cb($item)
    {
        $template = '<input type="checkbox" name="bulk-action[]" value="%s" />';
        
        // Generate a checkbox input element with the item's ID as the value.
        return sprintf($template , esc_attr($item['id']));
    }

}
