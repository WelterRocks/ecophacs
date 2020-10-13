# EcoPhacs is a PHP client library for the Ecovacs API

[![Latest Stable Version](https://poser.pugx.org/welterrocks/ecophacs/v/stable)](https://packagist.org/packages/welterrocks/ecophacs)
[![Total Downloads](https://poser.pugx.org/welterrocks/ecophacs/downloads)](https://packagist.org/packages/welterrocks/ecophacs)
[![Latest Unstable Version](https://poser.pugx.org/welterrocks/ecophacs/v/unstable)](https://packagist.org/packages/welterrocks/ecophacs)
[![Build Status](https://travis-ci.org/welterrocks/ecophacs.svg?branch=master)](https://travis-ci.org/welterrocks/ecophacs)
[![License](https://poser.pugx.org/welterrocks/ecophacs/license)](https://packagist.org/packages/welterrocks/ecophacs)

This library uses PHP to connect to the Ecovacs API and let you control your
Ecovacs based devices, like Deebot for example. You need an Ecovacs cloud
account and a password to login or a local server like [Bumper](https://github.com/bmartin5692/bumper). 
The library shows and let you control the supported and registered devices, 
linked to your account. EcoPhacs has been written in PHP and is an alternative
to the [Sucks](https://github.com/wpietri/sucks) project.

# News
- **2020-10-13** Added EcoPhacsD FIFO daemon.
If you would like to have fast access to your bots and immidiate command reactions and responses,
then this new daemon is probably what you are searching for. You need two FIFOs in /var/run, named
`ecophacs-in.fifo` and `ecophacs-out.fifo`, which can be accessed by `EcoPhacsD.php` while running.
The requirements to run the daemons are the same as for the EcoPhacs.php example (read below). To
read, what the daemon outputs, connect to `/var/run/ecophacs-out.fifo`, for example with a `cat`
command. To send commands write to `/var/run/ecophacs-in.fifo`, for example with the `echo`
command line tool. The commands are send as `device-id:command:arg1,arg2,arg3,...`, where device-id
is the DID of the bot. Also there are some special commands like `any:status`, `any:devicelist`.
With the example below, you cat start the bot cleaning in auto mode. Commands are the same, as they
apear as public functions in `Device.php`, without constructor or PHP internal class functions.
Replace E000111122233344445 with the DID of your bot for testing. Status reports are automatically
fetched and sent over the output FIFO, periodically. But remember, EcoPhacsD is an example only,
which is meant to be a technological proof of concept and not for production use, currently.

```
cat /var/run/ecophacs-out.fifo

echo "E000111122233344445:auto" > /var/run/ecophacs-in.fifo

echo "E000111122233344445:stop" > /var/run/ecophacs-in.fifo
```


# Installation requirements

Project requirements are given in `composer.json` (
[Composer website](https://getcomposer.org)):

You can use this library in your project by running:

```
composer require welterrocks/ecophacs
```

To use the standalone CLI, use the command above and change to directory:

```
cd vendor/welterrocks/ecophacs
```

After that, install the dependencies:

```
composer install
```

Than run the CLI tool:

```
php EcoPhacs.php
```

The CLI tool will create a file in your HOME directory ~/.ecophacsrc which
you should edit to your needs. Set `continent`, `country`, `account_id` and the
`password_hash` fields to your region and account settings. Do not be confused
about the field `password_hash`. Just fill the field with your password in
cleartext. After the first use, it will be hashed and encoded automatically. The
field `device_id` is autogenerated. There is no need to change it, but if you like
to, set it something with 32 byte string. Also, the CLI tool will not start, if you
do not change the config file.

If you have changed the config file to your needs, try your first shot with:

```
php EcoPhacs.php --list-devices
```

If your account is properly set up in the config file, you will see a device list, like:

```
I found 2 registered devices:

(Device-ID => Device-Name)

 * E0001111222233334444 => Deebot1
 * E0001111222233334455 => Deebot2
```

Select your bot by using the device id or its nickname (Device-Name) and request a status:

```
php EcoPhacs.php --device-name Deebot1 --status
```

OR with device id

```
php EcoPhacs.php --device-id E0001111222233334444 --status
```

which will lead to a status report of the selected device. Now clean a bit in Auto mode, with
strong power, stop the bot and send it back to its charger.

```
php EcoPhacs.php --device-name Deebot1 --power strong --auto

php EcoPhacs.php --device-name Deebot1 --stop

php EcoPhacs.php --device-name Deebot1 --charge
```

Because there is no `--help` for now, because I am focused on the functionallity first, you should
have a look at the code of `EcoPhacs.php` to understand, which switches you can use in CLI mode.

You can also use the usage example in `Example.php` file by changing credentials to 
point to your ecovacs account. After that, from run `php Example.php` from
project root folder, if you like. The Example will test all bots with a couple of functions.
They will start cleaning in several modes (each for 15 seconds) and then return to the charger.

# Library usage
## General information
This library is in an early development stage. You can use this library
with the ecovacs cloud, to control your registered devices. Also it has
been tested with [Bumper](https://github.com/bmartin5692/bumper), which
is a great project by [Brian Martin](https://github.com/bmartin5692), giving you a local server for your bots.
Use this lib with care and do NOT use it for production environments for now,
because there is too much testing to do...Hope I can get you to give it a try.

## TODO's

- **testing** - writing some testing routines for packaging
currently there is only one working Example.php and no tests
- **mqtt** - implement support for MQTT broker
it is planned to implement a mqtt listener and a mqtt status daemon

