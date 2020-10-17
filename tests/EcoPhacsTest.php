<?php

use WelterRocks\EcoPhacs\CLI;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    use CLI;

    public function testProcessTitle()
    {
        $expected = $this->set_process_title("EcoPhacsTest");
        $this->assertEquals($expected, $this->get_process_title());
    }
}

