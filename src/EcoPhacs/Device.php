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

use Norgul\Xmpp\XmppClient;
use Norgul\Xmpp\Options;
use WelterRocks\EcoPhacs\Client;

class Device
{
    private $did = null;
    private $class = null;
    private $name = null;
    private $nick = null;
    private $company = null;
    private $resource = null;

    private $full_jid = null;
    private $bare_jid = null;
    
    private $xmpp_client = null;
    private $xmpp_options = null;
    
    public $is_available = null;
    
    public $last_ping_request = null;
    public $last_ping_response = null;
    public $last_ping_roundtrip = null;
    
    public $last_clean_state = null;
    public $last_charge_state = null;
    public $last_battery_msg = null;
    
    public $last_lifespan_brush = null;
    public $last_lifespan_side_brush = null;
    public $last_lifespan_dust_case_heap = null;
    
    public $status_battery_power = null;
    public $status_cleaning_mode = null;
    public $status_vacuum_power = null;
    public $status_charging_state = null;
    
    public $status_report_clean = null;
    public $status_report_charge = null;
    
    public $status_lifespan_brush = null;
    public $status_lifespan_side_brush = null;
    public $status_lifespan_dust_case_heap = null;
        
    public const CLEANING_MODE_AUTO = 'auto';
    public const CLEANING_MODE_BORDER = 'border';
    public const CLEANING_MODE_SPOT = 'spot';
    public const CLEANING_MODE_STOP = 'stop';
    public const CLEANING_MODE_SINGLEROOM = 'singleroom';
    
    public const VACUUM_POWER_STANDARD = 'standard';
    public const VACUUM_POWER_STRONG = 'strong';
    
    public const VACUUM_STATUS_OFFLINE = 'offline';

    public const CHARGING_MODE_GO = 'go';
    
    public const CHARGING_STATE_GOING = 'Going';
    public const CHARGING_STATE_CHARGING = 'SlotCharging';
    public const CHARGING_STATE_IDLE = 'Idle';
    
    public const ACTION_MOVE_FORWARD = 'forward';
    
    public const ACTION_SPIN_LEFT = 'SpinLeft';
    public const ACTION_SPIN_RIGHT = 'SpinRight';
    
    public const ACTION_STOP = 'stop';
    public const ACTION_TURN_AROUND = 'TurnAround';
    
    public const COMPONENT_SIDE_BRUSH = 'SideBrush';
    public const COMPONENT_BRUSH = 'Brush';
    public const COMPONENT_DUST_CASE_HEAP = 'DustCaseHeap';
    
    public const DEFAULT_TIMEZONE = 'GMT+2';
    
    private static function parse_response($response, &$indexes = null)
    {
        if (!$response)
            return null;
            
        $res = Client::parse_response($response, $indexes);
        
        return $res;
    }
    
    private function iq_complete_result($result)
    {
        if (!is_array($result))
            return null;
            
        $retval = null;
        
        foreach ($result as $xml)
        {
            if (($xml["tag"] == "IQ") && ($xml["type"] == "complete") && (isset($xml["attributes"])))
            {
                $attr = $xml["attributes"];
                
                if (($attr["TO"] == $this->xmpp_options->fullJid()) && ($attr["FROM"] == $this->full_jid))
                {
                    if ($attr["TYPE"] == "result")
                    {
                        return true;
                    }
                    else
                    {
                        $retval = false;
                    }
                }
            }
        }
        
        return $retval;
    }
    
    private function register_states($result, $indexes)
    {
        if (!$result)
            return null;
            
        if (!$indexes)
            return null;
            
        $n = 0;
            
        if ((isset($indexes["BATTERY"])) && (isset($indexes["BATTERY"][0])))
        {
            $index = $indexes["BATTERY"][0];
            
            if (isset($result[$index]["attributes"]))
            {
                if (isset($result[$index]["attributes"]["POWER"]))
                {
                    $this->last_battery_msg = round(microtime(true) * 1000);            
                    $this->status_battery_power = (double)$result[$index]["attributes"]["POWER"];
                    
                    $n++;
                }
            }
        }
        
        if ((isset($indexes["CHARGE"])) && (isset($indexes["CHARGE"][0])))
        {
            $index = $indexes["CHARGE"][0];                
               
            if (isset($result[$index]["attributes"]))
            {
                $charge = $result[$index]["attributes"];
                    
                $this->last_charge_state = round(microtime(true) * 1000);
                $this->status_charging_state = $charge["TYPE"];
                
                unset($charge["TYPE"]);
                
                $this->status_report_charge = json_decode(json_encode($charge));
                $n++;
            }
        }
            
        if ((isset($indexes["CLEAN"])) && (isset($indexes["CLEAN"][0])))
        {
            $index = $indexes["CLEAN"][0];                
             
            if (isset($result[$index]["attributes"]))
            {
                $clean = $result[$index]["attributes"];
                 
                $this->last_clean_state = round(microtime(true) * 1000);
                $this->status_cleaning_mode = $clean["TYPE"];
                $this->status_vacuum_power = $clean["SPEED"];
                
                unset($clean["TYPE"]);
                unset($clean["SPEED"]);
                
                $this->status_report_clean = json_decode(json_encode($clean));
                $n++;
            }
        }    
        
        if ($n > 0)
            return true;
            
        return false;
    }
    
    private function get_parsed_response(&$raw_response = null, &$indexes = null)
    {
        $indexes = null;
        
        $raw_response = $this->xmpp_client->getResponse();
        $result = self::parse_response($raw_response, $indexes);
        
        return $result;    
    }
        
    public function playsound($sid = 0, $act = null)
    {
        $com = "<query sid='{$sid}'".(($act) ? " act='{$act}'" : "")."/>";
        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "PlaySound", $com);
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $playsound_state = $this->iq_complete_result($result);
            
        if ($playsound_state)
            $this->register_states($result, $indexes);
        
        return $playsound_state;
    }
    
    public function move($action = self::ACTION_MOVE_FORWARD, $act = null)
    {
        $com = null;
        
        switch ($action)
        {
            case self::ACTION_MOVE_FORWARD:
            case self::ACTION_SPIN_LEFT:
            case self::ACTION_SPIN_RIGHT:
            case self::ACTION_STOP:
            case self::ACTION_TURN_AROUND:
                $com = "<move action='{$action}'".(($act) ? " act='{$act}'" : "")."/>";
                break;
            default:
                return false;
        }
        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "Move", $com);
                                
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $move_state = $this->iq_complete_result($result);

        if ($move_state)
            $this->register_states($result, $indexes);
        
        return $move_state;
    }
    
    public function charge($act = null)
    {
        $com = "<charge type='".self::CHARGING_MODE_GO."'".(($act) ? " act='{$act}'" : "")."/>";
        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "Charge", $com);
                        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);
        
        if (!$result)
            return null;

        $charge_state = $this->iq_complete_result($result);
        
        if ($charge_state)
            $this->register_states($result, $indexes);
        
        return $charge_state;
    }
    
    public function clean($mode = self::CLEANING_MODE_AUTO, $speed = self::VACUUM_POWER_STANDARD, $act = null)
    {
        $com = null;
        
        switch ($mode)
        {
            case self::CLEANING_MODE_AUTO:
            case self::CLEANING_MODE_BORDER:
            case self::CLEANING_MODE_SINGLEROOM:
            case self::CLEANING_MODE_SPOT:
            case self::CLEANING_MODE_STOP:
                $com = "<clean type='{$mode}' speed='{$speed}'".(($act) ? " act='{$act}'" : "")."/>";
                break;
            default:
                return false;
        }

        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "Clean", $com);
                
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $clean_state = $this->iq_complete_result($result);
        
        if ($clean_state)
            $this->register_states($result, $indexes);

        return $clean_state;
    }
    
    public function forward()
    {
        return $this->move(self::ACTION_MOVE_FORWARD);
    }
    
    public function left()
    {
        return $this->move(self::ACTION_SPIN_LEFT);
    }
    
    public function spin_left()
    {
        return $this->left();
    }
    
    public function right()
    {
        return $this->move(self::ACTION_SPIN_RIGHT);
    }
    
    public function spin_right()
    {
        return $this->right();
    }
    
    public function halt()
    {
        return $this->move(self::ACTION_STOP);
    }
    
    public function turn()
    {
        return $this->move(self::ACTION_TURN_AROUND);
    }
    
    public function turn_around()
    {
        return $this->turn();
    }
    
    public function auto($strong = false)
    {
        return $this->clean(self::CLEANING_MODE_AUTO, (($strong) ? self::VACUUM_POWER_STRONG : self::VACUUM_POWER_STANDARD));
    }
        
    public function border($strong = false)
    {
        return $this->clean(self::CLEANING_MODE_BORDER, (($strong) ? self::VACUUM_POWER_STRONG : self::VACUUM_POWER_STANDARD));
    }
    
    public function edge($strong = false)
    {
        return $this->border($strong);
    }
        
    public function singleroom($strong = false)
    {
        return $this->clean(self::CLEANING_MODE_SINGLEROOM, (($strong) ? self::VACUUM_POWER_STRONG : self::VACUUM_POWER_STANDARD));
    }
    
    public function single_room($strong = false)
    {
        return $this->singleroom($strong);
    }
    
    public function spot($strong = false)
    {
        return $this->clean(self::CLEANING_MODE_SPOT, (($strong) ? self::VACUUM_POWER_STRONG : self::VACUUM_POWER_STANDARD));
    }
    
    public function circle($strong = false)
    {
        return $this->spot($strong);
    }
    
    public function stop($strong = false)
    {
        return $this->clean(self::CLEANING_MODE_STOP, (($strong) ? self::VACUUM_POWER_STRONG : self::VACUUM_POWER_STANDARD));
    }
    
    public function get_clean_state(&$raw_response = null)
    {        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "GetCleanState");
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $clean_state = $this->iq_complete_result($result);
        
        if ($clean_state)
            $this->register_states($result, $indexes);

        return $clean_state;
    }
    
    public function get_battery_info()
    {        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "GetBatteryInfo");
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $battery_state = $this->iq_complete_result($result);
            
        if ($battery_state)
            $this->register_states($result, $indexes);
            
        return $battery_state;
    }
    
    public function get_charge_state()
    {        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "GetChargeState");
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $charge_state = $this->iq_complete_result($result);
        
        if ($charge_state)
            $this->register_states($result, $indexes);

        return $charge_state;
   }
    
    public function get_lifespan($component = self::COMPONENT_BRUSH)
    {   
        switch ($component)
        {
            case self::COMPONENT_BRUSH:
            case self::COMPONENT_SIDE_BRUSH:
            case self::COMPONENT_DUST_CASE_HEAP:
                $com = "<query type='{$component}'/>";
                break;
            default:
                return false;
        }
        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "GetLifeSpan", $com);
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
                        
        $lifespan = $this->iq_complete_result($result);
        
        if ($lifespan)
        {
            $this->register_states($result, $indexes);
            
            if ((isset($indexes["CTL"])) && (isset($indexes["CTL"][0])))
            {
                $index = $indexes["CTL"][0];
                
                if (isset($result[$index]["attributes"]))
                {                
                    $attr = $result[$index]["attributes"];
                    
                    switch ($attr["TYPE"])
                    {
                        case self::COMPONENT_BRUSH:
                            $this->last_lifespan_brush = round(microtime(true) * 1000);
                            
                            $this->status_lifespan_brush = new \stdClass;
                            $this->status_lifespan_brush->total = (double)$attr["TOTAL"];
                            $this->status_lifespan_brush->value = (double)$attr["VAL"];
                            $this->status_lifespan_brush->percent = round((100 / $this->status_lifespan_brush->total * $this->status_lifespan_brush->value));
                            break;
                        case self::COMPONENT_SIDE_BRUSH:
                            $this->last_lifespan_side_brush = round(microtime(true) * 1000);
                            
                            $this->status_lifespan_side_brush = new \stdClass;
                            $this->status_lifespan_side_brush->total = (double)$attr["TOTAL"];
                            $this->status_lifespan_side_brush->value = (double)$attr["VAL"];
                            $this->status_lifespan_side_brush->percent = round((100 / $this->status_lifespan_side_brush->total * $this->status_lifespan_side_brush->value));
                            break;
                        case self::COMPONENT_DUST_CASE_HEAP:
                            $this->last_lifespan_dust_case_heap = round(microtime(true) * 1000);
                            
                            $this->status_lifespan_dust_case_heap = new \stdClass;
                            $this->status_lifespan_dust_case_heap->total = (double)$attr["TOTAL"];
                            $this->status_lifespan_dust_case_heap->value = (double)$attr["VAL"];
                            $this->status_lifespan_dust_case_heap->percent = round((100 / $this->status_lifespan_dust_case_heap->total * $this->status_lifespan_dust_case_heap->value));
                            break;
                        default:
                            break;
                    }    
                }
            }
        }

        return $lifespan;
    }
    
    public function set_time($timestamp = null, $timezone = self::DEFAULT_TIMEZONE)
    {   
        if (!$timestamp)
            $timestamp = round(microtime(true) * 1000);
            
        $com = "<time t='{$timestamp}' tz='{$timezone}'/>";
        
        $this->xmpp_client->iq->command($this->xmpp_options->fullJid(), $this->full_jid, "SetTime", $com);
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);

        if (!$result)
            return null;
            
        $set_time = $this->iq_complete_result($result);

        if ($set_time)
            $this->register_states($result, $indexes);            
            
        return $set_time;
    }
    
    public function ping(&$raw_response = null)
    {
        $this->last_ping_request = round(microtime(true) * 1000);
        $this->xmpp_client->iq->pingTo($this->xmpp_options->fullJid(), $this->full_jid);
        
        $indexes = null;
        $raw_response = null;
        
        $result = $this->get_parsed_response($raw_response, $indexes);
        $this->last_ping_response = round(microtime(true) * 1000);
        
        $this->is_available = null;
        $this->last_ping_roundtrip = ($this->last_ping_response - $this->last_ping_request);
                
        if (!$result)
            return null;
            
        $this->is_available = $this->iq_complete_result($result);
        
        if ($this->is_available)
            $this->register_states($result, $indexes);
        
        return $this->is_available;
    }
    
    public function to_json()
    {
        $obj = json_decode(json_encode($this));
        
        $obj->serial = $this->did;
        $obj->name = $this->name;
        $obj->nick = $this->nick;
        $obj->class = $this->class;
        $obj->bare_jid = $this->bare_jid;
        $obj->full_jid = $this->full_jid;
        $obj->user_jid = $this->xmpp_options->fullJid();
        $obj->company = $this->company;
        
        return json_encode($obj);
    }
    
    function __get($key)
    {
        if (!isset($this->$key))
            return null;
            
        if (substr($key, 0, 5) == "xmpp_")
            return null;
            
        return $this->$key;
    }
        
    function __construct(XmppClient $xmpp_client, Options $xmpp_options, $atom_domain, $did, $class, $name, $nick, $company, $resource)
    {
        $this->did = $did;
        $this->class = $class;
        $this->name = $name;
        $this->nick = $nick;
        $this->company = $company;
        $this->resource = $resource;

        $this->bare_jid = $did."@".$class.".".$atom_domain;
        $this->full_jid = $this->bare_jid."/".$resource;
        
        $this->xmpp_client = $xmpp_client;
        $this->xmpp_options = $xmpp_options;
    }
}
