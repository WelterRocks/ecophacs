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
linked to your account.

# Installation requirements and example

Project requirements are given in `composer.json` (
[Composer website](https://getcomposer.org)):

You can use this library in your project by running:

```
composer require welterrocks/ecophacs
```

You can see usage example in `Example.php` file by changing credentials to 
point to your ecovacs account. After that, from run `php Example.php` from
project root folder.

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

