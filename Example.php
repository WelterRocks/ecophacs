<?php require __DIR__ . '/vendor/autoload.php';

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
use WelterROcks\EcoPhacs\Device;

/* Basic configuration (not needed, if values already stored in $ecovacs_config_file) *
 * Uncomment the values, you need to (re)store in $ecovacs_config_file                *
 **************************************************************************************/
 
// Ecovacs account (email address)
//$ecovacs_account_id 	= "email@address.here";

// Ecovacs account password (automatically scrambled in config file)
//$ecovacs_password 	= "SuPerSecUreP@ssw0rd";

// Location for Ecovacs API (two letter code, lowercase)
//$ecovacs_continent	= "eu";
//$ecovacs_country	= "de";

// Device ID (randomly generated, 32 chars)
//$ecovacs_device_id	= md5(microtime(true));

// The location of configuration store (mandatory)
$ecovacs_config_file	= ".ecophacs-conf";

// Create the client (main) object
$ecovacs = new Client(
    $ecovacs_config_file,
    ((isset($ecovacs_account_id)) ? $ecovacs_account_id : null),
    ((isset($ecovacs_password)) ? $ecovacs_password : null),
    ((isset($ecovacs_continent)) ? $ecovacs_continent : null),
    ((isset($ecovacs_country)) ? $ecovacs_country : null),
    ((isset($ecovacs_device_id)) ? $ecovacs_device_id : null)
);

// Initialize error string store
$error = null;

// Try to login, if not yet done, otherwise fetch device list
echo "Checking, whether we are logged in or not => ";
if (!$ecovacs->try_login($error))
    die("nope :-(\nERROR: ".$error."\n");
else
    echo "yes, we are :-)\n";

// Try to connect to API server
echo "Checking, whether we are connected to API or not => ";
if (!$ecovacs->try_connect($error))
    die("nope :-(\nERROR: ".$error."\n");
else
    echo "yes, we are :-)\n";
    
// Get device list and indexes by device id => device name
echo "Getting a list of available devices...";
$indexes = null; 
$devices = $ecovacs->get_device_list($indexes);

if (count($indexes) == 0)
{
    echo "none found :-(\n";
    die("Nothing to do, exit!\n");
}
else
{
    echo "found ".count($indexes)."\n";
    echo "=================== HERE WE GO ===================\n";
}

// Ping the first device and you will see ---> nothing :-/
foreach ($devices as $did => $dev)
{
    echo "Pinging to ".$dev->nick."...";

    if ($ping = $dev->ping())
        echo "done (".$dev->last_ping_roundtrip." ms)\n";
    else
        echo "failed\n";
    
    // Bot is online, check states    
    if ($ping)
    {
        echo "- Getting clean status from ".$dev->nick."...";
        
        if ($clean_state = $dev->get_clean_state())
            echo "done (status: ".$dev->status_cleaning_mode.", power: ".$dev->status_vaccum_power.")\n";
        else
            echo "failed\n";

        echo "- Getting battery information from ".$dev->nick."...";
        
        if ($battery_info = $dev->get_battery_info())
            echo "done (power: ".$dev->status_battery_power."%)\n";
        else
            echo "failed\n";

        echo "- Getting charge state from ".$dev->nick."...";
        
        if ($charge_state = $dev->get_charge_state())
            echo "done (state: ".$dev->status_charging_state.")\n";
        else
            echo "failed\n";

        echo "- Getting life span of brush from ".$dev->nick."...";
        
        if ($lifespan_brush = $dev->get_lifespan(Device::COMPONENT_BRUSH))
            echo "done (lifespan: ".$dev->status_lifespan_brush->percent."%)\n";
        else
            echo "failed\n";

        echo "- Getting life span of side brush from ".$dev->nick."...";
        
        if ($lifespan_brush = $dev->get_lifespan(Device::COMPONENT_SIDE_BRUSH))
            echo "done (lifespan: ".$dev->status_lifespan_side_brush->percent."%)\n";
        else
            echo "failed\n";

        echo "- Getting life span of dust case heap from ".$dev->nick."...";
        
        if ($lifespan_brush = $dev->get_lifespan(Device::COMPONENT_DUST_CASE_HEAP))
            echo "done (lifespan: ".$dev->status_lifespan_dust_case_heap->percent."%)\n";
        else
            echo "failed\n";

        echo "- Setting time for ".$dev->nick."...";
        
        if ($timeset = $dev->set_time())
            echo "done\n";
        else
            echo "failed\n";

        echo "- Auto clean with normal power for 15 secs. with ".$dev->nick."...";
        
        if ($dev->auto())
        {
            sleep(15);
            
            echo "stopping...";
            
            if ($dev->stop())
                echo "done\n";
            else
                die("FAILED!! Stop it manually. Program aborted.\n");
                
            sleep(5);
        }
        else
        {
            die("FAILED!! Unable to control the bot. Program aborted.\n");
        }

        echo "- Auto clean with high power for 15 secs. with ".$dev->nick."...";
        
        if ($dev->auto(true))
        {
            sleep(15);
            
            echo "stopping...";
            
            if ($dev->stop())
                echo "done\n";
            else
                die("FAILED!! Stop it manually. Program aborted.\n");
                
            sleep(5);
        }
        else
        {
            die("FAILED!! Unable to control the bot. Program aborted.\n");
        }

        echo "- Spot clean with high power for 15 secs. with ".$dev->nick."...";
        
        if ($dev->spot(true))
        {
            sleep(15);
            
            echo "stopping...";
            
            if ($dev->stop())
                echo "done\n";
            else
                die("FAILED!! Stop it manually. Program aborted.\n");
                
            sleep(5);
        }
        else
        {
            die("FAILED!! Unable to control the bot. Program aborted.\n");
        }

        echo "- Border clean with high power for 15 secs. with ".$dev->nick."...";
        
        if ($dev->border(true))
        {
            sleep(15);
            
            echo "stopping...";
            
            if ($dev->stop())
                echo "done\n";
            else
                die("FAILED!! Stop it manually. Program aborted.\n");
                
            sleep(5);
        }
        else
        {
            die("FAILED!! Unable to control the bot. Program aborted.\n");
        }

        echo "- Turning ".$dev->nick." around...";
        
        if ($dev->turn_around())
            echo "done\n";
        else
            echo "failed\n";

        sleep(5);

        echo "- Holding ".$dev->nick."...";
        
        if ($dev->hold())
            echo "done\n";
        else
            echo "failed\n";

        sleep(5);

        echo "- Locating ".$dev->nick."...";
        
        if ($dev->playsound())
            echo "done\n";
        else
            echo "failed\n";

        sleep(5);

        echo "- Sending ".$dev->nick." back to charger...";
        
        if ($dev->charge())
            echo "done\n";
        else
            echo "failed\n";
    }
}
