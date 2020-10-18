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
use WelterRocks\EcoPhacs\Callback;
use WelterRocks\EcoPhacs\CLI;
use WelterRocks\EcoPhacs\MQTT;

// FIFO, config and PID file settings
define("PROG_NAME", "EcoPhacsMQTT");
define("RUN_DIR", "/run/ecophacs");
define("PID_FILE", RUN_DIR."/".PROG_NAME.".pid");

define("HOME_DIR", getenv("HOME"));
define("CONF_DIR", "/etc/ecophacs");

// Handles, bools and tick counters
$daemon_terminate = false;
$worker_reload = false;

$ticks_state = 0;
$ticks_lifespans = 0;
$ticks_output = 0;

$public_functions = null;

$pid = null;

// Create CLI object
try
{
    $cli = new CLI(PROG_NAME);
}
catch (exception $ex)
{
    echo "FATAL ERROR: ".$ex->getMessage()."\n";
    exit(255);
}

// Trap signals for mother
$cli->register_signal(SIGTERM);
$cli->redirect_signal(SIGHUP, SIGTERM);
$cli->redirect_signal(SIGUSR1, SIGTERM);
$cli->redirect_signal(SIGUSR2, SIGTERM);
$cli->redirect_signal(SIGINT, SIGTERM);

// Usage
function usage()
{
    global $cli;
    
    $cli->exit_error(CLI::COLOR_LIGHT_RED."Usage: ".CLI::COLOR_WHITE.$cli->get_command().CLI::COLOR_LIGHT_CYAN." start|stop|reload|status|foreground".CLI::COLOR_EOL, 1);
}

// Worker reload callback
function worker_reload_callback()
{
    global $worker_reload, $cli;
    
    $cli->log("Received HUP signal, initiating worker reload", LOG_INFO);

    $worker_reload = true;
    
    return;
}

// Daemon terminate callback
function daemon_terminate_callback()
{
    global $daemon_terminate, $worker_reload, $cli;
    
    $cli->log("Received TERM signal, initiating daemon shutdown", LOG_INFO);
    
    $daemon_terminate = true;
    $worker_reload = true;
        
    return;
}

// Select config file function
function select_config_file()
{
    if ((is_dir(CONF_DIR)) && (file_exists(CONF_DIR."/ecophacs.conf")))
        $config_file = CONF_DIR."/ecophacs.conf";
    else
        $config_file = HOME_DIR."/.ecophacsrc";

    if ($config_file == HOME_DIR."/.ecophacsrc")
    {
        if ((!is_dir(HOME_DIR)) || (!file_exists(HOME_DIR."/.ecophacsrc")))
	    return false;
    }
    
    return $config_file;
}

// Worker loop
function worker_loop(MQTT $mqtt, Client $ecovacs, $devices)
{
    global $ticks_state, $ticks_lifespans, $ticks_output;
    global $cli, $public_functions;
    global $worker_reload, $daemon_terminate;
    
    // Dispatch signals in inner loop
    $cli->signals_dispatch();

    $ticks_state++;
    $ticks_lifespans++;
    $ticks_output++;
    
    if ($ticks_state == 2500)
    {
        // Request statuses
        foreach ($devices as $dev)
        {
            $dev->ping();
            
            $dev->get_clean_state();
            $dev->get_charge_state();
            $dev->get_battery_info();            
        }
        
        $ticks_state = 0;
    }
    
    if ($ticks_lifespans == 10000)
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
    
    if ($ticks_output == 1000)
    {
        // Output status each 10000 ticks
        
        foreach ($devices as $dev)
        {
            $topic = $mqtt->get_topic("tele", $dev->did, "STATE");
            $payload = $dev->to_json();
            
            try
            {
                $mqtt->message_send($topic, $payload, 1, false);
            }
            catch (Mosquitto\Exception $ex)
            {
                $cli->log("Mosquitto exception: ".$ex->getMessage(), LOG_ALERT);
                $worker_reload = true;
                return;
            }
            catch (exception $ex)
            {
                $cli->log("General MQTT exception: ".$ex->getMessage(), LOG_ALERT);
                $worker_reload = true;
                return;
            }
        }
                        
        $ticks_output = 0;
    }    
    
    // Throttle CPU to prevent "doNothingLoop overloads"
    usleep(2500);
    
    // Check, whether we have lost the conenction to the broker
    if (!$mqtt->connected)
    {
        $worker_reload = true;
        
        $cli->log("Lost connection to MQTT broker.", LOG_ALERT);
        
        sleep(5);
        
        return;
    }

    try
    {
        $message = $mqtt->loop(10);
    }
    catch (Mosquitto\Exception $ex)
    {
        $cli->log("Mosquitto exception: ".$ex->getMessage(), LOG_ALERT);
        $worker_reload = true;
        return;
    }
    catch (exception $ex)
    {
        $cli->log("General MQTT exception: ".$ex->getMessage(), LOG_ALERT);
        $worker_reload = true;
        return;
    }
    
    if ((!$message) && (!is_object($message)))
        return;
        
    if ($message->topic_prefix != "command")
        return;
        
    $command = $message->topic_suffix;
    $device_id = $message->topic_device;
    $arguments = explode(",", $message->payload);
    
    // if command is STATE, take command from arguments
    if (($command == "STATE") && (count($arguments) > 0))
    {
        $command = $arguments[0];
        
        unset($arguments[0]);
        
        // Replace arguments in special cases
        switch ($command)
        {
            case "auto":
            case "singleroom":
                if (isset($devices[$device_id]))
                    $arguments = array((($devices[$device_id]->status_vacuum_power == Device::VACUUM_POWER_STRONG) ? 1 : 0));
                break;
        }
    }
    
    // if command is POWER, take command from arguments
    if (($command == "POWER") && (count($arguments) > 0))
    {
        $command = $arguments[0];        
        $arguments = null;        
    }
    
    if (!$arguments)
        $arguments = array();
    
    // Initialize send variables
    $send_topic = $mqtt->get_topic("stat", $device_id, $command);
    $send_payload = null;
    $send_qos = 0;
    $send_retain = false;
    
    // Check for valid command
    if (in_array($command, $public_functions))
    {
        $cli->log("Received command '".$command."' for device '".$device_id."'".((count($arguments)) ? " with arguments ".implode(", ", $arguments) : ""), LOG_INFO);
        
        $result = call_user_func_array(array($devices[$device_id], $command), $arguments);
        
        if ((!is_array($result)) && (!is_object($result)))
            $result = array("command" => $command, "arguments" => $arguments, "device_id" => $device_id, "timestamp" => round((microtime(true) * 1000)), "result" => $result);
        
        $send_payload = json_encode($result);
        $send_qos = 1;
        $send_retain = true;
                    
        unset($result);
    }
    elseif (($device_id == "local") && ($command == "exit"))
    {
        $cli->log("Received local exit command", LOG_INFO);
        
        $worker_reload = true;
        $daemon_terminate = true;
        
        $result = array("command" => $command, "arguments" => $arguments, "device_id" => $device_id, "timestamp" => round((microtime(true) * 1000)), "result" => true);

        $send_payload = json_encode($result);
        
        unset($result);
    }
    elseif (($device_id == "local") && ($command == "reload"))
    {
        $cli->log("Received local reload command", LOG_INFO);
        
        $worker_reload = true;

        $result = array("command" => $command, "arguments" => $arguments, "device_id" => $device_id, "timestamp" => round((microtime(true) * 1000)), "result" => true);

        $send_payload = json_encode($result);
        
        unset($result);
    }
    elseif (($device_id == "any") && ($command == "devicelist"))
    {
        $cli->log("Received devicelist request", LOG_INFO);
        
        $devicelist = array();
                
        foreach ($devices as $did => $dev)
        {
            array_push($devicelist, array("DID" => $dev->did, "nickname" => $dev->nick, "timestamp" => round((microtime(true) * 1000))));
        }
        
        $send_payload = json_encode($devicelist);
        
        unset($devicelist);
    }    
    elseif (($device_id == "any") && ($command == "status"))
    {
        $cli->log("Received status request for devices in json format", LOG_INFO);
        
        $devicestatus = array();                
        
        foreach ($devices as $did => $dev)
        {
            array_push($devicestatus, $dev->to_json());
        }
        
        $send_payload = json_encode($devicestatus);
        
        unset($devicestatus);
    }
    elseif (($device_id == "local") && ($command == "ticks"))
    {
        $cli->log("Received local ticks status request", LOG_INFO);
        
        $send_payload = json_encode(array("result" => "ticks", "state" => $ticks_state, "lifespans" => $ticks_lifespans, "output" => $ticks_output, "timestamp" => round((microtime(true) * 1000))))."\n";
    }
    else
    {
        $cli->log("Received unknown command '".$command."' for device id '".$device_id."'", LOG_WARNING);

        $result = array("command" => $command, "arguments" => $arguments, "device_id" => $device_id, "timestamp" => round((microtime(true) * 1000)), "result" => false, "error" => "Unknown command");
        $send_payload = json_encode($result)."\n";
    }
    
    // Send message, if any
    if ($send_payload)
    {
        try
        {
            $mqtt->message_send($send_topic, $send_payload, $send_qos, $send_retain);
        }
        catch (Mosquitto\Exception $ex)
        {
            $cli->log("Mosquitto exception: ".$ex->getMessage(), LOG_ALERT);
            $worker_reload = true;
            return;
        }
        catch (exception $ex)
        {
            $cli->log("General MQTT exception: ".$ex->getMessage(), LOG_ALERT);
            $worker_reload = true;
            return;
        }
    }
    
    // Clean up
    unset($send_topic);
    unset($send_payload);
    unset($send_qos);
    unset($send_retain);
    
    return;
}

// Mother callback is triggered, when child is starting up
function mother()
{
    global $cli;
    
    $cli->write(CLI::COLOR_WHITE."Bringing up ".CLI::COLOR_LIGHT_YELLOW.PROG_NAME.CLI::COLOR_WHITE."...".CLI::COLOR_EOL, "");
    sleep(2);
    $cli->write(CLI::COLOR_LIGHT_GREEN."done".CLI::COLOR_EOL);
    
    return;
}

// Daemon callback does the hard part
function daemon()
{
    global $cli, $public_functions;
    global $daemon_terminate, $worker_reload;
    
    // Register shutdown function
    register_shutdown_function("shutdown_daemon");
    
    // Set sync signal handling
    $cli->set_async_signals(false);
    
    // Force rewrite of PID file to set childs PID
    $cli->set_pidfile(PID_FILE, $cli->get_pid(), true);
    
    // Create callbacks
    $callback_daemon_terminate = new Callback("DaemonTerminate", 100, "daemon_terminate_callback");
    $callback_worker_reload = new Callback("WorkerReload", 100, "worker_reload_callback");
    
    // Register callbacks
    $cli->register_callback(SIGTERM, "DaemonTerminate", 100, $callback_daemon_terminate);
    $cli->register_callback(SIGHUP, "WorkerReload", 100, $callback_worker_reload);
    
    // Trap signals for daemon use and clear redirects
    $cli->init_redirects();
    $cli->register_signal(SIGTERM);
    $cli->register_signal(SIGHUP);
    $cli->redirect_signal(SIGINT, SIGTERM);
    
    // Initialize logger
    $cli->init_log(LOG_DAEMON);
    
    // Say hello to the log
    $cli->log(PROG_NAME." is starting up", LOG_INFO);
    
    // Daemon loop
    while (!$daemon_terminate)
    {
        // Select config file
        $config_file = select_config_file();
        
        // Create the MQTT object
        try
        {
            $mqtt = new MQTT($config_file);
        }
        catch (Mosquitto\Exception $ex)
        {
            $cli->log("Mosquitto exception: ".$ex->getMessage(), LOG_ALERT);
            
            sleep(10);
            
            continue;
        }
        catch (exception $ex)
        {
            $cli->log("General MQTT exception: ".$ex->getMessage(), LOG_ALERT);
            
            sleep(10);
            
            continue;
        }
        
        // Check, whether the MQTT connection has been established
        if (!$mqtt->connected)
        {
            $cli->log("Unable to connect to MQTT host, with topic ".$mqtt->get_topic("+", "+", "+"), LOG_ALERT);
            
            sleep(10);
            
            continue;
        }
        
        // Create the client object
        $ecovacs = new Client($config_file);

        // Initialize error string store
        $error = null;
        
        // Dispatch signals in outer loop
        $cli->signals_dispatch();

        // Try to login, if not yet done, otherwise fetch device list
        if (!$ecovacs->try_login($error))
        {
            $cli->log("Unable to login: ".$error, LOG_ALERT);
        
            sleep(10);
            
            continue;
        }

        // Try to connect to API server
        if (!$ecovacs->try_connect($error))
        {
            $cli->log("Unable to connect to server: ".$error, LOG_ALERT);
            
            sleep(10);
            
            continue;
        }
        
        // Get device list and indexes by device id => device name and do the magic
        $indexes = null;
        $devices = $ecovacs->get_device_list($indexes);
        
        // Check, whether there are existing devices
        if ((!is_array($indexes)) || (!count($indexes)))
        {
            $cli->log("No registered device found, yet", LOG_WARNING);
            
            sleep(10);

            continue;
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

        // Initialize devices status
        foreach ($devices as $dev)
        {
            $dev->get_clean_state();
            $dev->get_charge_state();
            $dev->get_battery_info();
            
            usleep(500);
    
            $dev->get_lifespan(Device::COMPONENT_BRUSH);
            $dev->get_lifespan(Device::COMPONENT_SIDE_BRUSH);
            $dev->get_lifespan(Device::COMPONENT_DUST_CASE_HEAP);
            
            usleep(500);
        }
        
        // Send info to log, if inner worker loop begins
        $cli->log("Reached inner worker loop. Ready to serve :-)", LOG_INFO);

        // The worker loop
        while (!$worker_reload) worker_loop($mqtt, $ecovacs, $devices);
        
        // Send info to log, if inner worker loop breaks
        $cli->log("Left inner worker loop. Reloading.", LOG_INFO);
    
        // Reset worker reload
        $worker_reload = false;
        
        // Wait a second before reloop
        sleep(1);
    }
    
    // Send info to log, if outer loop breaks
    $cli->log("Left outer daemon loop. Waiting for shutdown.", LOG_INFO);
    
    sleep(5);
        
    remove_pid();
        
    return;
}

// Remove PID function
function remove_pid()
{
    global $cli;
    
    $cli->remove_pidfile(PID_FILE, true);
}

// Shutdown function
function shutdown_daemon()
{
    remove_pid();
}

// Check usage
if ($cli->has_argument("start"))
{
    // Check for existing pid file and bound service
    if ($cli->check_pid_from_pidfile(PID_FILE, $pid))
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Another instance of ".CLI::COLOR_LIGHT_YELLOW.PROG_NAME.CLI::COLOR_LIGHT_RED." is running at PID ".CLI::COLOR_LIGHT_GREEN.$pid.CLI::COLOR_EOL, 2);
    elseif (!$cli->set_pidfile(PID_FILE, $cli->get_pid()))
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Unable to write PID file '".CLI::COLOR_LIGHT_YELLOW.PID_FILE.CLI::COLOR_LIGHT_RED."'".CLI::COLOR_EOL, 3);
    
    // Daemonize (fork) and prevent mother from killing her childs
    $cli->allow_zombies();
    $cli->fork("daemon", "mother", "daemon");
}
elseif ($cli->has_argument("foreground"))
{
    // Check for existing pid file and bound service
    if ($cli->check_pid_from_pidfile(PID_FILE, $pid))
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Another instance of ".CLI::COLOR_LIGHT_YELLOW.PROG_NAME.CLI::COLOR_LIGHT_RED." is running at PID ".CLI::COLOR_LIGHT_GREEN.$pid.CLI::COLOR_EOL, 2);
    elseif (!$cli->set_pidfile(PID_FILE, $cli->get_pid()))
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Unable to write PID file '".CLI::COLOR_LIGHT_YELLOW.PID_FILE.CLI::COLOR_LIGHT_RED."'".CLI::COLOR_EOL, 3);
    
    // Start the daemon in foreground    
    daemon();
}
elseif ($cli->has_argument("stop"))
{
    // Get PID, if one and send SIGTERM to stop
    $pid = CLI::get_pid_from_pidfile(PID_FILE);
    
    if (!$pid)
        $cli->exit_error(CLI::COLOR_LIGHT_GREEN."No running process found.".CLI::COLOR_EOL, 2);
        
    $cli->write(CLI::COLOR_WHITE."Stopping ".CLI::COLOR_LIGHT_YELLOW.PROG_NAME.CLI::COLOR_WHITE." with PID ".CLI::COLOR_LIGHT_RED.$pid.CLI::COLOR_WHITE."...".CLI::COLOR_EOL, "");
    $cli->trigger_signal_to($pid, SIGTERM);    
    sleep(3);
    $cli->write(CLI::COLOR_LIGHT_GREEN."OK".CLI::COLOR_EOL);    
}
elseif ($cli->has_argument("reload"))
{
    // Get PID, if one and send SIGHUP to reload
    $pid = CLI::get_pid_from_pidfile(PID_FILE);
    
    if (!$pid)
        $cli->exit_error(CLI::COLOR_LIGHT_GREEN."No running process found.".CLI::COLOR_EOL, 2);
        
    $cli->write(CLI::COLOR_WHITE."Reloading ".CLI::COLOR_LIGHT_YELLOW.PROG_NAME.CLI::COLOR_WHITE." with PID ".CLI::COLOR_LIGHT_RED.$pid.CLI::COLOR_WHITE."...".CLI::COLOR_EOL, "");
    $cli->trigger_signal_to($pid, SIGHUP);    
    sleep(3);
    $cli->write(CLI::COLOR_LIGHT_GREEN."OK".CLI::COLOR_EOL);    
}
elseif ($cli->has_argument("status"))
{
    // Get PID, if one and display it
    $pid = CLI::get_pid_from_pidfile(PID_FILE);
    
    if (!$pid)
        $cli->exit_error(CLI::COLOR_LIGHT_GREEN."No running process found.".CLI::COLOR_EOL, 2);
        
    $cli->write(CLI::COLOR_LIGHT_YELLOW.PROG_NAME.CLI::COLOR_WHITE." is running with PID ".CLI::COLOR_LIGHT_RED.$pid.CLI::COLOR_EOL);
}
else
{
    usage();
}
        
// Thank you and now, your applause :-)
exit;
