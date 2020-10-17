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

final class bootstrap
{
    private $mqtt = null;
    private $config = null;
    private $message = null;
    
    public $connected = null;
    
    private $cache = null;
    private $cachesize = null;
    
    function __construct($config)
    {
        $this->config = $config;
        
        $this->connected = false;
        
        $this->mqtt = new Client($config->mqtt_identify.getmypid());

        $this->mqtt->setCredentials($config->mqtt_username, $config->mqtt_password);        
        $this->mqtt->setReconnectDelay(10, 60, true);
                
        $this->mqtt->onConnect(array($this, 'subscribe'));        
        $this->mqtt->onMessage(array($this, 'message_receive'));
        $this->mqtt->onDisconnect(array($this, 'unsubscribe'));

        $this->flush_cache();
        $this->connect();
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
                $this->message->topic_type = "telemetry";
                break;
            case "stat":
                $this->message->topic_type = "statistic";
                break;
            case "cmnd":
                $this->message->topic_type = "command";
                break;
            default:
                $this->message->topic_type = "unknown";
                break;
        }
        
        $last = (count($this->message->topic) - 1);
        
        $this->message->cache_id = null;
        $this->message->topic_service = $this->message->topic[$last];
        
        if (count($this->message->topic) == 5)
        {
            $this->message->topic_zone = "lv";
            $this->message->topic_level = $this->message->topic[1];
            $this->message->topic_room = $this->message->topic[2];
            $this->message->topic_device = $this->message->topic[3];
            
            unset($this->message->topic);
        }
        elseif (count($this->message->topic) == 6)
        {
            $this->message->topic_zone = $this->message->topic[1];
            $this->message->topic_level = $this->message->topic[2];
            $this->message->topic_room = $this->message->topic[3];
            $this->message->topic_device = $this->message->topic[4];
            
            unset($this->message->topic);
        }
        else
        {
            $this->message->cache_id = md5($this->message->topic);
        }
        
        if (!$this->message->cache_id)
            $this->message->cache_id = md5($this->message->topic_zone.$this->message->topic_level.$this->message->topic_room.$this->message->topic_device);
        
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
            
        if (isset($this->message->topic_zone))
            $this->cache[$this->message->cache_id]->topic_zone = $this->message->topic_zone;
        if (isset($this->message->topic_level))
            $this->cache[$this->message->cache_id]->topic_level = $this->message->topic_level;
        if (isset($this->message->topic_room))
            $this->cache[$this->message->cache_id]->topic_room = $this->message->topic_room;
        if (isset($this->message->topic_device))
            $this->cache[$this->message->cache_id]->topic_device = $this->message->topic_device;
        if (isset($this->message->topic))
            $this->cache[$this->message->cache_id]->topic = $this->message->topic;
                                
        $this->cache[$this->message->cache_id]->retain = $this->message->retain;
        $this->cache[$this->message->cache_id]->qos = $this->message->qos;
        
        if (!isset($this->cache[$this->message->cache_id]->payload))
            $this->cache[$this->message->cache_id]->payload = new \stdClass;
            
        $service = $this->message->topic_service;
        $type = $this->message->topic_type;
        
        if (!isset($this->cache[$this->message->cache_id]->payload->$type))
            $this->cache[$this->message->cache_id]->payload->$type = new \stdClass;

        if (!isset($this->cache[$this->message->cache_id]->payload->$type->$service))
            $this->cache[$this->message->cache_id]->payload->$type->$service = new \stdClass;

        $this->cache[$this->message->cache_id]->payload->$type->$service = $this->message->payload;                
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
        $mid = $this->mqtt->subscribe("#", 2);
        
        if ($mid)
            $this->connected = true;
    }
    
    public function unsubscribe()
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
    
    private function connect()
    {
        return $this->mqtt->connect($this->config->mqtt_hostname, $this->config->mqtt_port);
    }
    
    private function disconnect()
    {
        return $this->mqtt->disconnect();
    }
    
    function __destruct()
    {
        $this->disconnect();
    }
}
