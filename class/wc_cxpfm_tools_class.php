<?php

class wc_cxpfm_tools
{
    function __construct() {

    }

    function check_BB_address( $address ){

        if( preg_match( "@^[0-9A-Z]{32}$@", $address ) or $address == 'NO-SENDING-ADDRESS-ON-TEST-MODE' ){
            return true;
        }else{
            return false;
        }

    }


    function log( $level, $msg ){

        if( get_option('wc_cxpfm_log_enable', true) ){

            global $cxpfm_WC_Logger;

            $cxpfm_WC_Logger->log( $level, $msg, array( 'source' => 'CXP payment' ));

        }

    }


    function byte_format( $val ){

        if( $val < pow( 10, 3 ) ){

            $result = $val . ' nCXP';

        }else if( $val < pow( 10, 4 ) ){

            $result = number_format_i18n( $val / pow( 10, 3 ), 2) . ' mCXP';

        }else if( $val < pow( 10, 5 ) ){

            $result = number_format_i18n( $val / pow( 10, 3 ), 1) . ' mCXP';

        }else if( $val < pow( 10, 6 ) ){

            $result = number_format_i18n( $val / pow( 10, 3 ), 0) . ' mCXP';

        }else if( $val < pow( 10, 7 ) ){

            $result = number_format_i18n( $val / pow( 10, 6 ), 2) . ' MBytes';

        }else if( $val < pow( 10, 8 ) ){

            $result = number_format_i18n( $val / pow( 10, 6 ), 1) . ' MBytes';

        }else if( $val < pow( 10, 9 ) ){

            $result = number_format_i18n( $val / pow( 10, 6 ), 0) . ' MBytes';

        }else if( $val < pow( 10, 10 ) ){

            $result = number_format_i18n( $val / pow( 10, 9 ), 2) . ' CXPs';

        }else if( $val < pow( 10, 11 ) ){

            $result = number_format_i18n( $val / pow( 10, 9 ), 1) . ' CXPs';

        }else{

            $result = number_format_i18n( $val / pow( 10, 9 ), 0) . ' CXPs';

        }

        return $result;

    }

}

