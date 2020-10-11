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

final class config
{
    private $api_key = 'eJUWrzRv34qFSaYk';
    private $api_secret = 'Cyu5jcR4zyK6QEPn1hdIGXB5QIDAQABMA0GC';

    private $api_realm = 'ecouser.net';

    private $api_url_main = 'https://eco-%country%-api.ecovacs.com/v1/private/%country%/%app_language%/%device_id%/%app_code%/%app_version%/%app_channel%/%device_type%';
    private $api_url_user = 'https://users-%continent%.ecouser.net:8000/user.do';
    
    private $xmpp_hostname = 'msg-%continent%.ecouser.net';
    private $xmpp_port = '5223';
    private $xmpp_use_tls = '1';
    
    private $atom_domain = 'ecorobot.net';

    private $public_key = 'MIIB/TCCAWYCCQDJ7TMYJFzqYDANBgkqhkiG9w0BAQUFADBCMQswCQYDVQQGEwJjbjEVMBMGA1UEBwwMRGVmYXVsdCBDaXR5MRwwGgYDVQQKDBNEZWZhdWx0IENvbXBhbnkgTHRkMCAXDTE3MDUwOTA1MTkxMFoYDzIxMTcwNDE1MDUxOTEwWjBCMQswCQYDVQQGEwJjbjEVMBMGA1UEBwwMRGVmYXVsdCBDaXR5MRwwGgYDVQQKDBNEZWZhdWx0IENvbXBhbnkgTHRkMIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDb8V0OYUGP3Fs63E1gJzJh+7iqeymjFUKJUqSD60nhWReZ+Fg3tZvKKqgNcgl7EGXp1yNifJKUNC/SedFG1IJRh5hBeDMGq0m0RQYDpf9l0umqYURpJ5fmfvH/gjfHe3Eg/NTLm7QEa0a0Il2t3Cyu5jcR4zyK6QEPn1hdIGXB5QIDAQABMA0GCSqGSIb3DQEBBQUAA4GBANhIMT0+IyJa9SU8AEyaWZZmT2KEYrjakuadOvlkn3vFdhpvNpnnXiL+cyWy2oU1Q9MAdCTiOPfXmAQt8zIvP2JC8j6yRTcxJCvBwORDyv/uBtXFxBPEC6MDfzU2gKAaHeeJUWrzRv34qFSaYkYta8canK+PSInylQTjJK9VqmjQ';
    
    private $app_code = 'i_eco_e';
    private $app_version = '1.3.5';
    private $app_channel = 'c_googleplay';
    private $app_language = 'en';    

    private $device_type = 1;
    private $device_id = null;

    private $country = null;
    private $continent = null;
    
    private $account_id = null;
    private $password_hash = null;

    private $login_uid = null;    
    private $login_access_token = null;
    private $login_username = null;
    
    private $auth_code = null;
    private $auth_uid = null;

    private $user_uid = null;    
    private $user_access_token = null;
    
    private $dynamic_keys = null;
    
    public function write($config_file = ".ecophacs")
    {
        $fd = @fopen($config_file, "w");
        
        if (!is_resource($fd))
            return false;
            
        foreach ($this->dynamic_keys as $key)
        {
            if ($key == "password_hash")
                $val = base64_encode("\$PW\$".$this->$key);
            else
                $val = $this->$key;
                
            $write = $key."=".$val."\n";
                
            @fwrite($fd, $write);
        }
        
        @fwrite($fd, "timestamp=".time()."\n");        
        @fclose($fd);
        
        return true;
    }
    
    function __construct($config_file = ".ecophacs")
    {
        $this->dynamic_keys = array("device_id", "continent", "country", "account_id", "password_hash", "login_access_token", "login_uid", "login_username", "auth_code", "auth_uid", "user_uid", "user_access_token");
        
        if (file_exists($config_file))
        {
            $ini = json_decode(json_encode(parse_ini_file($config_file)));
            
            if (is_object($ini))
            {
                foreach ($this->dynamic_keys as $key)
                {
                    if (isset($ini->$key))
                    {
                        if ($key == "password_hash")
                        {
                            if (substr(base64_decode($ini->$key), 0, 4) == "\$PW\$")
                                $this->password_hash = substr(base64_decode($ini->$key), 4);
                            else
                                $this->password_hash = md5($ini->$key);
                                
                            continue;
                        }
                        
                        $this->$key = $ini->$key;
                    }
                }
            }
        }
    }

    function __set($key, $val)
    {
        if (in_array($key, $this->dynamic_keys))
            $this->$key = $val;
        
        return;
    }
    
    function __get($key)
    {
        switch ($key)
        {
            case "api_url_main":
            case "api_url_user":
            case "xmpp_hostname":
                $rpl = array("country", "app_language", "device_id", "app_code", "app_version", "app_channel", "device_type", "continent");
                $val = $this->$key;
                
                foreach ($rpl as $r)
                {
                    $val = str_replace("%".$r."%", ((isset($this->$r)) ? $this->$r : ""), $val);
                }
                
                return $val;
            case "public_key":
                $val = "----- BEGIN CERTIFICATE -----\n";
                $val .= chunk_split(implode("", explode("\n", $this->$key)), 64);
                $val .= "----- END CERTIFICATE -----\n";
                
                return $val;
            default:
                return ((isset($this->$key)) ? $this->$key : null);
        }
    }
}
