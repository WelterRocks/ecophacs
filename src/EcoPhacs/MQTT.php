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

use Mosquitto\Client;
use Mosquitto\Message;
use WelterRocks\EcoPhacs\Config;
use WelterRocks\EcoPhacs\Device;
use WelterRocks\EcoPhacs\Exception;

class MQTT
{
    private $mqtt = null;
    private $config = null;
    private $message = null;
    
    public $connected = null;
    
    private $cache = null;
    private $cachesize = null;
    
    private $config_file = null;
    
    function __construct($config = ".ecophacsrc", $hostname = null, $port = null, $username = null, $password = null, $client_id = null, $topic = null)
    {
        $this->config_file = $config;
        
        $this->config = new Config($this->config_file);
        
        if ($hostname)
            $this->mqtt_hostname = $hostname;
        if ($port)
            $this->mqtt_hostport = $port;
        if ($username)
            $this->mqtt_username = $username;
        if ($password)
            $this->mqtt_password = $password;
        if ($client_id)
            $this->mqtt_client_id = $client_id;
        if ($topic)
            $this->mqtt_topic = $topic;
            
        if (($username) || ($password) || ($hostname) || ($port) || ($client_id) || ($topic))
            $this->config->write($config);
            
        $this->connected = false;
        
        $this->mqtt = new Client($this->config->mqtt_client_id.getmypid());

        $this->mqtt->setCredentials($this->config->mqtt_username, $this->config->mqtt_password);        
        $this->mqtt->setReconnectDelay(10, 60, true);

// Hmm, seems that onConnect is not triggered. For now, we subscribe in connect function                
//        $this->mqtt->onConnect(array($this, 'subscribe'));        
        $this->mqtt->onMessage(array($this, 'message_receive'));
        $this->mqtt->onDisconnect(array($this, 'unsubscribe'));

        $this->flush_cache();
        $this->connect();
    }
    
    public function get_topic($prefix, $device, $suffix)
    {
        $topic = $this->config->mqtt_topic;

        $topic = str_replace("%prefix%", $prefix, $topic);
        $topic = str_replace("%device%", $device, $topic);
        $topic = str_replace("%suffix%", $suffix, $topic);
        
        return $topic;
    }
    
    public function message_send($topic, $msg, $qos = 0, $retain = false)
    {
        return $this->mqtt->publish($topic, $msg, $qos, $retain);
    }
    
    public function message_receive(Message $msg)
    {
        $this->message = null;
        $this->message = new \stdClass;
        
        $this->message->timestamp = microtime(true);
        $this->message->topic = explode("/", $msg->topic);
        
        switch (strtolower($this->message->topic[0]))
        {
            case "tele":
                $this->message->topic_prefix = "telemetry";
                break;
            case "stat":
                $this->message->topic_prefix = "statistic";
                break;
            case "cmnd":
                $this->message->topic_prefix = "command";
                break;
            default:
                $this->message->topic_prefix = "unknown";
                break;
        }
        
        $last = (count($this->message->topic) - 1);
        
        $this->message->cache_id = null;
        $this->message->topic_suffix = $this->message->topic[$last];        
        $this->message->topic_device = $this->message->topic[$last-1];
        
        $topic_raw = array();
        
        for ($i = 1; $i < ($last-1); $i++)
            array_push($topic_raw, $this->message->topic[$i]);
            
        $this->message->topic_raw = implode("/", $topic_raw);
        
        if (!$this->message->cache_id)
            $this->message->cache_id = md5($this->message->topic_raw.$this->message->topic_device);
        
        $this->message->payload = json_decode($msg->payload);
        $this->message->payload_type = ((is_object($this->message->payload)) ? "json" : "string");
    
        if ($this->message->payload == "")
            $this->message->payload = $msg->payload;
            
        $this->message->retain = $msg->retain;
        $this->message->qos = $msg->qos;
        
        $this->update_cache();
    }
    
    private function update_cache()
    {
        if (!isset($this->cache[$this->message->cache_id]))
            $this->cache[$this->message->cache_id] = new \stdClass;
            
        if (isset($this->message->topic_raw))
            $this->cache[$this->message->cache_id]->topic_raw = $this->message->topic_raw;
        if (isset($this->message->topic_device))
            $this->cache[$this->message->cache_id]->topic_device = $this->message->topic_device;
        if (isset($this->message->topic))
            $this->cache[$this->message->cache_id]->topic = $this->message->topic;
                                
        $this->cache[$this->message->cache_id]->retain = $this->message->retain;
        $this->cache[$this->message->cache_id]->qos = $this->message->qos;
        
        if (!isset($this->cache[$this->message->cache_id]->payload))
            $this->cache[$this->message->cache_id]->payload = new \stdClass;
            
        $suffix = $this->message->topic_suffix;
        $prefix = $this->message->topic_prefix;
        
        if (!isset($this->cache[$this->message->cache_id]->payload->$prefix))
            $this->cache[$this->message->cache_id]->payload->$prefix = new \stdClass;

        if (!isset($this->cache[$this->message->cache_id]->payload->$prefix->$suffix))
            $this->cache[$this->message->cache_id]->payload->$prefix->$suffix = new \stdClass;

        $this->cache[$this->message->cache_id]->payload->$prefix->$suffix = $this->message->payload;                
        $this->cache[$this->message->cache_id]->timestamp = time();

        $this->cachesize = count($this->cache);
    }
    
    public function get_cache($cache_id)
    {
        return ((isset($this->cache[$cache_id])) ? $this->cache[$cache_id] : null);
    }
    
    public function flush_cache()
    {
        $this->cache = array();
        $this->cachesize = 0;
    }
    
    public function export_cache()
    {
        return json_encode($this->cache);
    }
    
    public function subscribe()
    {
        $topic = $this->get_topic("cmnd", "+", "+");
        
        $mid = $this->mqtt->subscribe($topic, 2);
        
        if ($mid)
            $this->connected = true;
    }
    
    public function unsubscribe($topic)
    {
        $this->connected = false;
    }
    
    public function loop($timeout = null)
    {
        $this->message = null;
        
        $this->mqtt->loop($timeout);
        
        if (is_object($this->message))
            return $this->message;
        
        return null;
    }
    
    private function connect(&$ex = null)
    {
        try
        {
            // Subscribe after connect, because onConnect doesnt work currently
            $this->mqtt->connect($this->config->mqtt_hostname, $this->config->mqtt_hostport);
            $this->subscribe();
                
            return true;
        }
        catch (Mosquitto\Exception $ex)
        {
            return null;
        }
        catch (\exception $ex)
        {
            return null;
        }
    }
    
    private function disconnect(&$ex = null)
    {
        $this->connected = false;
        
        try
        {
            return $this->mqtt->disconnect();
        }
        catch(Mosquitto\Exception $ex)
        {
            return null;
        }
        catch (\exception $ex)
        {
            return null;
        }
    }
    
    function __destruct()
    {
        $this->disconnect();
    }
}
