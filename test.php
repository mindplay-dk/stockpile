<?php

namespace test;

use Closure;
use Exception;

use mindplay\stockpile\AbstractContainer;
use PHP_CodeCoverage;
use PHP_CodeCoverage_Exception;
use PHP_CodeCoverage_Report_Text;
use PHP_CodeCoverage_Report_Clover;

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->add('mindplay\stockpile', __DIR__);

use mindplay\stockpile\Container;
use ReflectionClass;

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

class TestCustomContainer extends AbstractContainer
{
    protected function init()
    {}

    /**
     * Exposes the define() method for test-cases
     */
    public function doDefine($name, $type)
    {
        $this->define($name, $type);
    }

    /**
     * Exposes the set() method for test-cases
     */
    public function doSet($name, $value)
    {
        $this->set($name, $value);
    }

    /**
     * Exposes the get() method for test-cases
     */
    public function doGet($name)
    {
        return $this->get($name);
    }

    /**
     * Exposes the checkType() method for test-cases
     */
    public function doCheckType($name, $value)
    {
        $this->checkType($name, $value);
    }
}

if (coverage()) {
    coverage()->filter()->addDirectoryToWhitelist(__DIR__ . '/mindplay/stockpile');
    coverage()->start('test');
}

test(
    'Container behavior',
    function () {
        $container = new TestContainer;

        eq($container->isDefined('dummy'), true, 'correctly reports component as defined');
        eq($container->isDefined('bunk'), false, 'correctly reports component as not defined');
        eq($container->isRegistered('dummy'), false, 'correctly reports component as not registered');

        $dummy = $container->dummy = new TestDummy;

        eq($container->isRegistered('dummy'), true, 'correctly reports component as registered');

        $container->seal();

        eq($container->getRootPath(), getcwd(), 'can get root path');
        eq($container->string, TestContainer::EXPECTED_STRING, 'can get string');
        eq($container->isActive('int'), false, 'correctly reports component as inactive');
        eq($container->int, TestContainer::EXPECTED_INT, 'can get int');
        eq($container->isActive('int'), true, 'correctly reports component as active');
        eq($container->dummy, $dummy, 'can get object');

        $container = new TestContainer();
        $container->dummy = null; // should bypass type-check
        $container->seal(); // would throw without the above initialization

        ok(true, 'type-checking is bypassed when component is explicitly set to null');

        $container = new TestContainer();

        $container->load('test.config.php');

        eq($container->loaded, TestContainer::EXPECTED_LOADED, 'can load external configuration file');

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

        ok($container->dummy instanceof TestDummy, 'can perform late initialization');
        ok($container->dummy->configured, 'can perform late configuration');
        ok($got_dependency, 'can inject dependency');

        unset($container); // triggers shutdown function

        ok($shut_down === true, 'can perform shutdown function');
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

        eq($got_expected_null, true, 'skips initialization of optional dependency during configuration');

        $container->invoke(
            function ($string, $int = null) use (&$expected_null) {
                $expected_null = $int;
            }
        );

        eq($expected_null, null, 'skips initialization of optional dependency on invokation');

        $container->invoke(
            function ($default = 'DEFAULT') use (&$expected_default) {
                $expected_default = $default;
            }
        );

        eq($expected_default, 'DEFAULT', 'completes missing argument using default value');

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

        eq($injected_int, TestContainer::EXPECTED_INT, 'can inject argument to closure');
        eq($injected_string, $STRING_OVERRIDE, 'can override argument to closure');

        $container->invoke(array($consumer, 'inject'));

        eq($consumer->injected_string, TestContainer::EXPECTED_STRING, 'can inject argument to method');
    }
);

test(
    'Expected Exceptions',
    function() {
        $EXPECTED = 'mindplay\stockpile\ContainerException';

        $container = new TestContainer;

        expect(
            $EXPECTED,
            'should throw if sealed while incomplete',
            function () use ($container) {
                $container->seal(); // $dummy deliberately left uninitialized
            }
        );

        $container = new TestContainer;

        expect(
            $EXPECTED,
            'should throw on attempted access to component in unsealed container',
            function () use ($container) {
                $value = $container->int;
            }
        );

        $container = new TestContainer;

        expect(
            $EXPECTED,
            'should throw on attempted access to register twice',
            function () use ($container) {
                $container->register('int',
                    function () {
                        return 456; // will fail because $int is already registered
                    }
                );
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on attempted overwrite of registered property',
            function () use ($container) {
                $container->int = 456; // will fail because container is sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            $EXPECTED,
            'should throw on attempted direct access to sealed container',
            function () use ($container) {
                $container->int = 456; // will fail because container is sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            $EXPECTED,
            'should throw on attempted registration in sealed container',
            function () use ($container) {
                // will fail because container is sealed:
                $container->register(
                    'int',
                    function () {
                        return 456;
                    }
                );
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on attempted registration after direct access to sealed container',
            function () use ($container) {
                // will fail because container is sealed:
                $container->register(
                    'dummy',
                    function () {
                        return new TestDummy();
                    }
                );
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            $EXPECTED,
            'should throw on attempt to configure component after sealing',
            function () use ($container) {
                $container->configure(function ($dummy) {}); // will fail because container is already sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw for invalid configuration function',
            function () use ($container) {
                $container->configure(function () {}); // will fail because the closure takes no arguments
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;
        $container->seal();

        expect(
            $EXPECTED,
            'should throw on attempt to seal container twice',
            function () use ($container) {
                $container->seal(); // will fail because container is already sealed
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on attempt to configure an undefined component',
            function () use ($container) {
                // will fail because the component is undefined:
                $container->configure(
                    function ($nonsense) {
                    }
                );
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on attempt to check if an undefined component is active',
            function () use ($container) {
                $container->isActive('blah'); // will fail because component is undefined
            }
        );

        $container = new TestContainer;

        expect(
            $EXPECTED,
            'should throw on violation of string type-check',
            function () use ($container) {
                $container->string = 123; // not a string!
            }
        );

        $container = new TestContainer;

        expect(
            $EXPECTED,
            'should throw on violation of object type-check',
            function () use ($container) {
                $container->dummy = 'not even an object!';
            }
        );

        $container = new TestCustomContainer();
        $container->doDefine('strings', 'string[]');
        $container->doSet('strings', array('foo', 'bar'));
        $container->seal();

        eq($container->doGet('strings'), array('foo', 'bar'), 'value passed shallow type-checking for arrays');

        $container = new TestCustomContainer();
        $container->doDefine('strings', 'string[]');

        expect(
            $EXPECTED,
            'should throw on violation of shallow array type-check',
            function () use ($container) {
                $container->doSet('strings', 'not_an_array');
            }
        );

        expect(
            $EXPECTED,
            'should throw on type-check against undefined component',
            function () use ($container) {
                $container->doCheckType('not_there', null);
            }
        );

        $container->doDefine('anything', 'mixed');
        $container->doSet('anything', 'value');
        eq($container->doGet('anything'), 'value', 'string value passes a "mixed" type check');

        $container = new TestContainer();
        $container->register('dummy', function () {
            return null; // should trigger an exception
        });
        $container->seal();

        expect(
            $EXPECTED,
            'type-checking is enabled when initialization function returns null',
            function () use ($container) {
                $null = $container->dummy; // initialization function returns null
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on attempt to load missing configuration file',
            function () use ($container) {
                $container->load('foo.bar');
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on missing argument to invoke()',
            function () use ($container) {
                $container->invoke(
                    function (array $foo_bar) {
                    }
                );
            }
        );

        $container = new TestContainer;
        $container->dummy = new TestDummy;

        expect(
            $EXPECTED,
            'should throw on invalid argument to invoke()',
            function () use ($container) {
                $container->invoke((object)array());
            }
        );

        $container = new TestCustomContainer();
        $container->doDefine('dupe', 'string');

        expect(
            $EXPECTED,
            'should throw on duplicate define()',
            function () use ($container) {
                $container->doDefine('dupe', 'string');
            }
        );
    }
);

if (coverage()) {
    coverage()->stop();

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
function test($name, $function)
{
    echo "\n=== $name ===\n\n";

    try {
        call_user_func($function);
    } catch (Exception $e) {
        ok(false, "UNEXPECTED EXCEPTION", $e);
    }
}

/**
 * @param bool   $result result of assertion
 * @param string $why    description of assertion
 * @param mixed  $value  optional value (displays on failure)
 */
function ok($result, $why, $value = null)
{
    if ($result === true) {
        echo "- PASS: " . ($why === null ? 'OK' : $why) . ($value === null ? '' : ' (' . format($value) . ')') . "\n";
    } else {
        echo "# FAIL: " . ($why === null ? 'ERROR' : $why) . ($value === null ? '' : ' - ' . format($value, true)) . "\n";
        status(false);
    }
}

/**
 * @param mixed  $value    value
 * @param mixed  $expected expected value
 * @param string $why      description of assertion
 */
function eq($value, $expected, $why)
{
    $result = $value === $expected;

    $info = $result
        ? format($value)
        : "expected: " . format($expected, true) . ", got: " . format($value, true);

    ok($result, ($why === null ? $info : "$why ($info)"));
}

/**
 * @param string   $exception_type Exception type name
 * @param string   $why            description of assertion
 * @param callable $function       function expected to throw
 */
function expect($exception_type, $why, $function)
{
    try {
        call_user_func($function);
    } catch (Exception $e) {
        if ($e instanceof $exception_type) {
            ok(true, $why, $e);
            return;
        } else {
            $actual_type = get_class($e);
            ok(false, "$why (expected $exception_type but $actual_type was thrown)");
            return;
        }
    }

    ok(false, "$why (expected exception $exception_type was NOT thrown)");
}

/**
 * @param mixed $value
 * @param bool  $verbose
 *
 * @return string
 */
function format($value, $verbose = false)
{
    if ($value instanceof Exception) {
        return get_class($value) . ": \"" . $value->getMessage() . "\"";
    }

    if (! $verbose && is_array($value)) {
        return 'array[' . count($value) . ']';
    }

    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }

    if (is_object($value) && !$verbose) {
        return get_class($value);
    }

    return print_r($value, true);
}

/**
 * @param bool|null $status test status
 *
 * @return int number of failures
 */
function status($status = null)
{
    static $failures = 0;

    if ($status === false) {
        $failures += 1;
    }

    return $failures;
}

/**
 * @return PHP_CodeCoverage|null code coverage service, if available
 */
function coverage()
{
    static $coverage = null;

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

    return $coverage;
}

/**
 * Invoke a protected or private method (by means of reflection)
 *
 * @param object $object      the object on which to invoke a method
 * @param string $method_name the name of the method
 * @param array  $arguments   arguments to pass to the function
 *
 * @return mixed the return value from the function call
 */
function invoke($object, $method_name, $arguments = array())
{
    $class = new ReflectionClass(get_class($object));

    $method = $class->getMethod($method_name);

    $method->setAccessible(true);

    return $method->invokeArgs($object, $arguments);
}
