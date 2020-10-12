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
if (!$ecovacs->try_login($error))
    die("LOGIN-ERROR: ".$error."\n");

// Try to connect to API server
if (!$ecovacs->try_connect($error))
    die("CONNECT-ERROR: ".$error."\n");

// Get device list and indexes by device id => device name
$indexes = null; 
$devices = $ecovacs->get_device_list($indexes);

// Ping the first device and you will see ---> nothing :-/
foreach ($devices as $did => $dev)
{
    $result = $dev->ping();
    
    print_r($result);
    print_r($dev);
    break;
}
