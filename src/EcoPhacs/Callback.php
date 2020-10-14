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

final class Callback
{
    private $identifier = null;
    private $priority = null;
    
    private $exec_class = null;
    private $exec_method = null;
    private $exec_arguments = null;
    
    private $is_shell_exec = null;
    
    private $timestamp_registration = null;
    private $timestamp_exec_begin = null;
    private $timestamp_exec_end = null;
    
    private $error_message = null;
    private $return_value = null;
    private $exit_code = null;
    
    public function execute()
    {
        $this->timestamp_exec_begin = round((microtime(true) * 1000));
        
        $this->return_value = null;
        $this->exit_code = null;
        
        $retval = null;
        
        if ($this->is_shell_exec)
        {
            if ($this->exec_class)
                $interpreter = escapeshellcmd($this->exec_class);
            else
                $interpreter = "";
                
            if ($this->exec_method)
                $command = escapeshellcmd($this->exec_method);
            else
                $command = "";
                
            $arguments = "";
                
            if ($this->exec_arguments)
            {
                foreach ($exec_arguments as $arg)
                {
                    $arguments .= " ".escapeshellarg($arg);
                }
            }
            
            $commandline = $interpreter.$command.$arguments;
            
            unset($interpreter);
            unset($arguments);
            unset($command);
            
            $retval = @exec($commandline, $this->return_value, $this->exit_code);
        }
        elseif ($this->exec_class)
        {
            if (!class_exists($this->exec_class))
            {
                $this->error_message = "Class not found";
                
                return $retval;
            }
            
            if (!method_exists($this->exec_class, $this->exec_method))
            {
                $this->error_message = "Method not found";
                
                return $retval;
            }
            
            $retval = call_user_func_array(array($this->exec_class, $this->exec_method), $this->exec_arguments);
        }
        else
        {
            if (!function_exists($this->exec_method))
            {
                $this->error_message = "Function not found";
                
                return $retval;
            }
            
            $retval = call_user_func($this->exec_method, $this->exec_arguments);
        }
        
        $this->timestamp_exec_end = round((microtime(true) * 1000));
        
        return $retval;
    }
    
    function __construct($identifier, $priority, $exec_method, $exec_arguments = null, $exec_class = null, $is_shell_exec = null)
    {
        $this->identifier = $identifier;
        $this->priority = $priority;
        
        $this->exec_class = $exec_class;
        $this->exec_method = $exec_method;
        $this->exec_arguments = ((!is_array($exec_arguments)) ? explode(" ", $exec_arguments) : $exec_arguments);
        
        $this->is_shell_exec = (($is_shell_exec) ? true : false);
        
        $this->timestamp_registration = round((microtime(true) * 1000));
    }
    
    function __set($key, $val)
    {
        $this->$key = $val;
    }
    
    function __get($key)
    {
        if (!isset($this->$key))
            return null;
            
        return $this->$key;
    }
}
