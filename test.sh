#!/bin/sh
vendor/bin/phpcs . ./phpcs.xml
vendor/bin/phpmd ./src,./tests text ./phpmd.xml
