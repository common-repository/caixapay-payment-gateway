<?php


class wc_cxpfm_payment
{

    var $cxpfm_API_URL = 'https://caixapay-api.com/api/ask_payment.php';
    var $CASHBACK_API_URL = 'https://caixapay.money/new_purchase';

    function __construct()
    {

        global $cxpfm_WC_Logger;// our WC_Logger instance


        if( get_option( 'wc_cxpfm_enable' ) ){

            global $wc_cxpfm_settings;

            // Add this Gateway to WooCommerce
            add_filter('woocommerce_payment_gateways', array( $this, 'woocommerce_add_cxpfm_gateway' ) );

            // Display payment unit on the order details table
            add_action('woocommerce_order_details_after_order_table', array( $this, 'display_unit_caixapay_explorer_link'), 10, 1);

            // Display Caixapay payment button on thankyou page
            add_action('wp_enqueue_scripts', array( $this, 'load_paybutton_scripts') );
            add_action('woocommerce_thankyou', array( $this, 'render_paybutton') );

            add_filter('woocommerce_get_formatted_order_total', array( $this, 'display_total_in_caixapay' ), 10, 2 );

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_cxpfm', array(
                $this,
                'handle_cxpfm_notifications'
            ));


            // New order customer notification

            // if( get_option( 'wc_cxpfm_new_order_customer_notif' , $wc_cxpfm_settings->defaults[ 'new_order_customer_notif' ] ) ){ // does not trigger when option is not yet defined (!?)
            if( get_option( 'wc_cxpfm_new_order_customer_notif' , true ) ){

                // woocommerce_new_order hook is too early and woocommerce_thankyou hook could trigger many times, so we choose woocommerce_checkout_order_processed hook

                add_action( 'woocommerce_checkout_order_processed', array( $this, 'new_order_customer_notification' ), 10, 1 );

            }



            // cashback program
            if( get_option( 'wc_cxpfm_partner' ) ){

                // add customer Caixapay address to checkout fields
                add_filter( 'woocommerce_checkout_fields' , array( $this, 'add_customer_caixapay_address_field') );

                // validate Caixapay address
                add_action('woocommerce_checkout_process',  array( $this, 'check_order_caixapay_address' ) );

                // Display field value on the order edit page
                add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_admin_order_caixapay_address'), 10, 1 );

                // Make casback api request as soon as an order is set to completed
                add_action( 'woocommerce_order_status_completed', array( $this, 'make_cashback_api_request'), 10, 1 );

            }

        }


    }



    function new_order_customer_notification( $order_id ) {

        // Get an instance of the WC_Order object
        $order = wc_get_order( $order_id );

        // Only for "pending" order status
        if( ! $order->has_status( 'pending' ) ) return;

        // Only for Caixapay payments
        if( $order->get_payment_method() !== 'cxpfm' ) return;

        // check that customer invoice email is well defined
        $wc_customer_invoice_email = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
        if( empty( $wc_customer_invoice_email ) ) return;

        // Send mail to customer
        $wc_customer_invoice_email->trigger( $order_id, $order );

    }




    function make_cashback_api_request( $order_id ) {

        global $wc_cxpfm_tools;

        // log
        $wc_cxpfm_tools->log( 'debug', "order_id $order_id completed" );

        $partner = get_option( 'wc_cxpfm_partner' );

        if( ! $partner ){
            $wc_cxpfm_tools->log( 'debug', "no partner name found" );
            return;
        }

        $address = get_post_meta( $order_id, '_billing_caixapay_address', true);

        if( ! $address ){
            $wc_cxpfm_tools->log( 'debug', "no _billing_caixapay_address found" );
            return;
        }


        /*
         * set up request
         */

        $wc_order = new WC_Order($order_id);

        if( $wc_order->get_payment_method() == 'cxpfm' ){
            $currency = 'CXP';
            $currency_amount = get_post_meta( $order_id, '_wc_cxpfm_received_amount', true);
            $currency_amount = $currency_amount * (1/1000000000);
        }
        else{
            $currency = get_woocommerce_currency();
            $currency_amount = $wc_order->get_total();
        }

        $data = array(
            'partner' => $partner,
            'partner_key' => get_option( 'wc_cxpfm_partner_key' ),
            'customer' => $wc_order->get_customer_id(),
            'order_id' => $order_id,
            'description' => 'woocommerce sale',// ?
            'merchant' => $partner, // ?
            'address' => $address,
            'currency' => $currency,
            'currency_amount' => $currency_amount,
            'partner_cashback_percentage' => get_option( 'wc_cxpfm_partner_cashback_percent', '0' ),
            'purchase_unit' => get_post_meta( $order_id, '_wc_cxpfm_receive_unit', true),
        );


        // for cashback server specific config
        add_action( 'http_api_curl', array( $this, 'customize_curl_options' ) );


        /*
         * make request
         */


        // logs
        $log_msg = "** cashback request( $order_id ) ***";
        $log_msg .= " post data : " . wc_print_r( $data, true );
        $wc_cxpfm_tools->log( 'debug', $log_msg );

        $response = wp_remote_post( $this->CASHBACK_API_URL, array( 'body' => $data ) );

        // logs
        $log_msg = "*** response cashback request( $order_id ) ***";
        $log_msg .= " " . wc_print_r( $response, true );
        $wc_cxpfm_tools->log( 'debug', $log_msg );


        // error
        if( is_wp_error( $response ) ){

            $wc_order->add_order_note(__('Curl error on cashback api request', 'cxpfm-woocommerce') .' : ' . $response->get_error_message() );

            update_post_meta( $order_id, '_wc_cxpfm_cashback_result', 'error' );
            update_post_meta( $order_id, '_wc_cxpfm_cashback_error', $response->get_error_message() );

            return;

        }

        $returned_body_json = $response[ 'body' ];
        $returned_body = json_decode( $returned_body_json, true );

        if( $returned_body[ 'result' ] == 'error' ){
            $cashback_error_msg = $returned_body[ 'error' ];
            $wc_order->add_order_note(__('Error returned on cashback api request', 'cxpfm-woocommerce') .' : ' . $cashback_error_msg );
            update_post_meta( $order_id, '_wc_cxpfm_cashback_error', sanitize_text_field( $cashback_error_msg ) );
        }
        else if( $returned_body[ 'result' ] == 'ok' ){
            $cashback_amount = $returned_body[ 'cashback_amount' ];
            $cashback_unit = $returned_body[ 'unit' ];

            $wc_order->add_order_note(__('Cashback request has been processed', 'cxpfm-woocommerce') .' -  cashback cxp amount : ' . $cashback_amount . ' - cashback unit : ' . $cashback_unit );

            update_post_meta( $order_id, '_wc_cxpfm_cashback_amount', sanitize_text_field( $cashback_amount ) );
            update_post_meta( $order_id, '_wc_cxpfm_cashback_unit', sanitize_text_field( $cashback_unit ) );
        }
        else{
            $wc_order->add_order_note( 'Unhandled returned result on cashback API request : ' . $returned_body[ 'result' ] );
        }

        update_post_meta( $order_id, '_wc_cxpfm_cashback_result', sanitize_text_field( $returned_body[ 'result' ] ) );

        return;


    }


    function load_paybutton_scripts() {

        global $wc_cxpfm_tools;

        $order_id = null;




        
        if( isset( $_REQUEST["order-received"] ) ){
            $order_id = sanitize_text_field($_REQUEST["order-received"]);
        }

        // sanitize in line 230
        else if( isset( $_REQUEST["key"] ) ){
            $order_key = sanitize_text_field(wc_clean( $_REQUEST["key"] ));
            $order_id = sanitize_text_field(wc_get_order_id_by_order_key( $order_key ));
        }

        if( $order_id ){

            $data = $this->set_payment_data( $order_id );

            //$wc_cxpfm_tools->log( 'debug', 'paybutton data : ' . wc_print_r( $data, true ) );

            wp_enqueue_style(
                'cxpfm_style',
                wc_cxpfm_PATH . 'cxpfm-style.css'
            );

            wp_enqueue_script(
                'cxpfm_payment_button',
                'https://caixapay-api.com/api/payment-button.js'
            );

            wp_localize_script( 'cxpfm_payment_button', 'cxpfm_params', $data );

        }

    }


    function render_paybutton( $order_id ){

        $order = new WC_Order($order_id);

        if( $order->get_payment_method() == 'cxpfm' ){

            global $wc_cxpfm_settings;

            echo "<div id=\"cxpfm_before_paybutton_info\">" . get_option( 'wc_cxpfm_before_paybutton_msg', $wc_cxpfm_settings->defaults[ 'before_paybutton_msg' ] ) . "</div>";

            echo "<div id=\"cxpfm_container\"></div>";// and just let the cxpfm's magic js operate !

            echo "<div id=\"cxpfm_after_paybutton_info\">" . get_option( 'wc_cxpfm_after_paybutton_msg', $wc_cxpfm_settings->defaults[ 'after_paybutton_msg' ] ) . "</div>";

            global $wc_cxpfm_tools;
            $wc_cxpfm_tools->log( 'debug', 'render paybutton' );

        }

    }


    function display_total_in_caixapay( $formatted_total, $order ){

        if( $order->get_payment_method() == 'cxpfm' and get_post_meta( $order->id, '_wc_cxpfm_amount_BB_asked', true ) ){

            global $wc_cxpfm_tools;

            // $formatted_total = $formatted_total . ' ( ' . number_format_i18n( get_post_meta( $order->id, '_wc_cxpfm_amount_BB_asked', true ) ) . ' CXP )';
            $formatted_total = $formatted_total . ' (' . $wc_cxpfm_tools->byte_format( get_post_meta( $order->id, '_wc_cxpfm_amount_BB_asked', true ) ) . ')';

        }

        return $formatted_total;

    }





    function check_order_caixapay_address() {

        global $wc_cxpfm_tools;

        // for WORDPRESS REVIEW this function not need check because is make in check_BB_address



        if ( isset( $_POST['billing_caixapay_address'] ) and $_POST['billing_caixapay_address'] ){

            // if( ! $wc_cxpfm_tools->check_BB_address( $_POST['billing_caixapay_address'] ) ){
            //     wc_add_notice( 'Invalid Caixapay address' , 'error' );
            // }

            if( ! $wc_cxpfm_tools->check_BB_address( sanitize_text_field($_POST['billing_caixapay_address'] )) ){

                if ( ! is_email( $_POST['billing_caixapay_address'] ) ){

                    wc_add_notice( 'Invalid <strong>cashback address</strong> (should be a valid Caixapay or email address).' , 'error' );
                    return;
                }

            }

        }



        else if( isset( $_POST[ 'payment_method' ] ) and $_POST[ 'payment_method' ] == 'cxpfm' ){

            wc_add_notice( "You must enter a <strong>cashback address</strong> if you pay with CXP ( otherwise you won't receive any cashback! )." , 'error' );


        }
    }


    function add_invalid_class_to_caixapay_address_field( $fields ){

        $fields['billing']['billing_caixapay_address']['required'] = true;
        $fields['billing']['billing_caixapay_address']['label'] .= '<abbr class="required" title="requis">*</abbr>';

        return $fields;

    }


    function add_customer_caixapay_address_field( $fields ) {

        global $wc_cxpfm_settings;

        $cashback_addr_label = "<strong>* Cashback address *</strong><br>" . get_option( 'wc_cxpfm_cashback_addr_msg', $wc_cxpfm_settings->defaults[ 'cashback_address_msg' ] );

         $fields['billing']['billing_caixapay_address'] = array(
            'label'     => $cashback_addr_label,
            'placeholder'   => _x('your Caixapay or email address', 'placeholder', 'woocommerce'),
            'required'  => false,
            'class'     => array('form-row-wide'),
            'clear'     => true,
            'validate'  => false,
         );

         return $fields;

    }


    function display_admin_order_caixapay_address( $order ){
        echo '<p><strong>'.__('Cashback Caixapay address').':</strong> ' . get_post_meta( $order->get_id(), '_billing_caixapay_address', true ) . '</p>';
    }


    /*
     * ask cxpfm server for a Caixapay payment address
     */

    function ask_payment( $order_id ){
        global $wc_cxpfm_tools;

        $data = $this->set_payment_data( $order_id );

        // logs
        $log_msg = "** ask_payment( $order_id ) ***";
        $log_msg .= " post data : " . wc_print_r( $data, true );
        $wc_cxpfm_tools->log( 'debug', $log_msg );

        $response = wp_remote_post( $this->cxpfm_API_URL, array( 'body' => $data ) );

        // logs
        $log_msg = "*** response ask_payment( $order_id ) ***";
        $log_msg .= " " . wc_print_r( $response, true );
        $wc_cxpfm_tools->log( 'debug', $log_msg );


        // error
        if( is_wp_error( $response ) ){
            $return_error = $this->return_error( $response->get_error_message() );
            return $return_error;
        }

        return $response[ 'body' ];
    }


    /*
     * handle notif received fom cxpfm server
     */

    function handle_cxpfm_notifications(){

        global $wc_cxpfm_tools;

        # logs
        $wc_cxpfm_tools->log( 'debug', 'notification received : ' . wc_print_r( $_REQUEST, true ) );

        /*
         * notification IP security
         */


        # check notif IP
        $notif_IP = $_SERVER['REMOTE_ADDR'];
        $allowed_notif_IPs = get_option( 'wc_cxpfm_allowed_notif_IPs', array('178.128.243.234') ); //default cxpfm server IP

        if( ! in_array( $notif_IP, $allowed_notif_IPs ) ){
            // logs
            $wc_cxpfm_tools->log( 'debug', 'unallowed notif IP ' . $notif_IP . ' : notification will be ignored' );
            wp_die( 'unallowed notif IP', 403 );
        }

        # logs
        $wc_cxpfm_tools->log( 'debug', 'allowed notif IP ' . $notif_IP );



        # manage allowed_notif_IPs changes



        // sanitized in 450 line
        $new_allowed_notif_IPs = isset($_REQUEST['allowed_notif_IPs']) ? sanitize_text_field($_REQUEST['allowed_notif_IPs']) : "";

        if( $new_allowed_notif_IPs ){
            $update_allowed_notif_IPs = array( $notif_IP ); // always keep current notif IP

            # and then add new IPs
            foreach( $new_allowed_notif_IPs as $IP ){
                if( ! in_array( $IP, $update_allowed_notif_IPs ) ){
                    $update_allowed_notif_IPs[] = $IP;
                }
            }
            update_option( 'wc_cxpfm_allowed_notif_IPs', $update_allowed_notif_IPs );
        }

        # first read secret key cxpfm should be the only one to know

        // sanitized in 451 line
        $secret_key = isset($_REQUEST['secret_key']) ? sanitize_text_field($_REQUEST['secret_key']) : "";

        if( $secret_key ){

            $callback_secret = get_option("wc_cxpfm_callback_secret");

            # bad secret_key
            if( $callback_secret != $secret_key ){
                $wc_cxpfm_tools->log( 'debug', 'notif secret_key not equal to registered callback_secret (' . $callback_secret . ')' );
                wp_die( 'bad secret key', 403 );
            }

            // for WORDPRES REVIEV this checked in 441 line

            # now authentified notif
            // sanitized in 452 line
            $order_id = isset($_REQUEST['order_UID']) ? sanitize_text_field($_REQUEST['order_UID']) : "";
            // sanitized in 453 line
            $result = isset($_REQUEST['result']) ? sanitize_text_field($_REQUEST['result']) : "";
            // sanitized in 454 line
            $cashback_result = isset($_REQUEST['cashback_result']) ? sanitize_text_field($_REQUEST['cashback_result']) : "";


            if( $result ){
                # order payment status
                $amount_asked_in_currency = $this->sanitize_and_register_input( 'amount_asked_in_currency', $order_id );
                $currency_B_rate = $this->sanitize_and_register_input( 'currency_B_rate', $order_id );
                $received_amount = $this->sanitize_and_register_input( 'received_amount', $order_id );
                $receive_unit = $this->sanitize_and_register_input( 'receive_unit', $order_id );
                $fee = $this->sanitize_and_register_input( 'fee', $order_id );
                $amount_sent = $this->sanitize_and_register_input( 'amount_sent', $order_id );
                $unit = $this->sanitize_and_register_input( 'unit', $order_id );
                $result = $this->sanitize_and_register_input( 'result', $order_id );

                $wc_order = new WC_Order( $order_id );

                # result nok
                if( $result == 'nok' ){

                    $error_msg = $this->sanitize_and_register_input( 'error_msg', $order_id );

                    $wc_order->update_status('failed', __($error_msg, 'cxpfm-woocommerce'));

                    wp_die( 'ok', 200 );

                }

                # result receiving
                if( $result == 'receiving' ){
                    $wc_order->update_status('on-hold', __( 'Costumer payment has been received by cxpfm server (not yet network confirmed).', 'cxpfm-woocommerce' ));
                    wp_die( 'ok', 200 );
                }

                # result received
                if( $result == 'received' ){
                    $wc_order->add_order_note(__('Costumer payment has been confirmed by the network.', 'cxpfm-woocommerce'));
                    wp_die( 'ok', 200 );
                }

                # result unconfirmed
                if( $result == 'unconfirmed' ){
                    $wc_order->add_order_note(__('Payment has been sent to you but is still waiting for network confirmation.', 'cxpfm-woocommerce'));
                    wp_die( 'ok', 200 );
                }

                # result completed
                if( $result == 'ok' ){
                    # check received_amount (!)
                    if( $received_amount != get_option('_wc_cxpfm_amount_BB_asked', true) ){
                        $error_msg = 'Received amount does not match asked amount';
                        $wc_order->update_status('failed', __($error_msg, 'cxpfm-woocommerce'));
                    }
                    else{
                        $wc_order->add_order_note(__('Payment completed', 'cxpfm-woocommerce'));
                        $wc_order->payment_complete( $unit );
                    }
                    wp_die( 'ok', 200 );
                }

                $wc_cxpfm_tools->log( 'debug', 'not handled result' );
                wp_die( 'not handled result', 200 );

            }
            else if( $cashback_result ){

                /*
                 * cashback status
                 */

                $cashback_result = $this->sanitize_and_register_input( 'cashback_result', $order_id );
                $cashback_error_msg = $this->sanitize_and_register_input( 'cashback_error_msg', $order_id );
                $cashback_amount = $this->sanitize_and_register_input( 'cashback_amount', $order_id );
                $cashback_unit = $this->sanitize_and_register_input( 'cashback_unit', $order_id );
                // $cashback_notified = $this->sanitize_and_register_input( 'cashback_notified', $order_id );

                $wc_order = new WC_Order( $order_id );

                # cashback_result ok
                if( $cashback_result == 'ok' ){
                    $wc_order->add_order_note( __('Cashback successfully processed', 'cxpfm-woocommerce') . ". $cashback_amount CXP sent on unit $cashback_unit" );
                    wp_die( 'ok', 200 );
                }

                # cashback_result error
                if( $cashback_result == 'error' ){
                    $wc_order->add_order_note(__('Error on cashback api request', 'cxpfm-woocommerce') . ' : ' . $cashback_error_msg );
                    wp_die( 'ok', 200 );
                }

                $wc_cxpfm_tools->log( 'debug', 'not handled cashback result' );
                wp_die( 'not handled cashback result', 200 );

            }

        }

        $wc_cxpfm_tools->log( 'debug', 'Unauthorized request' );
        wp_die( 'Unauthorized', 401 );


    }// handle_cxpfm_notifications


    function sanitize_and_register_input( $var_name, $order_id ){

        // FOR WP REVIEW ^^^ 590 line  check it
        isset($_REQUEST[ $var_name ]) ? sanitize_text_field($_REQUEST[ $var_name ]) : "";
        $sanitized_value = sanitize_text_field( $value );
        update_post_meta( $order_id, '_wc_cxpfm_' . $var_name, $sanitized_value );

        return $sanitized_value;
    }


    function set_payment_data( $order_id ){

        $order = new WC_Order($order_id);

        $data = array(
            'mode' => 'live',
            'mode_notif' => 'POST',
            'order_UID' => $order_id,
            'currency' => get_woocommerce_currency(),
            'merchant_return_url' => WC()->api_request_url('WC_Gateway_cxpfm'),
            'amount' => $order->get_total(),
            'merchant_email' => get_option( 'wc_cxpfm_merchant_email' ),
            'partner' => get_option( 'wc_cxpfm_partner' ),
            'partner_key' => get_option( 'wc_cxpfm_partner_key' ),
            'partner_cashback_percentage' => get_option( 'wc_cxpfm_partner_cashback_percent' ),
            'customer' => $order->get_customer_id(),
            'description' => 'woocommerce sale',// ?
            'caixapay_merchant_address' => get_option( 'wc_cxpfm_caixapay_address' ),
            'callback_secret' => $this->cxpfm_callback_secret(),
            'cashback_address' => get_post_meta( $order_id, '_billing_caixapay_address', true),
            'display_powered_by' => get_option( 'wc_cxpfm_display_powered_by' ),
            'wc_cxpfm_VERSION' => wc_cxpfm_VERSION,
        );

        return $data;

    }


    function cxpfm_callback_secret()
    {
        $callback_secret = get_option("wc_cxpfm_callback_secret");

        if ( !$callback_secret ) {

            $callback_secret = sha1(openssl_random_pseudo_bytes(20));

            // logs
            global $wc_cxpfm_tools;
            $wc_cxpfm_tools->log( 'debug', 'new callback_secret created : ' . wc_print_r( $callback_secret, true ) );

            update_option("wc_cxpfm_callback_secret", $callback_secret);

        }

        return $callback_secret;
    }


    function return_error( $msg ){

        $return = array(
            'result' => 'nok',
            'error_msg' => $msg,
        );

        return json_encode($return);

    }


    function display_unit_caixapay_explorer_link( $order ){

        $receive_unit = get_post_meta($order->id, '_wc_cxpfm_receive_unit', true);

        if( $receive_unit ){
            echo '<p><strong>'.__('cxp payment unit', 'cxpfm-woocommerce').':</strong>  <a href ="' . 'https://explorer.Caixapay.com/#' . $receive_unit . '" target="blank" title="see unit in Caixapay explorer" >' . $receive_unit . '</a></p>';
        }

    }


    function woocommerce_add_cxpfm_gateway($methods){

        $methods[] = 'WC_Gateway_cxpfm';
        return $methods;

    }


    function customize_curl_options( $curl ) {

        if ( ! $curl ) {
            return;
        }

        $curl_getinfo = curl_getinfo( $curl );

        # just for cashback server requests
        if( $curl_getinfo['url'] != $this->CASHBACK_API_URL){
            return;
        }

        // global $wc_cxpfm_tools;
        // $wc_cxpfm_tools->log( 'debug', 'curl_getinfo : ' . wc_print_r( curl_getinfo($curl), true ) );

        # to avoid cURL error 35: Cannot communicate securely with peer: no common encryption algorithm(s) ]
        // curl_setopt( $curl, CURLOPT_SSL_CIPHER_LIST, 'ecdhe_ecdsa_aes_256_sha' );

        $wc_cxpfm_partner_SSL_CIPHER_LIST = get_option("wc_cxpfm_partner_SSL_CIPHER_LIST");

        if( $wc_cxpfm_partner_SSL_CIPHER_LIST ){
            curl_setopt( $curl, CURLOPT_SSL_CIPHER_LIST, $wc_cxpfm_partner_SSL_CIPHER_LIST );
        }

        return;

    }


}

