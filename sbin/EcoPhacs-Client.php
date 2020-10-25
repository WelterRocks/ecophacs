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
define("PROG_NAME", "EcoPhacsClient");
define("RUN_DIR", "/run/ecophacs");
define("PID_FILE", RUN_DIR."/EcoPhacsDaemon.pid");

define("SOCKET_IN", RUN_DIR."/ecophacs-in.fifo");
define("SOCKET_OUT", RUN_DIR."/ecophacs-out.fifo");
define("SOCKET_PERM", "0666");

define("HOME_DIR", getenv("HOME"));
define("CONF_DIR", "/etc/ecophacs");

// Set error reporting
error_reporting(E_ERROR | E_PARSE);

// Handles, bools and tick counters
$fifo_in = null;
$fifo_out = null;

$public_functions = null;

$pid = null;

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

// Trap signals
$cli->register_signal(SIGTERM);
$cli->redirect_signal(SIGHUP, SIGTERM);
$cli->redirect_signal(SIGUSR1, SIGTERM);
$cli->redirect_signal(SIGUSR2, SIGTERM);
$cli->redirect_signal(SIGINT, SIGTERM);

// Usable device commands
$commands = array("auto", "stop", "border", "spot", "left", "right", "forward", "turn", "halt", "playsound", "charge", "singleroom");

// Usage
function usage()
{
    global $cli, $commands;
    
    $cli->write(CLI::COLOR_LIGHT_RED."Usage: ".CLI::COLOR_WHITE.$cli->get_command().CLI::COLOR_LIGHT_CYAN." command [--wait secs|--nano-wait usecs] [--device-id devid] [--no-stdout] [--no-stderr] [--arguments arg1 arg2 ... arg10] ...".CLI::COLOR_EOL);
    $cli->write("\nValid general commands are:\n  devicelist, status, reload, exit, ticks\n");
    $cli->write("Valid sequencable pro-device commands are:\n  ".implode(", ", $commands)."\n");
    
    $cli->exit_error(CLI::COLOR_LIGHT_YELLOW."Copyright (c) 2020 Oliver Welter <".CLI::COLOR_WHITE."oliver@welter.rocks".CLI::COLOR_LIGHT_YELLOW.">\n".CLI::COLOR_LIGHT_MAGENTA."Visit project at: ".CLI::COLOR_LIGHT_BLUE."https://github.com/WelterRocks/ecophacs".CLI::COLOR_EOL, 1);
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

    register_shutdown_function("fifo_shutdown");

    $fifo_in = @fopen(SOCKET_OUT, "r+");

    if (!is_resource($fifo_in))
        $cli->log("Unable to open incoming FIFO '".SOCKET_OUT."', permission denied", LOG_EMERG);

    $fifo_out = @fopen(SOCKET_IN, "w+");

    if (!is_resource($fifo_out))
        $cli->log("Unable to open outgoing FIFO '".SOCKET_IN."', permission denied", LOG_EMERG);

    stream_set_blocking($fifo_in, false);
    stream_set_blocking($fifo_out, false);
    
    return;
}

// Check, whether the daemon is running
$pid = CLI::get_pid_from_pidfile(PID_FILE);

if (!$pid)
    $cli->exit_error(CLI::COLOR_LIGHT_RED."No running daemon process found.".CLI::COLOR_EOL, 2);
    
// Register shutdown function and intialize FIFOs
register_shutdown_function("fifo_shutdown");
fifo_initialize();

// Initialize random number generator
srand((double)microtime() * 94763324180);

// Generate result separator ID
function result_separator_id()
{
    global $cli;
    
    return base64_encode(gzencode($cli->get_command()."-".sha1(md5(rand(1000,9999)).md5(microtime(true) * rand(999,99999999))).".".md5(microtime(true))."_".round((microtime(true) * 1000)), 9));
}

// Send command
function send_command($device_id, $command, $args = null, $no_stdout = false, $no_stderr = false, &$res_object = null, $timeout_seconds = 10)
{
    global $fifo_in, $fifo_out, $cli;
    
    // Get timestamp
    $res_timestamp = round((microtime(true) * 1000));
    
    // Get a random result separator
    $res_separator = result_separator_id();
    
    // Prepare string to send
    $send_str = $device_id.":".$command.":".$res_separator.((is_array($args)) ? ":".implode(",", $args) : "");
    
    // Sending encoded command through outgoing socket
    @fwrite($fifo_out, base64_encode($send_str)."\n");
    
    // Getting response from daemon
    $buffer = "";
    
    // Initialize result stores
    $res_object = new \stdClass;
    $res_object->begin = null;
    $res_object->result = null;
    $res_object->data = null;
    
    // Timeout init
    $timeout = (time() + $timeout_seconds);
    
    while ((!feof($fifo_in)) && ($timeout > time()))
    {
        $buffer .= @fgets($fifo_in, 4096);
        
        foreach (explode("\n", $buffer) as $line)
        {
            if (trim($line) == "")
            {
                usleep(250);
                continue;
            }
                
            $dec = trim(base64_decode($line));

            if (substr($dec, 0, 1) != "{")
            {
                usleep(250);
                continue;
            }
            
            foreach (explode("\n", $dec) as $json_encoded)
            {
                // Try to decode json encoded data
                $json = json_decode(trim($json_encoded));
                
                // If we cannot decode valid json, start over
                if (!is_object($json))
                {
                    usleep(250);
                    continue;
                }
                
                // Check, whether we have a result separator object
                if ((isset($json->result_separator)) && (isset($json->type)) && (isset($json->id)) && (isset($json->timestamp)))
                {
                    // Check, whether the result separator ID matches and timestamps making sense
                    if (($json->id == $res_separator) && ($res_timestamp <= $json->timestamp))
                    {
                        if ($json->type == "begin")
                        {
                            // Make sure, the next data is written to data array of res_object
                            $res_object->data = array();
                            $res_object->begin = clone $json;
                            
                            usleep(250);
                            continue;
                        }
                        elseif ($json->type == "end")
                        {
                            // Copy the ending result separator object to res_object and break all loops
                            $res_object->result = clone $json;
                            break(3);
                        }
                    }
                }
                
                // If res_object data was initialized as array, store any incoming data to it
                if (is_array($res_object->data))
                {
                    // Create a checksum from json data
                    $checksum = md5(json_encode($json));            
                    
                    // Store checksum indexed data to prevent multiple copies of the same object
                    $res_object->data[$checksum] = clone $json;
                }
            }
        }
    }
    
    // Check, if a timeout occured
    if ($timeout <= time())
    {
        if (!$no_stdout)
            $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_RED."ERROR: ".CLI::COLOR_LIGHT_YELLOW."A timeout occured.".CLI::COLOR_EOL);
        
        return false;    
    }
    
    // Check, whether we have no begin object
    if (!$res_object->begin)
    {
        if (!$no_stdout)
            $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_RED."ERROR: ".CLI::COLOR_LIGHT_YELLOW."No valid response from daemon. Check, whether the daemon is still running.".CLI::COLOR_EOL);
        
        return false;
    }
    
    // Check, whether we have no result object
    if (!$res_object->result)
    {
        if (!$no_stdout)
            $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_RED."ERROR: ".CLI::COLOR_LIGHT_YELLOW."Incomplete and inconsistent data. The connection has been closed, before the result data was sent.".CLI::COLOR_EOL);
        
        return false;        
    }
    
    // Check, whether we have no result object
    if ($res_object->result->result != "ok")
    {
        if (!$no_stdout)
            $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_RED."ERROR: ".CLI::COLOR_LIGHT_YELLOW.(($res_object->result->msg != "") ? $res_object->result->msg : "Daemon responded with an unknown error.").CLI::COLOR_EOL);
        
        return false;        
    }
    
    // Check, whether the data sent is the data we received
    $json_buffer = "";
    
    foreach ($res_object->data as $data)
    {
        $json_buffer .= json_encode($data)."\n";
    }
    
    $json_checksum = md5($json_buffer);
    
    if ($json_checksum != $res_object->result->checksum)
    {
        if (!$no_stdout)
            $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_RED."ERROR: ".CLI::COLOR_LIGHT_YELLOW."Incomplete and inconsistent data. The received data checksum does not match the calculated checksum.".CLI::COLOR_EOL);
            
        return false;                
    }

    // Send a nice response to stdout    
    if (!$no_stdout)
        $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_GREEN."RESULT: ".CLI::COLOR_WHITE.(($res_object->result->msg != "") ? $res_object->result->msg : "Ok.").CLI::COLOR_EOL);

    // If we have any result data, write it to stderr, so they can easily be grabbed by other scripts
    if ((!$no_stderr) && (count($res_object->data) > 0))
    {
        foreach ($res_object->data as $data)
        {
            $cli->write(json_encode($data), "\n", true);
        }
    }
    
    // Done, return true
    return true;
}

// Get the device id, if given
if ($cli->has_argument("--device-id"))
    $device_id = $cli->get_argument_childs("--device-id");
else
    $device_id = null;
    
if ((is_array($device_id)) && (count($device_id) > 0))
    $device_id = implode(" ", $device_id);
else
    $device_id = "none";
    
// Get arguments for commands, if given
if ($cli->has_argument("--arguments"))
    $arguments = $cli->get_argument_childs("--arguments", 10);
else
    $arguments = null;
    
// Suppress standard output
if ($cli->has_argument("--no-stdout"))
    $no_stdout = true;
else
    $no_stdout = false;
    
// Suppress standard error
if ($cli->has_argument("--no-stderr"))
    $no_stderr = true;
else
    $no_stderr = false;
    
// Check usage and execute commands
if ($cli->has_argument("devicelist"))
{
    // Get the device list from daemon
    $res = send_command("any", "devicelist", null, $no_stdout, $no_stderr);
}
elseif ($cli->has_argument("status"))
{
    // Get the status information from daemon
    $res = send_command("any", "status", null, $no_stdout, $no_stderr);
}
elseif ($cli->has_argument("reload"))
{
    // Force daemon to reload
    $res = send_command("local", "reload", null, $no_stdout, $no_stderr);
}
elseif ($cli->has_argument("ticks"))
{
    // Show the ticks statistic
    $res = send_command("local", "ticks", null, $no_stdout, $no_stderr);
}
elseif ($cli->has_argument("exit"))
{
    // Force daemon to kill itself
    $res = send_command("local", "exit", null, $no_stdout, $no_stderr);
}
else
{
    // Each bot command can be sequenced for a single bot. 
    // If you would like to drive forward for two seconds, than turn left for one second and than halt, use:
    // forward --wait 2 left --wait 1 halt
    
    $res = false;
    $has_command = false;
    
    for ($i = 1; $i < $argc; $i++)
    {
        if ($argv[$i] == "--wait")
        {
            $secs = $argv[$i+1];
            $i++;
            
            if ((!is_numeric($secs)) || ($secs < 0) || (!$secs))
                continue;
                
            sleep($secs);
            
            if (!$no_stdout)
                $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_YELLOW."WAIT: ".CLI::COLOR_WHITE."Waiting for ".$secs." seconds.".CLI::COLOR_EOL);
            
            continue;
        }
        
        if ($argv[$i] == "--nano-wait")
        {
            $usecs = $argv[$i+1];
            $i++;
            
            if ((!is_numeric($usecs)) || ($usecs < 0) || (!$usecs))
                continue;
                
            usleep($usecs);

            if (!$no_stdout)
                $cli->write(CLI::COLOR_LIGHT_BLUE.date("Y-m-d H:i:s")." ".CLI::COLOR_LIGHT_MAGENTA."NANOWAIT: ".CLI::COLOR_WHITE."Waiting for ".$usecs." nanoseconds.".CLI::COLOR_EOL);           
            
            continue;
        }
        
        foreach ($commands as $cmd)
        {
            if ($cmd == $argv[$i])
            {                
                $res &= send_command($device_id, $cmd, $arguments, $no_stdout, $no_stderr);
                $has_command = true;
                break;
            }
        }
    }
    
    // No valid command, send usage
    if (!$has_command)
        usage();
}
        
// Thank you and now, your applause :-)
exit((($res) ? 0 : 1));
