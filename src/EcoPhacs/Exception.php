<?php namespace WelterRocks\EcoPhacs;

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

use WelterRocks\EcoPhacs\CLI;

final class Exception extends \exception
{
    private $affected_class = null;
    private $suppress_cli_log = null;
    private $cli = null;
    
    public function getAffectedClass()
    {
        return $this->affected_class;
    }
    
    public function getCli()
    {
        return $this->cli;
    }
    
    public function getSuppressCliLog()
    {
        return $this->suppress_cli_log;
    }
    
    function __construct(string $message, int $code = 0, object $affected_class = null, CLI $cli = null, bool $suppress_cli_log = false, Throwable $previous = null)
    {
        $this->affected_class = $class;
        $this->cli = $cli;
        $this->suppress_cli_log = $suppress_cli_log;
        
        parent::__construct($message, $code, $previous);

        if ((!$this->suppress_cli_log) && (is_object($this->cli)))
        {
            $this->cli->log("Exception occured. File: ".$this->getFile().", Line: ".$this->getLine().", Code: ".$code.", Message: ".$message, CLI::LOG_EMERG);
        }        
    }
}
