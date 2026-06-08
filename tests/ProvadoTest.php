<?php

namespace Mquevedob\Provado\Tests;

use Mquevedob\Provado\Provado;
use PHPUnit\Framework\TestCase;

class ProvadoTest extends TestCase
{
    public function test_package_entry_class_can_be_instantiated(): void
    {
        $provado = new Provado();

        $this->assertInstanceOf(Provado::class, $provado);
    }
}
