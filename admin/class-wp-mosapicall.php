<?php
/**
 * Used to make API calls to Kounta.
 * @uses WP_MOScURL class
 *
 * This class is based off of Kounta's Merchant OS github repo:
 * https://github.com/merchantos/api_samples/tree/master/php/MOSAPI
 *
 *
 */
if (class_exists('WP_MOSAPICall')) {
    return;
}

if (!class_exists('WP_MOScURL')) {
    return;
}

class WP_MOSAPICall
{

    protected $_mos_api_url_https = 'https://api.kounta.com/v1/';
    protected $_mos_api_url = 'https://api.kounta.com/v1/';

    /**
     * @var
     * @deprecated
     */
    protected $_api_key;

    /**
     * Kounta account id
     * @var
     */
    protected $_account_num;

    /**
     * oAuth token
     * @var null
     */
    protected $_token;

    /**
     * MerchantOS API Call
     * @var string
     */
    public $api_call;

    /**
     * MerchantOS API Action
     * @var string
     */
    public $api_action;

    public function __construct($api_key, $account_num, $token = null)
    {
        $this->_api_key = $api_key;
        $this->_account_num = $account_num;
        if (isset($token)) {
            $this->_token = $token;
        }
    }

    public function makeAPICall($controlname, $action = 'Read', $unique_id = null, $data = array(), $query_str = '', Closure $callback = null, $emitter = 'json')
    {

        $this->api_call = $controlname;
        $this->api_action = $action;

        $custom_request = 'GET';

        switch ($action) {
            case 'Create':
                $custom_request = 'POST';
                break;
            case 'Read':
                $custom_request = 'GET';
                break;
            case 'Update':
                $custom_request = 'PUT';
                break;
            case 'Delete':
                $custom_request = 'DELETE';
                break;
        }

        $curl = new WP_MOScURL();
        if (isset($this->_token) && !empty($this->_token)) {;
            $curl->setOAuth($this->_token);
        } else {
            $curl->setBasicAuth($this->_api_key->username, $this->_api_key->password);
        }

        $curl->setVerifyPeer(false);
        $curl->setVerifyHost(0);
        $curl->setCustomRequest($custom_request);
        $curl->setReturnHeaders(true);

        $control_url = $this->_mos_api_url_https . str_replace('.', '/', str_replace('companies.', 'companies.' . $this->_account_num . '.', $controlname));
        // echo 'controlURL: ' . $control_url . '<br/>';
        if ($unique_id) {
            $control_url .= '/' . $unique_id;
        }

        if ($query_str) {
            if (is_array($query_str)) {
                $query_str = $this->build_query_string($query_str);
            }

            $control_url .= '.' . $emitter . '?' . $query_str;
        } else {
            $control_url .= '.' . $emitter;
        }
        if (is_array($data) && count($data) > 0) {
            $body = json_encode($data);
        } elseif (is_string($data)) {
            $body = $data;
        } else {
            $body = '';
        }

        if (!is_null($callback)) {
            $callback($curl, $body);
        }

        return self::_makeCall($curl, $control_url, $body);
    }

    public static function plugin_log( $entry, $mode = 'a', $file = 'brewhq-kounta-apicalls' ) {
        // Get WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];

        // If the entry is array, json_encode.
        if ( is_array( $entry ) ) {
            $entry = json_encode( $entry );
        }

        // Write the log file.
        // $file  = $upload_dir . '/' . $file . '.log';
        // $file  = fopen( $file, $mode );
        // $bytes = fwrite( $file, current_time( 'mysql' ) . "::" . $entry . "\n" );
        // fclose( $file );

        // return $bytes;
    }

    protected static function _makeCall($curl, $url, $body)
    {
        self::plugin_log("//");
        self::plugin_log("//");
        self::plugin_log("/***** START API CALL *****/");
        self::plugin_log("URL: ".$url);
        $requestBody = "No request body";
        if($body) $requestBody = $body;
        self::plugin_log("Body: ".$requestBody);

        $raw_response = $curl->call($url, $body);
        $result = wp_remote_retrieve_body($raw_response);
        try {
            self::plugin_log("/* Raw Response */");
            self::plugin_log($raw_response);
            self::plugin_log("Response Code: ".wp_remote_retrieve_response_code($raw_response));
            self::plugin_log("Response Message: ".wp_remote_retrieve_response_message($raw_response));
            self::plugin_log("/* Response Body */");
            self::plugin_log($result);
        } catch (Exception $e) {
            throw new Exception('MerchantOS API Call Error: ' . $e->getMessage() . ', Response: ' . $result);
        }

        try {
            $return = json_decode($result);
            if($return == null){
              return $result;
            }
        } catch (Exception $e) {
            throw new Exception('MerchantOS API Call Error: ' . $e->getMessage() . ', Response: ' . $result);
        }

        // if (!is_object($return)) {
        //     try {
        //         $xml_obj = new SimpleXMLElement($result);
        //         $return = json_decode(json_encode($xml_obj));
        //     } catch (Exception $e) {
        //         throw new Exception('MerchantOS API Call Error: ' . $e->getMessage() . ', Response: ' . $result);
        //     }

        //     if (!is_object($return)) {
        //         throw new Exception('MerchantOS API Call Error: Could not parse XML, Response: ' . $result);
        //     }
        // }

        self::plugin_log("/***** End API Call *****/");

        return $return;
    }

    private function build_query_string($data)
    {
        if (function_exists('http_build_query')) {
            return http_build_query($data);
        } else {
            $qs = '';
            foreach ($data as $key => $value) {
                $append = urlencode($key) . '=' . urlencode($value);
                $qs .= $qs ? '&' . $append : $append;
            }
            return $qs;
        }
    }
}
