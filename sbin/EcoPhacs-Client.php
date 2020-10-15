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

// FIFO, config and PID file settings
define("PROG_NAME", "EcoPhacsClient");
define("RUN_DIR", "/run/ecophacs");
define("PID_FILE", RUN_DIR."/EcoPhacsDaemon.pid");

define("SOCKET_IN", RUN_DIR."/ecophacs-in.fifo");
define("SOCKET_OUT", RUN_DIR."/ecophacs-out.fifo");
define("SOCKET_PERM", "0666");

define("HOME_DIR", getenv("HOME"));
define("CONF_DIR", "/etc/ecophacs");

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
catch (exception $ex)
{
    echo "FATAL ERROR: ".$ex->message."\n";
    exit(255);
}

// Trap signals
$cli->register_signal(SIGTERM);
$cli->redirect_signal(SIGHUP, SIGTERM);
$cli->redirect_signal(SIGUSR1, SIGTERM);
$cli->redirect_signal(SIGUSR2, SIGTERM);
$cli->redirect_signal(SIGINT, SIGTERM);

// Usage
function usage()
{
    global $cli;
    
    $cli->exit_error(CLI::COLOR_LIGHT_RED."Usage: ".CLI::COLOR_WHITE.$cli->get_command().CLI::COLOR_LIGHT_CYAN." devicelist".CLI::COLOR_EOL, 1);
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

// Check usage
if ($cli->has_argument("devicelist"))
{
    // Sending command through outgoing socket
    @fwrite($fifo_out, "any:devicelist\n");
    
    // Getting response from daemon
    $c = "";
    $retval = "";
    
    while ($c != "\n")
    {
        $c = @fgetc($fifo_in);
        
        if ($c == "\n")
            break;
            
        $retval .= $c;
    }
    
    $cli->write(trim($retval));
}
else
{
    usage();
}
        
// Thank you and now, your applause :-)
exit;
