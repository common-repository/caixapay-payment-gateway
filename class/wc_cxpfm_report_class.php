<?php



class wc_cxpfm_report
{

    var $admin_page_slug = 'wc_cxpfm_report';

    function __construct()
    {
        // add admin report page
        add_action( 'admin_menu', array($this, 'add_report_menu' ), 20);// priority 20 to be triggered after add_settings_menu

        add_filter('set-screen-option', array( $this, 'wc_cxpfm_report_set_option' ), 10, 3);

    }


    public function add_report_menu(){

        global $wc_cxpfm_settings;

        $hook = add_submenu_page( $wc_cxpfm_settings->admin_page_slug, 'Report', 'Report', 'manage_options', $this->admin_page_slug, array( $this, 'render_report_page' ) );

        add_action( "load-$hook", array( $this, 'add_options' ) );

    }


    function add_options() {

        $option = 'per_page';
        $args = array(
             'label' => 'Orders per page',
             'default' => 20,
             'option' => 'orders_per_page'
             );
        add_screen_option( $option, $args );

        global $wc_cxpfm_report_List_Table;
        $wc_cxpfm_report_List_Table = new wc_cxpfm_report_List_Table();

    }


    function wc_cxpfm_report_set_option($status, $option, $value) {
        return $value;
    }


    public function render_report_page()
    {
        // $wc_cxpfm_report_List_Table = new wc_cxpfm_report_List_Table();
        global $wc_cxpfm_report_List_Table;
        $wc_cxpfm_report_List_Table->prepare_items();
        ?>

            <div class="wrap">
                <div id="icon-users" class="icon32"></div>
                <h1>cxp payments report</h1>
                <form method="post">
                  <input type="hidden" name="page" value="<?php echo $this->admin_page_slug; ?>" />
                  <?php $wc_cxpfm_report_List_Table->search_box('Search order', 'search_id'); ?>
                </form>
                <?php $wc_cxpfm_report_List_Table->display(); ?>
            </div>
        <?php
    }


}

/**
 * Create a new table class that will extend the WP_List_Table
 */

class wc_cxpfm_report_List_Table extends WP_List_Table
{
    /**
     * Prepare the items for the table to process
     *
     * @return Void
     */
    public function prepare_items()
    {

        // $columns = $this->get_columns();
        // $hidden = $this->get_hidden_columns();
        // $sortable = $this->get_sortable_columns();

        $data = $this->table_data();
        usort( $data, array( &$this, 'sort_data' ) );

        // $perPage = 3;
        $perPage = $this->get_items_per_page('orders_per_page', 10);
        $currentPage = $this->get_pagenum();

        $totalItems = count($data);
        $this->set_pagination_args( array(
            'total_items' => $totalItems,
            'per_page'    => $perPage
        ) );

        $data = array_slice($data,(($currentPage-1)*$perPage),$perPage);

        // $this->_column_headers = array($columns, $hidden, $sortable);
        $this->_column_headers = $this->get_column_info();// to take screen options into account
        $this->items = $data;

    }


    /**
     * Override the parent columns method. Defines the columns to use in your listing table
     *
     * @return Array
     */
    public function get_columns()
    {

        $columns = array(
            'post_id'                  => 'Order',
            'post_date'                => 'Date',
            'post_status'              => 'Order status',
            '_order_total'             => 'Total',
            '_wc_cxpfm_amount_BB_asked' => 'Total in cxp',
            '_wc_cxpfm_received_amount' => 'Sent by customer',
            '_wc_cxpfm_fee' => 'Process Fees',
            '_wc_cxpfm_amount_sent' => 'Net received',
            '_wc_cxpfm_result' => 'Payment status',
            '_wc_cxpfm_cashback_result' => 'Cashback',

        );
        return $columns;
    }


    /**
     * Define which columns are hidden
     *
     * @return Array
     */
    public function get_hidden_columns()
    {
        return array();
    }


    /**
     * Define the sortable columns
     *
     * @return Array
     */
    public function get_sortable_columns()
    {
        return array(
            'post_id' => array('post_id', false),
            'post_date' => array('post_date', false),
            'post_status' => array('post_status', false),
            '_order_total' => array('_order_total', false),
            '_wc_cxpfm_amount_BB_asked' => array('_wc_cxpfm_amount_BB_asked', false),
            '_wc_cxpfm_received_amount' => array('_wc_cxpfm_received_amount', false),
            '_wc_cxpfm_fee' => array('_wc_cxpfm_fee', false),
            '_wc_cxpfm_amount_sent' => array('_wc_cxpfm_amount_sent', false),
            '_wc_cxpfm_result' => array('_wc_cxpfm_result', false),
            '_wc_cxpfm_cashback_result' => array('_wc_cxpfm_cashback_result', false),
        );
    }


    /**
     * Define what data to show on each column of the table
     *
     * @param  Array $item        Data
     * @param  String $column_name - Current column name
     *
     * @return Mixed
     */
    public function column_default( $item, $column_name )
    {

        global $wc_cxpfm_tools;

        switch( $column_name ) {

            case 'post_id':
            return '<a href="' . get_edit_post_link( $item->$column_name ) . '" title="see order details">#' . $item->$column_name . '</a>';

            case 'post_date':
                return date_i18n( $item->$column_name );

            case '_order_total':
                return number_format_i18n( $item->$column_name, 2 ) . ' ' . $item->_order_currency;

            case 'post_status':
                return str_replace( 'wc-', '', $item->$column_name );

            case '_wc_cxpfm_amount_BB_asked':
                return $wc_cxpfm_tools->byte_format( $item->$column_name );


            case '_wc_cxpfm_received_amount':

                if( $item->$column_name == 0 ){
                    return 'not yet...';
                }else{
                    return "<a href='https://explorer.caixapay.com/#" . $item->_wc_cxpfm_receive_unit . "' target='_blank' title='see unit on Caixapay explorer'>" . $wc_cxpfm_tools->byte_format( $item->$column_name ) . "</a>";
                }


            case '_wc_cxpfm_fee':

                if( $item->$column_name == 0 ){
                    return '-';
                }else{
                    return $wc_cxpfm_tools->byte_format( $item->$column_name );
                }


            case '_wc_cxpfm_amount_sent':

                if( $item->$column_name == 0 ){
                    return '-';
                }else{
                    return "<a href='https://explorer.caixapay.com/#" . $item->_wc_cxpfm_unit . "' target='_blank' title='see unit on Caixapay explorer'>" . $wc_cxpfm_tools->byte_format( $item->$column_name ) . "</a>";
                }


            case '_wc_cxpfm_result':

                if( $item->$column_name === null ){
                    return 'pending';
                }else{
                    return $item->$column_name;
                }



            case '_wc_cxpfm_cashback_result':

                if( $item->$column_name == 'ok' ){

                    return "<a href='https://explorer.caixapay.com/#" . $item->_wc_cxpfm_cashback_unit . "' target='_blank' title='see unit on Caixapay explorer'>" . $wc_cxpfm_tools->byte_format( $item->_wc_cxpfm_cashback_amount ) . "</a>";

                }else if( $item->$column_name == 'error' ){

                    return $item->$column_name. '<br><i>(' . $item->_wc_cxpfm_cashback_error . ')</i>';

                }else{
                    return '-';
                }


            default:
                return $item->$column_name ;
        }

    }



    /**
     * Get the table data
     *
     * @return Array
     */
    private function table_data()
    {

        include_once( WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php');

        $reports = new WC_Admin_Report();


         // search order filter



        if( isset( $_POST['s'] ) and is_numeric( $_POST['s'] ) ){
            $value = sanitize_text_field($_POST['s']);
            $where_array = array(
                array(
                    'key'   => 'posts.ID',
                    'value' => $value,
                    'operator'   => '='
                )
            );

        }else{
            $where_array = array();
        }


        $args = array(
            'data' => array(
                'ID' => array(
                    'type'     => 'post_data',
                    'function' => '',
                    'name'     => 'post_id',
                ),
                'post_date' => array(
                    'type'     => 'post_data',
                    'function' => '',
                    'name'     => 'post_date',
                ),
                'post_status' => array(
                    'type'     => 'post_data',
                    'function' => '',
                    'name'     => 'post_status',
                ),
                '_order_total' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_order_total'
                ),
                '_order_currency' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_order_currency'
                ),
                '_wc_cxpfm_result' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_result'
                ),
                '_wc_cxpfm_receive_unit' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_receive_unit'
                ),
                '_wc_cxpfm_received_amount' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_received_amount'
                ),
                '_wc_cxpfm_fee' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_fee'
                ),
                '_wc_cxpfm_amount_BB_asked' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_amount_BB_asked'
                ),
                '_wc_cxpfm_amount_sent' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_amount_sent'
                ),
                '_wc_cxpfm_unit' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_unit'
                ),
                '_wc_cxpfm_cashback_amount' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_cashback_amount'
                ),
                '_wc_cxpfm_cashback_error' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_cashback_error'
                ),
                '_wc_cxpfm_cashback_result' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_cashback_result'
                ),
                '_wc_cxpfm_cashback_unit' => array(
                    'type'     => 'meta',
                    'function' => '',
                    'join_type' => 'LEFT',
                    'name'     => '_wc_cxpfm_cashback_unit'
                ),
            ),
            'query_type'   => 'get_results',
            // 'order_status' => array( 'completed', 'processing', 'on-hold' ),// default
            // 'order_status' => array_keys( wc_get_order_statuses() ),// not working :-(
            'order_status' => array( 'pending', 'completed', 'processing', 'on-hold', 'cancelled', 'failed' ),// to be improved with WC native functions
            'where_meta' => array(
                array(
                    'meta_key'   => '_payment_method',
                    'meta_value' => 'cxpfm',
                    'operator'   => '='
                )
            ),

            'where' => $where_array,


        );


        // log report query
        add_filter( 'woocommerce_reports_get_order_report_query', array( $this, 'log_get_order_report_query' ) );

        $data = $reports->get_order_report_data($args);

        // log
        global $wc_cxpfm_tools;
        $wc_cxpfm_tools->log( 'debug', 'count orders found : ' . count( $data ) );
        $wc_cxpfm_tools->log( 'debug', 'orders data : ' . wc_print_r( $data, true ) );

        return $data;

    }



    /**
     * Allows you to sort the data by the variables set in the $_GET
     *
     * @return Mixed
     */
    private function sort_data( $a, $b )
    {
        // Set defaults
        $orderby = 'title';
        $order = 'asc';
        // If orderby is set, use this as the sort column


        if(!empty($_GET['orderby']))
        {
            $orderby = sanitize_text_field($_GET['orderby']);
        }
        // If order is set use this as the order
        if(!empty($_GET['order']))
        {
            $order = sanitize_text_field($_GET['order']);
        }
        $result = strcmp( $a->$orderby, $b->$orderby );
        if($order === 'asc')
        {
            return $result;
        }
        return -$result;
    }

    function log_get_order_report_query( $query ){

        global $wc_cxpfm_tools;
        $wc_cxpfm_tools->log( 'debug', 'get_order_report_query : ' . wc_print_r( $query, true ) );

        return $query;

    }
}
