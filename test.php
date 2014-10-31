<?php

namespace test;

use Closure;
use Exception;

use PHP_CodeCoverage;
use PHP_CodeCoverage_Exception;
use PHP_CodeCoverage_Report_Text;
use PHP_CodeCoverage_Report_Clover;

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->add('mindplay\stockpile', __DIR__);

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
 * @property string $loaded
 */
class TestContainer extends Container
{
    const EXPECTED_STRING = 'hello world';
    const EXPECTED_INT = 123;
    const EXPECTED_LOADED = 'loaded';

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

        // $loaded initialized as null for load() test:

        $this->loaded = null;

        // $dummy deliberately left uninitialized for exception tests
    }
}

if (coverage()) {
    coverage('stockpile')->filter()->addDirectoryToWhitelist(__DIR__ . '/mindplay/stockpile');
}

test(
    'Container behavior',
    function () {
        $container = new TestContainer;

        eq('correctly reports component as defined', $container->isDefined('dummy'), true);
        eq('correctly reports component as not defined', $container->isDefined('bunk'), false);
        eq('correctly reports component as not registered', $container->isRegistered('dummy'), false);

        $dummy = $container->dummy = new TestDummy;

        eq('correctly reports component as registered', $container->isRegistered('dummy'), true);

        $container->seal();

        eq('can get root path', $container->getRootPath(), getcwd());
        eq('can get string', $container->string, TestContainer::EXPECTED_STRING);
        eq('correctly reports component as inactive', $container->isActive('int'), false);
        eq('can get int', $container->int, TestContainer::EXPECTED_INT);
        eq('correctly reports component as active', $container->isActive('int'), true);
        eq('can get object', $container->dummy, $dummy);

        $container = new TestContainer();
        $container->dummy = null; // should bypass type-check
        $container->seal(); // would throw without the above initialization

        ok('type-checking is bypassed when component is explicitly set to null', true);

        $container = new TestContainer();

        $container->load('test.config.php');

        eq('can load external configuration file', $container->loaded, TestContainer::EXPECTED_LOADED);

        $container->register(
            'dummy',
            function () {
                return new TestDummy;
            }
        );

        $got_dependency = false;

        $container->configure(
            function ($dummy, $int) use (&$got_dependency) {
                $dummy->configured = true; // late configuration
                $got_dependency = ($int === TestContainer::EXPECTED_INT); // dependency resolution
            }
        );

        $shut_down = false;

        $container->shutdown(
            function ($dummy) use (&$shut_down) {
                $shut_down = true;
            }
        );

        $container->seal();

        ok('can perform late initialization', $container->dummy instanceof TestDummy);
        ok('can perform late configuration', $container->dummy->configured);
        ok('can inject dependency', $got_dependency);

        unset($container); // triggers shutdown function

        ok('can perform shutdown function', $shut_down === true);
    }
);

test(
    'Dependency Injection',
    function () {
        $container = new TestContainer;
        $container->dummy = new TestDummy;

        $container->configure(
            function ($string, $int = null) use (&$got_expected_null) {
                $got_expected_null = ($int === null);
            }
        );

        $container->seal();

        eq('skips initialization of optional dependency during configuration', $got_expected_null, true);

        $container->invoke(
            function ($string, $int = null) use (&$expected_null) {
                $expected_null = $int;
            }
        );

        eq('skips initialization of optional dependency on invokation', $expected_null, null);

        $container->invoke(
            function ($default = 'DEFAULT') use (&$expected_default) {
                $expected_default = $default;
            }
        );

        eq('completes missing argument using default value', $expected_default, 'DEFAULT');

        $consumer = new ConsumerDummy();

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

        $container = new TestContainer;

        expect(
            'should throw if sealed while incomplete',
            $EXPECTED,
            function () use ($container) {
                $container->seal(); // $dummy deliberately left uninitialized
            }
        );

        $container = new TestContainer;

        expect(
            'should throw on attempted access to component in unsealed container',
            $EXPECTED,
            function () use ($container) {
                $value = $container->int;
            }
        );

        $container = new TestContainer;

        expect(
            'should throw on attempted access to register twice',
            $EXPECTED,
            function () use ($container) {
                $container->register('int',
                    function() {
                        return 456; // will fail because $int is already registered
                    }
                );
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            'should throw on attempted overwrite of registered property',
            $EXPECTED,
            function () use ($container) {
                $container->int = 456; // will fail because container is sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            'should throw on attempted direct access to sealed container',
            $EXPECTED,
            function () use ($container) {
                $container->int = 456; // will fail because container is sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            'should throw on attempted registration in sealed container',
            $EXPECTED,
            function () use ($container) {
                $container->register('int', function () { return 456; }); // will fail because container is sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            'should throw on attempted registration after direct access to sealed container',
            $EXPECTED,
            function () use ($container) {
                $container->register('dummy', function () { return new TestDummy(); }); // will fail because container is sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            'should throw on attempt to seal container twice',
            $EXPECTED,
            function () use ($container) {
                $container->seal(); // will fail because container is already sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            'should throw on attempt to configure an undefined component',
            $EXPECTED,
            function () use ($container) {
                $container->configure(function($nonsense) {}); // will fail because container is already sealed
            }
        );

        $container = new TestContainer;

        expect(
            'should throw on violation of string type-check',
            $EXPECTED,
            function () use ($container) {
                $container->string = 123; // not a string!
            }
        );

        $container = new TestContainer;

        expect(
            'should throw on violation of object type-check',
            $EXPECTED,
            function () use ($container) {
                $container->dummy = 'not even an object!';
            }
        );

        $container = new TestContainer();
        $container->register('dummy', function () {
            return null; // should trigger an exception
        });
        $container->seal();

        expect(
            'type-checking is enabled when initialization function returns null',
            $EXPECTED,
            function () use ($container) {
                $null = $container->dummy; // initialization function returns null
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            'should throw on attempt to load missing configuration file',
            $EXPECTED,
            function () use ($container) {
                $container->load('foo.bar');
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            'should throw on missing argument to invoke()',
            $EXPECTED,
            function () use ($container) {
                $container->invoke(function (array $foo_bar) {});
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            'should throw on invalid argument to invoke()',
            $EXPECTED,
            function () use ($container) {
                $container->invoke((object) array());
            }
        );

    }
);

if (coverage()) {
    $report = new PHP_CodeCoverage_Report_Text(50, 90, false, false);

    echo $report->process(coverage(), false);

    $report = new PHP_CodeCoverage_Report_Clover();

    $report->process(coverage(), 'build/logs/clover.xml');
}

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
 * @param string|null $text description (to start coverage); or null (to stop coverage)
 * @return PHP_CodeCoverage|null
 */
function coverage($text = null)
{
    static $coverage = null;
    static $running = false;

    if ($coverage === false) {
        return null; // code coverage unavailable
    }

    if ($coverage === null) {
        try {
            $coverage = new PHP_CodeCoverage;
        } catch (PHP_CodeCoverage_Exception $e) {
            echo "# Notice: no code coverage run-time available\n";
            $coverage = false;
            return null;
        }
    }

    if (is_string($text)) {
        $coverage->start($text);
        $running = true;
    } else {
        if ($running) {
            $coverage->stop();
            $running = false;
        }
    }

    return $coverage;
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
