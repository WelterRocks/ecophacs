#!/usr/bin/php
<?php require __DIR__ . '/../vendor/autoload.php';

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

// Initialize autoloading
use WelterRocks\EcoPhacs\Client;
use WelterRocks\EcoPhacs\Device;

// Check, whether we are on cli
if ((!isset($argc)) || (!isset($argv)) && (!is_array($argv)))
{
    echo "Not on CLI. Exitting\n";
    exit(1);
}

// Try to load the config file
define("HOME_DIR", getenv("HOME"));
define("CONF_DIR", "/etc/ecophacs");

if ((is_dir(CONF_DIR)) && (file_exists(CONF_DIR."/ecophacs.conf")))
    $config_file = CONF_DIR."/ecophacs.conf";
else
    $config_file = HOME_DIR."/.ecophacsrc";

if ($config_file == HOME_DIR."/.ecophacsrc")
{
    if ((!is_dir(HOME_DIR)) || (!file_exists(HOME_DIR."/.ecophacsrc")))
        define("CONFIG_MODE", true);
}
    
if ((defined("CONFIG_MODE")) || (strstr(implode("", file($config_file)), "put-your@email.here")))
{
    if (!is_dir(HOME_DIR))
    {
        echo "Missing home directory '".HOME_DIR."'\n";
        exit(1);
    }
    
    $fd = @fopen($config_file, "w");
    
    if (!is_resource($fd))
    {
        echo "Unable to open configuration file '".$config_file."'\n";
        exit(2);
    }
    
    @fwrite($fd, "device_id=".md5(microtime(true))."\n");
    @fwrite($fd, "continent=eu\n");
    @fwrite($fd, "country=de\n");
    @fwrite($fd, "account_id=put-your@email.here\n");
    @fwrite($fd, "password_hash=YOUR-CLEARTEXT-PASSWORD-HERE\n");
    @fclose($fd);
    
    echo "A configuration file has been written to '".$config_file."'.\n";
    echo "A device_id has been generated for you and you only\n";
    echo "have to change account_id and password_hash to your needs.\n";
    echo "Dont be confused about 'password_hash'. You must insert your\n";
    echo "password in cleartext. It is automatically hashed and encoded\n";
    echo "after the first use. If you are not within germany and/or EU\n";
    echo "you have to adapt the continent and country codes to your needs.\n";
    echo "If you would like to have a global configuration file, move this\n";
    echo "file to /etc/ecophacs/ecophacs.conf after changing its contents.\n";
    
    exit(3);
}

// Create the client (main) object
$ecovacs = new Client($config_file);

// Initialize error string store
$error = null;

// Initialize command string store
$cmnd = null;

// Initialize device string stores
$device_id = null;
$device_name = null;
$device_power = Device::VACUUM_POWER_STANDARD;
$device_state = null;

// Initialize flags
$flag_output_as_json = false;

// Get command line arguments
for ($i = 1; $i < $argc; $i++)
{
    switch ($argv[$i])
    {
        case "-h":
        case "--help":
            echo "Hey, I am an ALPHA version....\n";
            echo "...so nobody can help you, now.\n";
            exit;
        case "-l":
        case "--list-devices":
            $cmnd = "devicelist";
            break;
        case "-d":
        case "--device-id":
            if (!isset($argv[$i+1]))
            {
                echo "Missing device ID after ".$argv[$i]."\n";
                exit(8);
            }
            
            $device_id = strtoupper($argv[$i+1]);
            $i++;
            break;
        case "-D":
        case "--device-name":
            if (!isset($argv[$i+1]))
            {
                echo "Missing device name after ".$argv[$i]."\n";
                exit(8);
            }
            
            $device_name = strtoupper($argv[$i+1]);
            $i++;
            break;
        case "-T":
        case "--set-time":
            $cmnd = "settime";
            break;
        case "-S":
        case "--status":
            $cmnd = "status";
            break;
        case "-c":
        case "--charge":
            $cmnd = "charge";
            break;        
        case "-a":
        case "--auto":
            $cmnd = "auto";
            break;        
        case "-s":
        case "--stop":
            $cmnd = "stop";
            break;        
        case "-b":
        case "--border":
            $cmnd = "border";
            break;        
        case "-P":
        case "--spot":
            $cmnd = "spot";
            break;        
        case "-A":
        case "--single-room":
            $cmnd = "singleroom";
            break;        
        case "-C":
        case "--locate":
            $cmnd = "playsound";
            break;        
        case "-H":
        case "--halt":
            $cmnd = "halt";
            break;        
        case "-F":
        case "--forward":
            $cmnd = "forward";
            break;        
        case "-L":
        case "--left":
            $cmnd = "left";
            break;        
        case "-R":
        case "--right":
            $cmnd = "right";
            break;        
        case "-T":
        case "--turn-around":
            $cmnd = "around";
            break;        
        case "-p":
        case "--power":
            if (!isset($argv[$i+1]))
            {
                echo "Missing device power (standard|strong) after ".$argv[$i]."\n";
                exit(8);
            }
                        
            if (($argv[$i+1] != "standard") && ($argv[$i+1] != "strong"))
            {
                echo "Only standard or strong is accepted for ".$argv[$i]."\n";
                exit(8);
            }
            
            switch (strtolower($argv[$i+1]))
            {
                case "standard":
                    $device_power = false;
                    break;
                case "strong":
                    $device_power = true;
                    break;
            }
            $i++;
            break;
        case "-j":
        case "--json-output":
            $flag_output_as_json = true;
            break;
    }
}

// Check, whether there is a command
if (!$cmnd)
{
    echo "Usage: ".$argv[0]." --help\n";
    echo "-----> NO, better not.\n";
    exit;
}

// Try to login, if not yet done, otherwise fetch device list
if (!$ecovacs->try_login($error))
{
    echo "Unable to login: ".$error."\n";
    exit(4);
}

// Try to connect to API server
if (!$ecovacs->try_connect($error))
{
    echo "Unable to connect to server.\nERROR: ".$error."\n";
    exit(5);
}
    
// Get device list and indexes by device id => device name and do the magic
$indexes = null; 
$devices = $ecovacs->get_device_list($indexes);

if ($cmnd == "devicelist")
{
    if (count($indexes) == 0)
    {
        echo "There are currently no registered devices.\n";
        exit(6);
    }
    else
    {
        echo "I found ".count($indexes)." registered devices:\n\n";
        echo "(Device-ID => Device-Name)\n\n";
        
        foreach ($indexes as $id => $name)
        {
            echo " * ".$id." => ".$name."\n";
        }
        
        echo "\n";
        exit;
    }
}
else
{
    $device = null;
    
    if (($device_id) && ($device_name))
    {
        echo "Do not use device ID and device name at the same time\n";
        exit(10);
    }
    elseif ($device_id)
    {
        if (!isset($devices[$device_id]))
        {
            echo "Unknown device id '".$device_id."'\n";
            exit(9);
        }
        
        $device = $devices[$device_id];
    }
    elseif ($device_name)
    {
        foreach ($indexes as $id => $name)
        {
            if (strtoupper($name) == $device_name)
            {
                $device = $devices[$id];
                break;
            }
        }
        
        if (!$device)
        {
            echo "Unkown device name '".$device_name."'\n";
            exit(11);
        }
    }
    
    if (!$device->ping())
    {
        echo $device->nick." is currently unavailable\n";
        exit(12);
    }
    
    if ($cmnd == "status")
    {
        if (!$flag_output_as_json) echo "Requesting status of ".$device->nick."...";
        $device->get_clean_state();
        $device->get_battery_info();
        $device->get_charge_state();
        
        $device->get_lifespan(Device::COMPONENT_BRUSH);
        $device->get_lifespan(Device::COMPONENT_SIDE_BRUSH);
        $device->get_lifespan(Device::COMPONENT_DUST_CASE_HEAP);
        if (!$flag_output_as_json) echo "done\n\n";
        
        if ($flag_output_as_json)
        {
            echo $device->to_json()."\n";
        }
        else
        {
            echo $device->nick." => ".$device->did."\n\n";
            echo " * Company: ".$device->company."\n";
            echo " * Cleaning-Mode: ".$device->status_cleaning_mode."\n";
            echo " * Vacuum-Power: ".$device->status_vacuum_power."\n";
            echo " * Battery-Power: ".$device->status_battery_power."%\n";
            echo " * Charging-State: ".$device->status_charging_state."\n";
            echo " * Lifespan of brush: ".$device->status_lifespan_brush->percent."%\n";
            echo " * Lifespan of side brush: ".$device->status_lifespan_side_brush->percent."%\n";
            echo " * Lifespan of dust case heap: ".$device->status_lifespan_dust_case_heap->percent."%\n";
            
            echo "\n";
        }
        exit;
    }
    elseif ($cmnd == "settime")
    {
        echo "Setting time for ".$device->nick."...";
        
        if ($device->set_time())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(13);
        }
    }
    elseif ($cmnd == "charge")
    {
        echo "Sending ".$device->nick." to charger...";
        
        if ($device->charge())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(14);
        }
    }
    elseif ($cmnd == "auto")
    {
        echo $device->nick." starts cleaning in auto mode, with ".(($device_power) ? "strong" : "standard")." power...";
        
        if ($device->auto($device_power))
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "stop")
    {
        echo $device->nick." stopps cleaning...";
        
        if ($device->stop())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "border")
    {
        echo $device->nick." is cleaning in border mode, with ".(($device_power) ? "strong" : "standard")." power...";
        
        if ($device->border($device_power))
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "spot")
    {
        echo $device->nick." is cleaning in spot mode, with ".(($device_power) ? "strong" : "standard")." power...";
        
        if ($device->spot($device_power))
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "singleroom")
    {
        echo $device->nick." is cleaning in single room mode, with ".(($device_power) ? "strong" : "standard")." power...";
        
        if ($device->singleroom($device_power))
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "playsound")
    {
        echo "Locating ".$device->nick."...";
        
        if ($device->playsound())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "halt")
    {
        echo "Halting ".$device->nick."...";
        
        if ($device->halt())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "left")
    {
        echo "Turning ".$device->nick." left...";
        
        if ($device->left())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "right")
    {
        echo "Turning ".$device->nick." right...";
        
        if ($device->right())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "around")
    {
        echo "Turning ".$device->nick." around...";
        
        if ($device->turn_around())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
    elseif ($cmnd == "forward")
    {
        echo "Driving ".$device->nick." to forward direction...";
        
        if ($device->forward())
        {
            echo "done\n";
            exit;
        }
        else
        {
            echo "failed\n";
            exit(15);
        }
    }
}
