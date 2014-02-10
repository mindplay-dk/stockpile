<?php

require __DIR__ . '/mindplay/stockpile/Container.php';
require __DIR__ . '/mindplay/stockpile/ContainerException.php';

use mindplay\stockpile\Container;

class TestDummy
{
    public $configured = false;
}

class ConsumerDummy
{
    public $int;

    public $injected_string;

    public function inject($string)
    {
        $this->injected_string = $string;
    }
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
                return TestContainer::EXPECTED_INT;
            }
        );

        // $dummy deliberately left uninitialized for exception tests
    }
}

test(
    'Container behavior',
    function () {
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
                $got_dependency = ($int === TestContainer::EXPECTED_INT); // dependency resolution
            }
        );
        $c->seal();

        ok('can perform late initialization', $c->dummy instanceof TestDummy);
        ok('can perform late configuration', $c->dummy->configured);
        ok('can inject dependency', $got_dependency);
    }
);

test(
    'Dependency Injection',
    function () {
        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        $consumer = new ConsumerDummy();

        $container->inject($consumer);

        eq('can inject public property', $container->int, TestContainer::EXPECTED_INT);

        $STRING_OVERRIDE = 'string_override';

        $container->invoke(
            function ($int, $string) use (&$injected_int, &$injected_string) {
                $injected_int = $int;
                $injected_string = $string;
            },
            array(
                'string' => $STRING_OVERRIDE,
            )
        );

        eq('can inject argument to closure', $injected_int, TestContainer::EXPECTED_INT);
        eq('can override argument to closure', $injected_string, $STRING_OVERRIDE);

        $container->invoke(array($consumer, 'inject'));

        eq('can inject argument to method', $consumer->injected_string, TestContainer::EXPECTED_STRING);
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

exit(status());

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
        ok("UNEXPECTED EXCEPTION:\n\n$e", false);
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
        status(false);
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

/**
 * @param bool|null $status test status
 * @return int number of failures
 */
function status($status = null) {
    static $failures = 0;
    
    if ($status === false) {
        $failures += 1;
    }
    
    return $failures;
}
