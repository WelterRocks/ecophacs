<?php namespace WelterRocks\EcoPhacs;

/******************************************************************************

    EcoPhacs is a php class to control ecovacs api based devices
    Copyright (C) 2020  Oliver Welter  <oliver@welter.rocks>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

*******************************************************************************/

use \Norgul\Xmpp\XmppClient;
use \Norgul\Xmpp\Options;
use \phpseclib\File\X509;
use \WelterRocks\EcoPhacs\Config;
use \WelterRocks\EcoPhacs\Device;
    
class Client
{
    private $xmpp_client = null;
    private $xmpp_options = null;
    
    private $config = null;    
    private $config_file = null;
    
    public $has_logged_in = false;
    public $has_connected = false;
    
    public $device_list = null;
    
    public const TIMEZONE_DIFF = 'GMT-8';
    
    private function clear_login()
    {
        if (($this->has_connected) && (is_object($this->xmpp_client)))
        {
            try
            {
                $this->xmpp_client->disconnect();
            }
            catch(exception $ex)
            {
                ;
            }
        }
            
        $this->has_connected = false;
        
        $this->xmpp_client = null;
        $this->xmpp_options = null;

        $this->has_logged_in = false;
        
        return;
    }
        
    private function init_xmpp()
    {
        $this->has_connected = false;
        
        $this->xmpp_options = new Options();

        $this->xmpp_options->setSSLVerifyHost(false);
        $this->xmpp_options->setSSLVerifyPeer(false);
        $this->xmpp_options->setSSLAllowSelfSigned(true);
        $this->xmpp_options->setHost($this->config->xmpp_hostname);
        $this->xmpp_options->setPort($this->config->xmpp_port);
        $this->xmpp_options->setUsername($this->config->user_uid);
        $this->xmpp_options->setRealm($this->config->api_realm);
        $this->xmpp_options->setPassword('0/'.substr($this->config->device_id, 0, 8).'/'.$this->config->user_access_token);
        $this->xmpp_options->setResource(substr($this->config->device_id, 0, 8));
        $this->xmpp_options->setUseTls($this->config->xmpp_use_tls);
        $this->xmpp_options->setAuthZID($this->config->user_uid);
        
        $this->xmpp_client = new XmppClient($this->xmpp_options);
    }
        
    private function encrypt($plain, &$encrypted = null)
    {   
        $x509 = new X509();
        $x509->loadX509($this->config->public_key);
        
        openssl_public_encrypt($plain, $encrypted, $x509->getPublicKey());
                       
        if ($encrypted)
        {
            $encrypted = base64_encode($encrypted);
            return true;
        }

        return false;
    }
    
    private function api_sign($obj, $meta)
    {
        $res = clone $obj;
        $res->authTimespan = round((microtime(true) * 1000));
        $res->authTimeZone = self::TIMEZONE_DIFF;
        
        $sign = clone $meta;

        foreach ($res as $key => $val)
            $sign->$key = $val;
            
        $sign = get_object_vars($sign);
        ksort($sign);
        
        $sign_str = $this->config->api_key;
        
        foreach ($sign as $key => $val)
            $sign_str .= $key."=".$val;
            
        $sign_str .= $this->config->api_secret;
        
        $res->authAppkey = $this->config->api_key;
        $res->authSign = md5($sign_str);

        return $res;
    }
    
    private function send_api($path, $obj, $type = "user", $as_json = false, $timeout = 10)
    {
        if (!is_object($obj))
            return null;

        $meta = new \stdClass;
        $meta->country = $this->config->country;
        $meta->lang = $this->config->app_language;
        $meta->deviceId = $this->config->device_id;
        $meta->appCode = $this->config->app_code;
        $meta->appVersion = $this->config->app_version;
        $meta->channel = $this->config->app_channel;
        $meta->deviceType = $this->config->device_type;
            
        if ($type == "main")
        {
            $obj->requestId = md5(round(microtime(true) * 1000));
            
            $options = array(
                CURLOPT_URL => $this->config->api_url_main."/".$path."?".http_build_query($this->api_sign($obj, $meta)),
                CURLOPT_HEADER => 0,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSL_VERIFYHOST => 0
            );
        }
        elseif ($type == "user")
        {
            $obj->todo = $path;
            
            $options = array(
                CURLOPT_POST => 1,
                CURLOPT_URL => $this->config->api_url_user,
                CURLOPT_FRESH_CONNECT => 1,
                CURLOPT_FORBID_REUSE => 1,
                CURLOPT_HEADER => 0,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_POSTFIELDS => http_build_query($obj)
            );
            
            if ($as_json)
            {
                $options[CURLOPT_POSTFIELDS] = json_encode($obj);
                $options[CURLOPT_HTTPHEADER] = array("Content-Type:application/json", "Content-Length:".strlen($options[CURLOPT_POSTFIELDS]));
            }
        }
        else
        {
            return false;
        }  
            
        $ch = curl_init();
            
        curl_setopt_array($ch, $options);            
        $result = curl_exec($ch);
        @curl_close($ch);
            
        if (!$result)
            return null;
            
        return $result;
    }
    
    public static function parse_response($xml, &$indexes = null)
    {
        $struct = null;
        $indexes = null;
        
        $parser = xml_parser_create();
        xml_parse_into_struct($parser, "<parse_response id='".md5(microtime(true))."'>".$xml."</parse_response>", $struct, $indexes);
        xml_parser_free($parser);
        
        return $struct;
    }
    
    public function login(&$error = null)
    {
        $error = null;
        $this->clear_login();
        
        $obj = new \stdClass;
        $obj->account = null;
        $obj->password = null;
        
        $this->encrypt($this->config->account_id, $obj->account);
        $this->encrypt($this->config->password_hash, $obj->password);
        
        $result = $this->send_api("user/login", $obj, "main");
        
        if (!$result)
            return false;
            
        $json = json_decode($result);
        
        if (!is_object($json))
            return false;
            
        if ($json->code == "0000")
        {
            $this->config->login_uid = $json->data->uid;
            $this->config->login_access_token = $json->data->accessToken;
            $this->config->login_username = $json->data->username;
                    
            return $this->config->write($this->config_file);
        }
        elseif ($json->code == "0001")
        {
            $error = "operation failed";
            
            return false;
        }
        elseif ($json->code == "0002")
        {
            $error = "interface authentication failed";
            
            return false;
        }
        elseif ($json->code == "1005")
        {
            $error = "invalid email or password";
            
            return false;
        }
        
        $error = "Unknown error ".$json->code.", chinese said: ".$json->msg;
        
        return false;
    }
    
    public function get_authcode(&$error = null)
    {
        $error = null;
        $this->clear_login();
        
        $obj = new \stdClass;
        $obj->uid = $this->config->login_uid;
        $obj->accessToken = $this->config->login_access_token;
        
        $result = $this->send_api("user/getAuthCode", $obj, "main");
        
        if (!$result)
            return false;
            
        $json = json_decode($result);
        
        if (!is_object($json))
            return false;
        
        if ($json->code == "0000")
        {    
            $this->config->auth_code = $json->data->authCode;
            $this->config->auth_uid = $json->data->ecovacsUid;

            return $this->config->write($this->config_file);
        }
        elseif ($json->code == "0001")
        {
            $error = "operation failed";
            
            return false;
        }
        
        $error = "Unknown error ".$json->code.", chinese said: ".$json->msg;
        
        return false;
    }
    
    public function login_by_it_token(&$error = null)
    {
        $error = null;
        $this->clear_login();
        
        $obj = new \stdClass;
        $obj->country = strtoupper($this->config->country);
        $obj->resource = substr($this->config->device_id, 0, 8);
        $obj->realm = $this->config->api_realm;
        $obj->userId = $this->config->auth_uid;
        $obj->token = $this->config->auth_code;
        
        $result = $this->send_api("loginByItToken", $obj, "user");
        
        if (!$result)
            return false;
            
        $json = json_decode($result);
        
        if (!is_object($json))
        {
            $error = "unexpected response";
            
            return false;
        }
            
        if ($json->result == "ok")
        {
            $this->config->user_uid = $json->userId;            
            $this->config->user_access_token = $json->token;
            
            $this->has_logged_in = true;

            if (!$this->xmpp_client) $this->init_xmpp();
        
            return $this->config->write($this->config_file);    
        }
        elseif ($json->result == "fail")
        {
            $error = "failed with code ".$json->errno.": ".$json->error;

            return false;
        }
        else
        {
            $error = "unexpected result: ".$json->result;
            
            return false;
        }
    }
        
    public function update_device_list(&$error = null)
    {
        $error = null;
        
        $obj = new \stdClass;
        $obj->userid = $this->config->user_uid;
        $obj->auth = new \stdClass;
        $obj->auth->with = "users";
        $obj->auth->userid = $this->config->user_uid;
        $obj->auth->realm = $this->config->api_realm;
        $obj->auth->token = $this->config->user_access_token;
        $obj->auth->resource = substr($this->config->device_id, 0, 8);
        
        $result = $this->send_api("GetDeviceList", $obj, "user", true);
        
        if (!$result)
            return false;
            
        $json = json_decode($result);
        
        if (!is_object($json))
        {
            $error = "unexpected response";
            
            return false;
        }
            
        if ($json->result == "ok")
        {
            $device_list = json_decode(json_encode($json->devices));
            
            if (!is_array($device_list))
            {
                $error = "invalid or empty device list";
                
                return false;
            }
            elseif (count($device_list) == 0)
            {
                $error = "no registered device found";
                
                return false;
            }
            
            $this->device_list = array();            
            $this->has_logged_in = true;
            
            if (!$this->xmpp_client) $this->init_xmpp();
            
            if (!$this->xmpp_client)
            {
                $error = "unable to initialize api server connector";
                
                return false;
            }
            
            foreach ($device_list as $dev)
            {
                $this->device_list[$dev->did] = new Device(
                    $this->xmpp_client, 
                    $this->xmpp_options,
                    $this->config->atom_domain,
                    $dev->did, 
                    $dev->class, 
                    $dev->name,
                    $dev->nick,
                    $dev->company,
                    $dev->resource
                );
            }
            
            return true;
        }
        elseif ($json->result == "fail")
        {
            $error = "failed with code ".$json->errno.": ".$json->error;

            return false;
        }
        else
        {
            $error = "unexpected result: ".$json->result;
            
            return false;
        }
    }
    
    public function try_login(&$error = null)
    {
        $error = null;
        
        if ($this->update_device_list($error))
            return true;
            
        if (!$this->login($error))
            return false;
            
        if (!$this->get_authcode($error))
            return false;
            
        if (!$this->login_by_it_token($error))
            return false;
            
        if (!$this->update_device_list($error))
            return false;
            
        return true;
    }
    
    public function try_connect(&$error = null)
    {
        $error = null;
        
        if (!$this->has_logged_in)
        {
            $error = "Not logged in, yet";
            
            return false;
        }
        
        if ($this->has_connected)
            return true;
            
        $this->xmpp_client->connect();
        
        $result = $this->xmpp_client->getResponse();
        
        if (!$result)
        {
            $error = "Unexpected or empty response";
            
            return false;
        }
        
        $res = self::parse_response($result);
        
        if (!$res)
        {
            $error = "Unable to parse response";
            
            return false;
        }
        
        $jid = null;
        
        foreach ($res as $xml)
        {
            if (($xml["tag"] == "JID") && ($xml["type"] == "complete"))
            {
                $jid = $xml["value"];
                break;
            }
        }
        
        if ($jid == $this->xmpp_options->fullJid())
            $this->has_connected = true;
                    
        if (!$this->has_connected)
            $error = "Authentication failed";
        
        return $this->has_connected;
    }
    
    public function get_device_list(&$indexes = null)
    {
        $indexes = array();
        
        foreach ($this->device_list as $index => $dev)
            $indexes[$index] = $dev->nick;
            
        return $this->device_list;
    }
    
    function __construct($config = ".ecophacs", $username = null, $password = null, $continent = null, $country = null, $device_id = null)
    {
        $this->config_file = $config;
        
        $this->config = new Config($this->config_file);
        
        if ($username)
            $this->config->account_id = $username;
        if ($password)
            $this->config->password_hash = md5($password);
        if ($continent)
            $this->config->continent = $continent;
        if ($country)
            $this->config->country = $country;
        if ($device_id)
            $this->config->device_id = $device_id;
        if (($username) || ($password) || ($continent) || ($country) || ($device_id))
            $this->config->write($config);
    }
}
