<?php

namespace benchmark;

use mindplay\benchpress\Benchmark;
use mindplay\stockpile\Container;

require __DIR__ . '/header.php';

/**
 * @property string $string
 * @property int $int
 * @property bool $bool
 * @property TestDummy $dummy
 */
class TestContainer extends Container
{
    protected function init()
    {
        $this->string = 'string';
        $this->int = 123;
        $this->bool = true;
        $this->register('dummy', function () {
            return new TestDummy();
        });
    }
}
/**
 * @property string $string1
 * @property string $string2
 * @property string $string3
 * @property string $string4
 * @property string $string5
 * @property string $string6
 * @property string $string7
 * @property string $string8
 * @property string $string9
 * @property string $string10
 * @property string $string11
 * @property string $string12
 * @property string $string13
 * @property string $string14
 * @property string $string15
 * @property string $string16
 * @property string $string17
 * @property string $string18
 * @property string $string19
 * @property string $string20
 */
class LongContainer extends Container
{
    protected function init()
    {
        $this->string1 = 'foo';
        $this->string2 = 'foo';
        $this->string3 = 'foo';
        $this->string4 = 'foo';
        $this->string5 = 'foo';
        $this->string6 = 'foo';
        $this->string7 = 'foo';
        $this->string8 = 'foo';
        $this->string9 = 'foo';
        $this->string10 = 'foo';
        $this->string11 = 'foo';
        $this->string12 = 'foo';
        $this->string13 = 'foo';
        $this->string14 = 'foo';
        $this->string15 = 'foo';
        $this->string16 = 'foo';
        $this->string17 = 'foo';
        $this->string18 = 'foo';
        $this->string19 = 'foo';
        $this->string20 = 'foo';
    }
}

class TestDummy
{}

$bench = new Benchmark();

$bench->add(
    'configuration overhead, 4 components',
    function () {
        $container = new TestContainer();
        $container->seal();
    }
);

$bench->add(
    'configuration plus initialization',
    function () {
        $container = new TestContainer();
        $container->seal();
        $string = $container->string;
        $int = $container->int;
        $bool = $container->bool;
        $dummy = $container->dummy;
    }
);

$bench->add(
    'configuration overhead, 20 components',
    function () {
        $container = new LongContainer();
        $container->seal();
    }
);

$bench->run();
