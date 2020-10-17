<?php

use WelterRocks\EcoPhacs\CLI;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function testProcessTitle()
    {
        $cli = new CLI("EcoPhacsTest");
        
        $expected = $cli->set_process_title("EcoPhacsTest");
        $this->assertEquals($expected, $cli->get_process_title());
    }
}

