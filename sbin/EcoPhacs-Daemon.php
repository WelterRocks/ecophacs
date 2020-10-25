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
use WelterRocks\EcoPhacs\Exception;

// FIFO, config and PID file settings
define("PROG_NAME", "EcoPhacsDaemon");
define("RUN_DIR", "/run/ecophacs");
define("PID_FILE", RUN_DIR."/".PROG_NAME.".pid");

define("SOCKET_IN", RUN_DIR."/ecophacs-in.fifo");
define("SOCKET_OUT", RUN_DIR."/ecophacs-out.fifo");
define("SOCKET_PERM", "0666");

define("HOME_DIR", getenv("HOME"));
define("CONF_DIR", "/etc/ecophacs");

// Handles, bools and tick counters
$fifo_in = null;
$fifo_out = null;

$daemon_terminate = false;
$worker_reload = false;

$ticks_state = 0;
$ticks_lifespans = 0;
$ticks_output = 0;

$public_functions = null;

$pid = null;

$bump_api = null;
$dry_login = null;

$log_options = LOG_CONS | LOG_NDELAY | LOG_PID;

// Create CLI object
try
{
    $cli = new CLI(PROG_NAME);
}
catch (Exception $ex)
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

// Setting FIFO handles and functions
function fifo_shutdown()
{
    global $fifo_in, $fifo_out, $cli;
    
    if (is_resource($fifo_in))
        @fclose($fifo_in);
        
    if (is_resource($fifo_out))
        @fclose($fifo_out);

    $cli->log("Shutdown point reached. Bye.", LOG_INFO);
}

function touch_fifo($file)
{
    global $cli;
    
    if (!file_exists($file))
    {
        if (!function_exists("posix_mkfifo"))
            @shell_exec("mkfifo -m ".SOCKET_PERM." '".$file."' >/dev/null 2>&1");
        else
            @posix_mkfifo($file, SOCKET_PERM);
    }
    
    if (!file_exists($file))
        $cli->exit_error("Unable to create fifo sockets", 2);
}

// Initialize FIFO function
function fifo_initialize()
{
    global $cli, $fifo_in, $fifo_out;
    
    if (!is_dir(RUN_DIR))
        $cli->log("Missing socket directory '".RUN_DIR."'", LOG_EMERG);

    touch_fifo(SOCKET_IN);
    touch_fifo(SOCKET_OUT);

    $fifo_in = @fopen(SOCKET_IN, "r+");

    if (!is_resource($fifo_in))
        $cli->log("Unable to open incoming FIFO '".SOCKET_IN."', permission denied", LOG_EMERG);

    $fifo_out = @fopen(SOCKET_OUT, "w+");

    if (!is_resource($fifo_out))
        $cli->log("Unable to open outgoing FIFO '".SOCKET_OUT."', permission denied", LOG_EMERG);

    stream_set_blocking($fifo_in, false);
    stream_set_blocking($fifo_out, false);
    
    return;
}

// Separator object
function result_separator($id, $type = "begin", $result = "ok", $msg = null, $checksum = null)
{
    global $fifo_out;
    
    switch ($type)
    {
        case "begin":
        case "end":
            break;
        default:
            return null;
    }
    
    switch ($result)
    {
        case "ok":
        case "error":
            break;
        default:
            return null;
    }

    $sep = new \stdClass;
    $sep->result_separator = true;
    $sep->type = $type;
    $sep->id = $id;
    $sep->timestamp = round((microtime(true) * 1000));
    
    if ($type != "begin")
    {
        $sep->result = $result;
        $sep->msg = $msg;
        $sep->checksum = $checksum;
    }
    
    if (!is_resource($fifo_out))
        return null;
            
    @fwrite($fifo_out, base64_encode(json_encode($sep))."\n");
    
    return null;
}

// Worker loop
function worker_loop(Client $ecovacs, $devices)
{
    global $ticks_state, $ticks_lifespans, $ticks_output;
    global $cli, $fifo_in, $fifo_out, $public_functions;
    global $worker_reload, $daemon_terminate, $dry_login, $bump_api;
    
    // Dispatch signals in inner loop
    $cli->signals_dispatch();

    $ticks_state++;
    $ticks_lifespans++;
    $ticks_output++;
    
    if ($ticks_state == 20000)
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
        // Output status each 10000 ticks and mark it as STATUS_timestamp
        $status_id = "STATUS_".round((microtime(true) * 1000));
        $status_buffer = "";
        
        result_separator($status_id);
        
        foreach ($devices as $dev)
        {
            $status_buffer .= base64_encode($dev->to_json())."\n";
        }
        
        $status_checksum = md5($status_buffer);
        
        @fwrite($fifo_out, $status_buffer);
        
        result_separator($status_id, "end", "ok", "Status update", $status_checksum);
        
        unset($status_id);
        unset($status_buffer);
        unset($status_checksum);
        
        $ticks_output = 0;
    }
    
    // Throttle CPU to prevent "doNothingLoop overloads"
    usleep(2500);

    $incoming_message = base64_decode(trim(@fgets($fifo_in, 4096)));
    
    if (!$incoming_message)
        return;
        
    // Split device, command, result seperator and args (format: IDOFDEVICE01234:command:EOL:arg1,arg2,...)
    $fields = explode(":", $incoming_message, 4);
    $device_id = $fields[0];
    
    if (!isset($fields[1]))
        return;
    
    $command = $fields[1];
    
    if (!isset($fields[2]))
        return;
        
    $res_separator = $fields[2];
    
    if (isset($fields[3]))
        $arguments = explode(",", $fields[3]);
    else
        $arguments = array();
        
    // Send result separator at the beginning
    result_separator($res_separator);
    
    // Initialize variables for result separator at the end
    $res_result = "ok";
    $res_message = "command ".$command." for ".$device_id." successful";
    $res_checksum = null;
    
    // Initialize send buffer
    $send_buffer = "";
    
    // Check for valid command
    if (in_array($command, $public_functions))
    {
        $cli->log("Received command '".$command."' for device '".$device_id."'".((count($arguments)) ? " with arguments ".implode(", ", $arguments) : ""), LOG_INFO);
        
        if (!isset($devices[$device_id]))
            return result_separator($res_separator, "end", "error", "device not found");
            
        $result = call_user_func_array(array($devices[$device_id], $command), $arguments);
        
        if ((!is_array($result)) && (!is_object($result)))
            $result = array("result" => $result);
            
        $send_buffer = json_encode($result)."\n";
            
        unset($result);
    }
    elseif (($device_id == "local") && ($command == "exit"))
    {
        $cli->log("Received local exit command", LOG_INFO);
        
        $worker_reload = true;
        $daemon_terminate = true;
    }
    elseif (($device_id == "local") && ($command == "reload"))
    {
        $cli->log("Received local reload command", LOG_INFO);
        
        $worker_reload = true;
    }
    elseif (($device_id == "any") && ($command == "devicelist"))
    {
        $cli->log("Received devicelist request", LOG_INFO);
        
        foreach ($devices as $did => $dev)
        {
            $send_buffer .= json_encode(array("DID" => $dev->did, "nickname" => $dev->nick))."\n";
        }        
    }    
    elseif (($device_id == "any") && ($command == "status"))
    {
        $cli->log("Received status request for devices in json format", LOG_INFO);
        
        foreach ($devices as $did => $dev)
        {
            $send_buffer .= $dev->to_json()."\n";
        }
    }
    elseif (($device_id == "local") && ($command == "ticks"))
    {
        $cli->log("Received local ticks status request", LOG_INFO);
        
        $send_buffer = json_encode(array("result" => "ticks", "state" => $ticks_state, "lifespans" => $ticks_lifespans, "output" => $ticks_output))."\n";
    }
    else
    {
        $cli->log("Received unknown command '".$command."' for device id '".$device_id."'", LOG_WARNING);

        $send_buffer = json_encode(array("error" => "Unknown command ".$command))."\n";
    }
    
    // Checksum the send buffer, even if it is empty
    $res_checksum = md5($send_buffer);
    
    // If we have a send buffer, send it through the fifo socket
    if (trim($send_buffer))
        @fwrite($fifo_out, base64_encode($send_buffer)."\n");
    
    // Send result separator at the end and tell the requesting party, that the command is done
    result_separator($res_separator, "end", $res_result, $res_message, $res_checksum);
    
    // Clean up
    unset($send_buffer);
    unset($arguments);
    unset($command);
    unset($res_separator);
    unset($res_result);
    unset($res_message);
    unset($res_checksum);
    unset($device_id);
    unset($fields);
    unset($incoming_message);

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
    global $cli, $fifo_in, $fifo_out, $public_functions, $log_options;
    global $daemon_terminate, $worker_reload, $bump_api, $dry_login;
    
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
    $cli->init_log(LOG_DAEMON, $log_options);
    
    // Initialize FIFOs
    fifo_initialize();
    
    // Say hello to the log
    $cli->log(PROG_NAME." is starting up", LOG_INFO);
    
    // Daemon loop
    while (!$daemon_terminate)
    {
        // Create the client (main) object
        $ecovacs = new Client(select_config_file());

        // Check, whether we have to "bump" the API
        if (is_object($bump_api)) 
        {
            $ecovacs->bump_api($bump_api->public_key, $bump_api->key, $bump_api->secret, $bump_api->url_main, $bump_api->url_user, $bump_api->realm);
            
            if ($bump_api->is_bumper_server)
                $ecovacs->set_as_bumper_server();
        }

        // Initialize error string store
        $error = null;
        
        // Dispatch signals in outer loop
        $cli->signals_dispatch();
        
        // Try login or "dry" login
        $do_login = (($dry_login) ? "dry_login" : "try_login");

        // Try to login, if not yet done, otherwise fetch device list
        if (!$ecovacs->$do_login($error))
        {
            $cli->log("Unable to ".(($dry_login) ? "dry-" : "")."login: ".$error, LOG_ALERT);
        
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
        while (!$worker_reload) worker_loop($ecovacs, $devices);
        
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
        
    fifo_shutdown();
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
    fifo_shutdown();
    remove_pid();
}

// Use alternative security informations ("bump" the API)
if ($cli->has_argument("--bump-api"))
{
    $public_key_file = $cli->get_argument_childs("--bump-api", 1);

    $bump_api = new \stdClass;

    $bump_api->key = null;
    $bump_api->secret = null;
    $bump_api->url_main = null;
    $bump_api->url_user = null;
    $bump_api->realm = null;
    $bump_api->public_key = null;
    $bump_api->is_bumper_server = null;
    
    if ($cli->has_argument("--is-bumper-server"))
        $bump_api->is_bumper_server = true;
    else
        $bump_api->is_bumper_server = false;

    if ($cli->has_argument("--api-credentials"))
    {
        $credentials = $cli->get_argument_childs("--api-credentials", 2);

        if ((is_array($credentials)) && (count($credentials) == 2))
        {
            $bump_api->key = $credentials[0];
            $bump_api->secret = $credentials[1];
        }

        unset($credentials);
    }

    if ($cli->has_argument("--api-urls"))
    {
        $urls = $cli->get_argument_childs("--api-urls", 2);

        if ((is_array($urls)) && (count($urls) == 2))
        {
            $bump_api->url_main = $urls[0];
            $bump_api->url_user = $urls[1];
        }

        unset($urls);
    }

    if ($cli->has_argument("--api-realm"))
    {
        $args = $cli->get_argument_childs("--api-realm", 1);

        if ((is_array($args)) && (count($args) == 1))
            $bump_api->realm = $args[0];

        unset($args);
    }

    if (!$public_key_file)
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Missing certificate filename after argument.".CLI::COLOR_EOL, 2);
    elseif (is_array($public_key_file))
        $public_key_file = $public_key_file[0];

    if (!file_exists($public_key_file))
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Certificate file not found.".CLI::COLOR_EOL, 2);

    $cert = explode("-----", str_replace("\n", "", trim(@file_get_contents($public_key_file))));

    unset($public_key_file);

    if (count($cert) > 3)
        $bump_api->public_key = $cert[2];

    if (!$bump_api->public_key)
        $cli->exit_error(CLI::COLOR_LIGHT_RED."Not a valid certificate file.".CLI::COLOR_EOL, 3);

    unset($cert);
}

// Check for "dry" login
if ($cli->has_argument("--dry-login"))
    $dry_login = true;

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

    // Redirect log to console
    $log_options = LOG_CONS | LOG_NDELAY | LOG_PID | LOG_PERROR;

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
