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

echo "Bringing up daemon...";

// Setting up fifo sockets
define("SOCKET_DIR", "/var/run");
define("SOCKET_IN", SOCKET_DIR."/ecophacs-in.fifo");
define("SOCKET_OUT", SOCKET_DIR."/ecophacs-out.fifo");
define("SOCKET_PERM", "0600");

$fifo_in = null;
$fifo_out = null;

function fifo_shutdown()
{
    global $fifo_in, $fifo_out;
    
    if (is_resource($fifo_in))
        @fclose($fifo_in);
        
    if (is_resource($fifo_out))
        @fclose($fifo_out);
}

function touch_fifo($file)
{
    if (!file_exists($file))
    {
        if (!function_exists("posix_mkfifo"))
            @shell_exec("mkfifo -m ".SOCKET_PERM." '".$file."' >/dev/null 2>&1");
        else
            @posix_mkfifo($file, SOCKET_PERM);
    }
    
    if (!file_exists($file))
    {
        echo "Unable to create fifo sockets.\n";
        exit(8);
    }    
}

if (!is_dir(SOCKET_DIR))
{
    echo "Missing socket directory '".SOCKET_DIR."'.\n";
    exit(7);
}

touch_fifo(SOCKET_IN);
touch_fifo(SOCKET_OUT);

register_shutdown_function("fifo_shutdown");

$fifo_in = @fopen(SOCKET_IN, "r+");

if (!is_resource($fifo_in))
{
    echo "Unable to open '".SOCKET_IN."', permission denied.\n";
    exit(10);
}

$fifo_out = @fopen(SOCKET_OUT, "w+");

if (!is_resource($fifo_out))
{
    echo "Unable to open '".SOCKET_OUT."', permission denied.\n";
    exit(11);
}

stream_set_blocking($fifo_in, false);
stream_set_blocking($fifo_out, false);

// Create the client (main) object
$ecovacs = new Client($config_file);

// Initialize error string store
$error = null;

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

// Check, whether there are existing devices
if ((!is_array($indexes)) || (!count($indexes)))
{
    echo "No registered device found. Waiting 10 seconds before exitting...";
    sleep(10);
    echo "BYE!\n";

    exit(6);
}

// Get the first device
$first_device = null;

foreach ($devices as $did => $dev)
{
    $first_device = $did;
    break;
}

// Get all public functions from Device class
$public_functions = array();

foreach (get_class_methods($devices[$first_device]) as $f)
{
    // Filter constructor, set, etc.
    if (substr($f, 0, 2) == "__")
        continue;
        
    // Filter to_json function
    if ($f == "to_json")
        continue;
        
    array_push($public_functions, $f);
}

// Start the processing loop
$ticks_state = 0;
$ticks_lifespans = 0;
$ticks_output = 0;

// Initialize statuses
foreach ($devices as $dev)
{
    $dev->get_clean_state();
    $dev->get_charge_state();
    $dev->get_battery_info();            
    
    $dev->get_lifespan(Device::COMPONENT_BRUSH);
    $dev->get_lifespan(Device::COMPONENT_SIDE_BRUSH);
    $dev->get_lifespan(Device::COMPONENT_DUST_CASE_HEAP);
}

echo "READY\n";

while (true)
{
    $ticks_state++;
    $ticks_lifespans++;
    $ticks_output++;
    
    if ($ticks_state == 20000)
    {
        // Request statuses
        foreach ($devices as $dev)
        {
            $dev->get_clean_state();
            $dev->get_charge_state();
            $dev->get_battery_info();            
        }
        
        $ticks_state = 0;
        
        echo "REQUEST STATUS\n";
    }
    
    if ($ticks_lifespans == 100000)
    {
        // Request lifespans
        foreach ($devices as $dev)
        {
            $dev->get_lifespan(Device::COMPONENT_BRUSH);
            $dev->get_lifespan(Device::COMPONENT_SIDE_BRUSH);
            $dev->get_lifespan(Device::COMPONENT_DUST_CASE_HEAP);
        }
        
        $ticks_lifespans = 0;
    }
    
    if ($ticks_output == 10000)
    {
        // Output status
        foreach ($devices as $dev)
        {
            @fwrite($fifo_out, $dev->to_json()."\n");
        }
        
        $ticks_output = 0;
    }
    
    // Throttle CPU to prevent "doNothingLoop overloads"
    usleep(2500);

    $incoming_message = trim(@fread($fifo_in, 1024));
    
    if (!$incoming_message)
        continue;
        
    // Split device, command and args (format: IDOFDEVICE01234:command:arg1,arg2,...)
    $fields = explode(":", $incoming_message, 3);
    $device_id = $fields[0];
    
    if (!isset($fields[1]))
        continue;
    
    $command = $fields[1];
    
    if (isset($fields[2]))
        $arguments = explode(",", $fields[2]);
    else
        $arguments = array();
        
    // Check for valid command
    if (in_array($command, $public_functions))
    {
        if (!isset($devices[$device_id]))
            continue;
            
        $result = call_user_func_array(array($devices[$device_id], $command), $arguments);
        
        if ((!is_array($result)) && (!is_object($result)))
            $result = array("result" => $result);
            
        @fwrite($fifo_out, json_encode($result)."\n");
        
        unset($result);
    }
    elseif (($device_id == "any") && ($command == "devicelist"))
    {
        foreach ($devices as $did => $dev)
        {
            @fwrite($fifo_out, json_encode(array("DID" => $dev->did, "nickname" => $dev->nick))."\n");
        }
    }    
    elseif (($device_id == "any") && ($command == "status"))
    {
        foreach ($devices as $did => $dev)
        {
            @fwrite($fifo_out, $dev->to_json()."\n");
        }
    }
    elseif ($command == "ticks")
    {
        @fwrite($fifo_out, json_encode(array("result" => "ticks", "state" => $ticks_state, "lifespans" => $ticks_lifespans, "output" => $ticks_output))."\n");
    }
    else
    {
        @fwrite($fifo_out, json_encode(array("error" => "Unknown command ".$command))."\n");
    }
    
    // Clean up
    unset($arguments);
    unset($command);
    unset($device_id);
    unset($fields);
    unset($incoming_message);
}

// We should never reach this point
echo "Mainloop kickout, doing suicide :-(\n";
fifo_shutdown();
exit(255);
