<?php
/**
 * Plugin Name:     CaixaPay payment gateway
 * Plugin URI:      https://caixapay-api.com/
 * Description:     Accept Caixapay CXP on your WooCommerce-powered website
 * Version:         1.0.2
 * Author:          caixapay.com
 * Author URI:      https://caixapay-api.com/
 * Text Domain:     caixapay-payment-gateway
 * License:         GNU General Public License v3.0
 * License URI:     http://www.gnu.org/licenses/gpl-3.0.html
 */



if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!defined('wc_cxpfm_VERSION')) { 
    define( 'wc_cxpfm_VERSION', '1.2.0' );
}

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );


/*
 * activate the admin notice to go on plugin settings page
 */

function wc_cxpfm_plugin_activate() {

    # check PHP
    // if (version_compare(PHP_VERSION, '5.4', '<')) {
    //     deactivate_plugins(basename(__FILE__));
    //     wp_die(__('<p><strong>CaixaPay payment gateway</strong> plugin requires PHP version 5.4 or greater.</p>', 'cxpfm-woocommerce'));
    // }

    update_option("wc_cxpfm_seen_settings_page", false);

}
register_activation_hook( __FILE__, 'wc_cxpfm_plugin_activate' );



if ( is_plugin_active( 'woocommerce/woocommerce.php') || class_exists( 'WooCommerce' )) {

    define( 'wc_cxpfm_PATH', plugin_dir_url( __FILE__ ) );
    define( 'wc_cxpfm_BASENAME', plugin_basename( __FILE__ ) );
    define( 'wc_cxpfm_CLASS_PATH', plugin_dir_path(__FILE__) . 'class/' );


    // wc_cxpfm_tools class
    require_once(wc_cxpfm_CLASS_PATH . 'wc_cxpfm_tools_class.php');
    $wc_cxpfm_tools = new wc_cxpfm_tools;


    // wc_cxpfm_payment class
    require_once(wc_cxpfm_CLASS_PATH . 'wc_cxpfm_payment_class.php');
    $wc_cxpfm_payment = new wc_cxpfm_payment;


    // wc_cxpfm_settings class
    require_once(wc_cxpfm_CLASS_PATH . 'wc_cxpfm_settings_class.php');
    $wc_cxpfm_settings = new wc_cxpfm_settings;


    // WP_List_Table is not loaded automatically so we need to load it in our application
    if( ! class_exists( 'WP_List_Table' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
    }

    // wc_cxpfm_report class
    require_once(wc_cxpfm_CLASS_PATH . 'wc_cxpfm_report_class.php');
    $wc_cxpfm_report = new wc_cxpfm_report;

    // to save logs in DB (WC doc says to put it in wp-config.php but it is not really a plugin territory)
    if( ! defined( 'WC_LOG_HANDLER' ) ) define( 'WC_LOG_HANDLER', 'WC_Log_Handler_DB' );




    function cxpfm_woocommerce_init()
    {

        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }


        /*
         * check woocommerce version
         */

        global $woocommerce;
        $wc_cxpfm_woocommerce_required_version = '3.0';

        if ( version_compare($woocommerce->version, $wc_cxpfm_woocommerce_required_version, '<') ) {

            deactivate_plugins( plugin_basename( __FILE__ ) );

            wp_die( __( 'CaixaPay payment gateway requires WooCommerce ' . $wc_cxpfm_woocommerce_required_version . ' or higher', 'cxpfm-woocommerce' ) . '<br>' . '<a href="' . admin_url( 'plugins.php' ) . '">back to plugins</a>' );

        }


        /*
         * setup logs
         */

        global $cxpfm_WC_Logger;

        // $cxpfm_WC_Logger = new WC_Logger( null, get_option('wc_cxpfm_log_level', true) );
        $cxpfm_WC_Logger = new WC_Logger();
        // define( 'wc_cxpfm_LOG_SRC', 'BB_for_Woo' );


        /**
         * Caixapay Payment Gateway
         */

        class WC_Gateway_cxpfm extends WC_Payment_Gateway{

            public function __construct(){

                if( get_option( 'wc_cxpfm_partner' ) ){
                    $description = __("Pay with cxp and earn a <strong>" . (20 + get_option( 'wc_cxpfm_partner_cashback_percent' ) * 2) . "% cashback</strong>!", 'cxpfm-woocommerce');
                }
                else{
                    $description = __("Pay with cxp.");
                }
                if( get_option( 'wc_cxpfm_display_powered_by' ) ){
                    $description .= '<br>' . __("Powered by ", 'cxpfm-woocommerce'). "<a href='https://caixapay-api.com/' target='_blank'>caixapay-api.com</a>";
                }

                $this->id                = 'cxpfm';
                $this->icon              = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/caixapay.png';
                $this->has_fields        = false;
                $this->order_button_text = __('Pay with cxp', 'cxpfm-woocommerce');
                $this->title             = 'Caixapay cxp';
                $this->enable            = get_option( 'wc_cxpfm_enable' );
                $this->description       = $description;

                add_action('woocommerce_receipt_' . $this->id, array(
                    $this,
                    'receipt_page'
                ));


            }


            function add_customer_caixapay_address_field( $fields ) {
                 $fields['billing']['caixapay_address'] = array(
                    'label'     => __('Caixapay address', 'cxpfm-woocommerce'),
                    'placeholder'   => _x('enter your cashback Caixapay address', 'placeholder', 'woocommerce'),
                    'required'  => true,
                    'class'     => array('form-row-wide'),
                    'clear'     => true,
                 );

                 return $fields;
            }


            function display_admin_order_caixapay_address( $order ){
                echo '<p><strong>'.__('Cashback Caixapay address').':</strong> ' . get_post_meta( $order->get_id(), '_caixapay_address', true ) . '</p>';
            }

            public function init_form_fields()
            {
                $this->form_fields = array(

                );
            }


            public function process_payment( $order_id ){
                global $woocommerce, $wc_cxpfm_payment, $wc_cxpfm_tools;

                $order = new WC_Order($order_id);
                $ask_payment_json = $wc_cxpfm_payment->ask_payment( $order_id );
                $ask_payment = json_decode( $ask_payment_json, true );
                $CXPaddress = $ask_payment[ 'CXPaddress' ];
                $amount_BB_asked = $ask_payment[ 'amount_BB_asked' ];

                // ask_payment result = nok
                if( $ask_payment[ 'result' ] == 'nok' ){
                    wc_add_notice( 'Error returned when asking for Caixapay payment address : ' . $ask_payment[ 'error_msg' ], 'error' );
                    return;
                }

                // ask_payment result = 'completed'
                if( $ask_payment[ 'result' ] == 'completed' ){
                    // check received_amount (!)
                    if( $amount_BB_asked != get_post_meta( $order_id, '_wc_cxpfm_amount_BB_asked', true ) ){
                        $error_msg = 'Received amount does not match asked amount';
                        $order->update_status('failed', __($error_msg, 'cxpfm-woocommerce'));
                        wc_add_notice( $error_msg, 'error' );
                    }
                    else{
                        $order->update_status('completed');
                    }
                    return;
                }

                // ask_payment result = 'processing'
                if( $ask_payment[ 'result' ] == 'processing' ){
                    $order->update_status('processing');
                    wc_add_notice( 'The payment of this order is already processing...', 'error' );
                    return;
                }

                // ask_payment result unknown
                if( $ask_payment[ 'result' ] != 'ok' ){
                    wc_add_notice( 'Unknown result value when asking for Caixapay payment address : ' . $ask_payment[ 'result' ], 'error' );
                    return;
                }

                // check $CXPaddress
                if (! $CXPaddress){
                    $error_msg = "Could not generate new payment Caixapay address. Note to webmaster: Contact us on https://Caixapay.com/discord";
                    wc_add_notice($error_msg, 'error');
                    return;
                }
                else if( ! $wc_cxpfm_tools->check_BB_address( $CXPaddress ) ){
                    $error_msg = "Received invalid payment Caixapay address. Note to webmaster: Contact us on https://Caixapay.com/discord";
                    wc_add_notice($error_msg, 'error');
                    return;
                }

                // check $amount_BB_asked
                if( ! preg_match( "@^[0-9]{1,12}$@", $amount_BB_asked ) && ! preg_match( "@^[-+]?[0-9]*\.?[0-9]+([eE][-+]?[0-9]+)?$@", $amount_BB_asked )  ){
                    $error_msg = "Received invalid cxp amount. Note to webmaster: Contact us on https://Caixapay.com/discord";
                    wc_add_notice($error_msg, 'error');
                    return;
                }

                // register order payment infos
                update_post_meta( $order_id, '_wc_cxpfm_CXPaddress', $CXPaddress );
                update_post_meta( $order_id, '_wc_cxpfm_amount_BB_asked', $amount_BB_asked );

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),

                );

            }

        }

    }

    add_action('plugins_loaded', 'cxpfm_woocommerce_init' );

}else{

    add_action( 'admin_notices', 'wc_cxpfm_admin_notice_woocommerce_needed' );

}


function wc_cxpfm_admin_notice_woocommerce_needed(){

    ?>
    <div class="notice notice-error is-dismissible">
        <p><strong><?php _e( 'CaixaPay payment gateway requires WooCommerce to be activated', 'cxpfm-woocommerce' ); ?></strong></p>
    </div>
    <?php

}

