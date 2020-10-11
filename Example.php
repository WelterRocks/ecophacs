<?php // THIS FILE IS CURRENTLY ONLY FOR INTERNAL TESTING

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

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . "/../src/EcoPhacs.php";
require __DIR__ . "/../src/Config.php";
require __DIR__ . "/../src/Device.php";

use WelterRocks\EcoPhacs;

$eph = new WelterRocks\EcoPhacs\EcoPhacsClient(".ecophacs-conf");
$err = null;

if (!$eph->try_login($err))
    die("LOGIN-ERROR: ".$err."\n");

if (!$eph->try_connect($err))
    die("CONNECT-ERROR: ".$err."\n");
 
$devices = $eph->get_device_list();

print_r($devices);

   
//print_r($eph);

