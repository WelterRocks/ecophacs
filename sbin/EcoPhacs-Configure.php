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

// Program name
$prog_name = "EcoPhacs-Configure";

// Create CLI object
try
{
    $cli = new CLI($prog_name);
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

// Try to locate the config file
define("HOME_DIR", getenv("HOME"));
define("CONF_DIR", "/etc/ecophacs");

if ((is_dir(CONF_DIR)) && (file_exists(CONF_DIR."/ecophacs.conf")))
    $config_file = CONF_DIR."/ecophacs.conf";
else
    $config_file = HOME_DIR."/.ecophacsrc";

if ($config_file == HOME_DIR."/.ecophacsrc")
{
    if ((!is_dir(HOME_DIR)) || (!file_exists(HOME_DIR."/.ecophacsrc")))
        define("INIT_CONFIG", true);
}

// No config file? Create one.
if (defined("INIT_CONFIG"))
{
    if (!is_dir(HOME_DIR))
        $cli->exit_error("Missing home directory '".HOME_DIR, 1);
    
    $fd = @fopen($config_file, "w");
    
    if (!is_resource($fd))
        $cli->exit_error("Unable to open configuration file '".$config_file, 2);
    
    @fwrite($fd, "device_id=".md5(microtime(true))."\n");
    @fwrite($fd, "continent=eu\n");
    @fwrite($fd, "country=de\n");
    @fwrite($fd, "account_id=put-your@email.here\n");
    @fwrite($fd, "password_hash=YOUR-CLEARTEXT-PASSWORD-HERE\n");
    @fwrite($fd, "mqtt_hostname=localhost\n");
    @fwrite($fd, "mqtt_hostport=1883\n");
    @fwrite($fd, "mqtt_username=mqtt\n");
    @fwrite($fd, "mqtt_password=YOUR-MQTT-PASSWORD-HERE\n");
    @fwrite($fd, "mqtt_client_id=EcoPhacs\n");
    @fwrite($fd, "mqtt_topic=%prefix%/ecophacs/%device_type%/%device_id%/%suffix%\n");

    @fclose($fd);
}

// Load the config file
$config = parse_ini_file($config_file);
$new_config = array();

// Print a nice header
$cli->write(CLI::COLOR_LIGHT_YELLOW."Welcome to EcoPhacs Configuration Utility.\nVisit us on: ".CLI::COLOR_LIGHT_RED."https://github.com/WelterRocks/ecophacs\n".CLI::COLOR_LIGHT_YELLOW."Copyright (c) 2020 by Oliver Welter.\n".CLI::COLOR_EOL);

// Configure fields
foreach ($config as $key => $val)
{
    $default = ((trim($val)) ? "[".$val."] " : "");
    $default_val = $val;
    
    switch ($key)
    {
        case "continent":
            $text = CLI::COLOR_WHITE."Please enter your ".CLI::COLOR_LIGHT_GREEN."2-letter continent".CLI::COLOR_WHITE." code".CLI::COLOR_EOL;
            break;
        case "country":
            $text = CLI::COLOR_WHITE."Please enter your ".CLI::COLOR_LIGHT_GREEN."2-letter country".CLI::COLOR_WHITE." code".CLI::COLOR_EOL;
            break;
        case "account_id":
            $text = CLI::COLOR_WHITE."Please enter your ".CLI::COLOR_LIGHT_GREEN."email address".CLI::COLOR_WHITE." for your ".CLI::COLOR_YELLOW."ecovacs".CLI::COLOR_WHITE." account".CLI::COLOR_EOL;
            break;
        case "password_hash":
            $cli->write("\n".CLI::COLOR_MAGENTA."WARNING: password characters are shown at console. Press ENTER only to leave to old one!".CLI::COLOR_EOL);
            $text = CLI::COLOR_WHITE."Please enter your ".CLI::COLOR_LIGHT_GREEN."password".CLI::COLOR_WHITE." for your ".CLI::COLOR_YELLOW."ecovacs".CLI::COLOR_WHITE." account".CLI::COLOR_EOL;
            $default = "";
            break;
        case "mqtt_hostname":
            $text = CLI::COLOR_WHITE."Please enter the ".CLI::COLOR_LIGHT_GREEN."hostname".CLI::COLOR_WHITE." of your mqtt-broker".CLI::COLOR_EOL;
            break;
        case "mqtt_hostport":
            $text = CLI::COLOR_WHITE."Please enter the ".CLI::COLOR_LIGHT_GREEN."port".CLI::COLOR_WHITE." of your mqtt-broker".CLI::COLOR_EOL;
            break;
        case "mqtt_username":
            $text = CLI::COLOR_WHITE."Please enter the ".CLI::COLOR_LIGHT_GREEN."username".CLI::COLOR_WHITE." for your mqtt-broker".CLI::COLOR_EOL;
            break;
        case "mqtt_client_id":
            $text = CLI::COLOR_WHITE."Please enter the ".CLI::COLOR_LIGHT_GREEN."client-id".CLI::COLOR_WHITE." for your mqtt-broker".CLI::COLOR_EOL;
            break;
        case "mqtt_topic":
            $text = CLI::COLOR_WHITE."Please enter the ".CLI::COLOR_LIGHT_GREEN."topic".CLI::COLOR_WHITE." for your mqtt-broker".CLI::COLOR_EOL;
            break;
        case "mqtt_password":
            $cli->write("\n".CLI::COLOR_MAGENTA."WARNING: password characters are shown at console. Press ENTER only to leave to old one!".CLI::COLOR_EOL);
            $text = CLI::COLOR_WHITE."Please enter your ".CLI::COLOR_LIGHT_GREEN."password".CLI::COLOR_WHITE." for your mqtt-broker".CLI::COLOR_EOL;
            $default = "";
            break;
        default:
            $new_config[$key] = $val;
            continue(2);
    }
    
    $newval = $cli->input($text.": ".$default);
    
    if ($newval == "")
        $newval = $default_val;
        
    $new_config[$key] = $newval;
}

// Write the new config
$cli->write("\n".CLI::COLOR_DEFAULT."Writing config file '".$config_file."'...".CLI::COLOR_EOL, "");

$fd = fopen($config_file, "w");

if (!is_resource($fd))
    $cli->exit_error("failed", 3);

foreach ($new_config as $key => $val)
{
    @fwrite($fd, $key."=".$val."\n");
}

@fclose($fd);
$cli->write("done");
