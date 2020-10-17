<?php

use WelterRocks\EcoPhacs\CLI;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function testProcessTitle()
    {
        if (function_exists("pcntl_getpriority"))
        {
            $cli = new CLI("EcoPhacsTest");
        
            $expected = $cli->set_process_title("EcoPhacsTest");
            $this->assertEquals($expected, $cli->get_process_title());
        }
        else
        {
            // Missing PCNTL (currently on appveyor), do not test CLI
            $this->assertEquals(true, true);
        }
    }
}

