#!/bin/sh

################################################################################
#
#    EcoPhacs is a php class to control ecovacs api based devices
#    Copyright (C) 2020  Oliver Welter  <oliver@welter.rocks>
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program.  If not, see <https://www.gnu.org/licenses/>.
#
################################################################################

awk=`which awk`
cut=`which cut`
php=`which php`

if [ "$php" = "" ]; then
	echo "PHP >= 7.3 is required"
	exit 1
fi

php_has_version=`echo "<?php if (version_compare(phpversion(), '7.3', '<')) echo '0'; else echo '1';?>" | $php`

if [ "$php_has_version" = "0" ]; then
	echo "PHP version has to be at least 7.3"
	exit 2
fi

php_version=`echo '<?php $version = explode(".", PHP_VERSION); echo $version[0].".".$version[1]."\n";' | $php`
php_version_str=`echo "php-$php_version"`

echo "Found PHP version $php_version at $php"

install_to="/usr/local/lib/$php_version_str/ecophacs"

composer=`which composer`

if [ "$composer" = "" ]; then
	curl=`which curl`
	wget=`which wget`
	composer_target="composer-setup.php"
	composer_url="https://getcomposer.org/installer"
	composer_downloaded=0
	
	[ -e "$composer_target" ] && composer_downloaded=1
	
	if [ "$composer_downloaded" = "0" ]; then	
		if [ "$curl" = "" ]; then
			composer_downloaded=`$curl "$composer_url" > "$composer_target" 2>/dev/null && echo 1`
		elif [ "$wget" = "" ]; then
			composer_downloaded=`$wget -O "$composer_target" "$composer_url" 2>/dev/null && echo 1`
		else
			echo "WGET or CURL is required, please install one of them"
			exit 3
		fi
		
		if [ "$composer_downloaded" = "0" ]; then
			echo "Unable to download composer. Please install it manually to '$composer_target'."
			echo "You can download composer at '$composer_url'"
		fi
	fi
	
	if [ "$composer_downloaded" = "0" ]; then
		$php "$composer_target" && rm -f "$composer_target"
		[ -e "./composer.phar" ] && mv composer.phar composer
		[ -e "./composer" ] && composer="./composer"
	else
		echo "Still no composer. Giving up."
		exit 4
	fi
fi

export composer_version=`$composer -V --no-ansi 2>/dev/null | $cut -d \  -f2`
composer_check_version=`echo '<?php $composer_version=explode(".", getenv("composer_version")); $composer_version=(double)$composer_version[0].".".$composer_version[1]; if ($composer_version >= 1.10) echo "1"; else echo "0";' | $php`

if [ "$composer_check_version" = "0" ]; then
	echo "Composer >= 1.10 is required, consider 'composer self-update'"
	exit 5
fi

echo "Found Composer version $composer_version at $composer"

if [ -e "$install_to/install.sh" ]; then
	echo "Alread found install.sh at destination '$install_to'."
	echo "Please clean destination path first, to use this installer."
	exit 6
fi

install_status=0

echo -n "Installing to ${install_to}..."
mkdir -p "$install_to" >/dev/null 2>&1 && cp -a . "$install_to" >/dev/null 2>&1 && echo "done" && install_status=1 || echo "failed"

if [ "$install_status" = "0" ]; then
	echo "Installation aborted. Check permissions for '${install_to}'"
	exit 7
fi

systemctl=`which systemctl`

if [ "$systemctl" != "" ]; then
	systemd_running=`$systemctl status systemd-sysctl >/dev/null 2>&1 && echo 1`
	systemd_lib_path=`ls -1 /lib/systemd/system/systemd-sysctl.service >/dev/null 2>&1 && echo 1`
	
	if [ "$systemd_running" = "1" -a "$systemd_lib_path" = "1" ]; then
		echo -n "Installing systemd services..."
		cp -a systemd/system/*.service /lib/systemd/system/ >/dev/null 2>&1 && $systemctl daemon-reload >/dev/null 2>&1 && echo "done" || echo "failed"
	fi
fi

echo "Installing requirements in $install_to"
cd $install_to
$composer install
