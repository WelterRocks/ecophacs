#!/bin/sh
vendor/bin/phpcs . ./phpcs.xml
vendor/bin/phpmd ./src,./tests text ./phpmd.xml
vendor/bin/phpunit --coverage-xml ./phpunit.xml
