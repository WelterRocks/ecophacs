# EcoPhacs is a PHP client library for the Ecovacs API

[![Latest Stable Version](https://poser.pugx.org/welterrocks/ecophacs/v/stable)](https://packagist.org/packages/welterrocks/ecophacs)
[![Total Downloads](https://poser.pugx.org/welterrocks/ecophacs/downloads)](https://packagist.org/packages/welterrocks/ecophacs)
[![Latest Unstable Version](https://poser.pugx.org/welterrocks/ecophacs/v/unstable)](https://packagist.org/packages/welterrocks/ecophacs)
[![Build Status](https://travis-ci.org/welterrocks/ecophacs.svg?branch=master)](https://travis-ci.org/welterrocks/ecophacs)
[![License](https://poser.pugx.org/welterrocks/ecophacs/license)](https://packagist.org/packages/welterrocks/ecophacs)

This library uses PHP to connect to the Ecovacs API and let you control your
Ecovacs based devices, like Deebot for example. You need an Ecovacs cloud
account and a password to login. The library shows and let you control the
supported and registered devices, linked to your account.

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
This library is in a very early development stage. For now, it can connect
to the ecovacs cloud and list the registered devices. There is currently
no way to control the devices. Use this lib with extreme care and do NOT
use it for production environments right now. 

## TODO's

- **basics** - writing some code to control registered devices
there is a lot of work to be done. The lib is in a pre-alpha stage and just can connect to the api and list some things.
