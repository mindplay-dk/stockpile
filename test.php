<?php

require __DIR__ . '/mindplay/stockpile/Container.php';
require __DIR__ . '/mindplay/stockpile/ContainerException.php';

use mindplay\stockpile\Container;

class TestDummy
{
    public $configured = false;
}

/**
 * @property string $string
 * @property int $int
 * @property TestDummy $dummy
 */
class TestContainer extends Container
{
    const EXPECTED_STRING = 'hello world';
    const EXPECTED_INT = 123;

    protected function init()
    {
        // eager initialization:
        $this->string = self::EXPECTED_STRING;

        // late initialization:
        $this->register(
            'int',
            function () {
                return self::EXPECTED_INT;
            }
        );

        // $dummy deliberately left uninitialized for exception tests
    }
}

test(
    'Container behavior',
    function() {
        $c = new TestContainer;
        $dummy = $c->dummy = new TestDummy;
        $c->seal();

        eq('can get string', $c->string, TestContainer::EXPECTED_STRING);
        eq('can get int', $c->int, TestContainer::EXPECTED_INT);
        eq('can get object', $c->dummy, $dummy);

        $c = new TestContainer;
        $c->register(
            'dummy',
            function () {
                return new TestDummy;
            }
        );
        $got_dependency = false;
        $c->configure(
            function ($dummy, $int) use (&$got_dependency) {
                $dummy->configured = true; // late configuration
                $got_dependency = true; // dependency resolution
            }
        );
        $c->seal();

        ok('can perform late initialization', $c->dummy instanceof TestDummy);
        ok('can perform late configuration', $c->dummy->configured);
        ok('can inject dependency', $got_dependency);
    }
);

test(
    'Expected Exceptions',
    function() {
        $EXPECTED = 'mindplay\stockpile\ContainerException';

        $c = new TestContainer;

        expect(
            'should throw if sealed while incomplete',
            $EXPECTED,
            function () use ($c) {
                $c->seal(); // $dummy deliberately left uninitialized
            }
        );

        $c = new TestContainer;

        expect(
            'should throw on attempted access to uninitialized value',
            $EXPECTED,
            function () use ($c) {
                $value = $c->int;
            }
        );

        $c = new TestContainer;

        expect(
            'should throw on attempted access to register twice',
            $EXPECTED,
            function () use ($c) {
                $c->register('int',
                    function() {
                        return 456; // will fail because $int is already registered
                    }
                );
            }
        );

        $c = new TestContainer;
        $c->dummy = new TestDummy;

        expect(
            'should throw on attempted to overwrite registered property',
            $EXPECTED,
            function () use ($c) {
                $c->int = 456; // will fail because container is sealed
            }
        );

        $c = new TestContainer;
        $c->dummy = new TestDummy;
        $c->seal();

        expect(
            'should throw on attempted access to sealed container',
            $EXPECTED,
            function () use ($c) {
                $c->int = 456; // will fail because container is sealed
            }
        );

        $c = new TestContainer;
        $c->dummy = new TestDummy;
        $c->seal();

        expect(
            'should throw on attempt to seal container twice',
            $EXPECTED,
            function () use ($c) {
                $c->seal();; // will fail because container is already sealed
            }
        );

        $c = new TestContainer;
        $c->dummy = new TestDummy;

        expect(
            'should throw on attempt to configure an undefined property',
            $EXPECTED,
            function () use ($c) {
                $c->configure(function($nonsense) {}); // will fail because container is already sealed
            }
        );

        $c = new TestContainer;

        expect(
            'should throw on violation of string type-check',
            $EXPECTED,
            function () use ($c) {
                $c->string = 123; // not a string!
            }
        );

        $c = new TestContainer;

        expect(
            'should throw on violation of object type-check',
            $EXPECTED,
            function () use ($c) {
                $c->dummy = 'not even an object!';
            }
        );
    }
);

// https://gist.github.com/mindplay-dk/4260582

/**
 * @param string   $name     test description
 * @param callable $function test implementation
 */
function test($name, Closure $function)
{
    echo "\n=== $name ===\n\n";

    try {
        $function();
    } catch (Exception $e) {
        echo "\n*** TEST FAILED ***\n\n$e\n";
    }
}

/**
 * @param string $text   description of assertion
 * @param bool   $result result of assertion
 * @param mixed  $value  optional value (displays on failure)
 */
function ok($text, $result, $value = null)
{
    if ($result === true) {
        echo "- PASS: $text\n";
    } else {
        echo "# FAIL: $text" . ($value === null ? '' : ' (' . (is_string($value) ? $value : var_export($value, true)) . ')') . "\n";
    }
}

/**
 * @param string $text   description of assertion
 * @param mixed  $value  value
 * @param mixed  $value  expected value
 */
function eq($text, $value, $expected) {
    ok($text, $value === $expected, "expected: " . var_export($expected, true) . ", got: " . var_export($value, true));
}

/**
 * @param string   $text           description of assertion
 * @param string   $exception_type Exception type name
 * @param callable $function       function expected to throw
 */
function expect($text, $exception_type, Closure $function)
{
    try {
        $function();
    } catch (Exception $e) {
        if ($e instanceof $exception_type) {
            ok("$text (ok: \"{$e->getMessage()}\")", true);
            return;
        } else {
            $actual_type = get_class($e);
            ok("$text (expected $exception_type but $actual_type was thrown)", false);
            return;
        }
    }

    ok("$text (expected exception $exception_type was NOT thrown)", false);
}