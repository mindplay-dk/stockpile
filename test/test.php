<?php

namespace test;

use mindplay\filereflection\CacheProvider;
use mindplay\stockpile\AbstractContainer;
use mindplay\stockpile\Container;
use PHP_CodeCoverage_Report_Text;
use PHP_CodeCoverage_Report_Clover;

require __DIR__ . '/header.php';

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

/**
 * Deliberately has no property-annotations
 */
class EmptyContainer extends Container
{
    protected function init()
    {}
}

/**
 * @property bool $base
 */
class BaseContainer extends Container
{
    protected function init()
    {
        $this->base = true;
    }
}

/**
 * @property bool $extended
 */
class ExtendedContainer extends BaseContainer
{
    protected function init()
    {
        parent::init();

        $this->extended = true;
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

class MockCache implements CacheProvider
{
    public $data = array();

    public function read($key, $timestamp, $refresh)
    {
        return isset($this->data[$key])
            ? $this->data[$key]
            : $this->data[$key] = $refresh();
    }
}

class CachedTestContainer extends TestContainer
{
    /** @var MockCache */
    public $cache;

    protected function getCache()
    {
        return $this->cache = new MockCache();
    }
}

if (coverage()) {
    $filter = coverage()->filter();

    $filter->addDirectoryToWhitelist(dirname(__DIR__) . '/src');

    coverage()->start('test');
}

test(
    'Container behavior',
    function () {
        $container = new TestContainer;

        eq($container->isDefined('dummy'), true, 'correctly reports component as defined [1]');
        eq(isset($container->dummy), true, 'correctly reports component as defined [2]');
        eq($container->isDefined('bunk'), false, 'correctly reports component as not defined [1]');
        eq(isset($container->bunk), false, 'correctly reports component as not defined [2]');
        eq($container->isRegistered('dummy'), false, 'correctly reports component as not registered');

        $dummy = $container->dummy = new TestDummy;

        eq($container->isRegistered('dummy'), true, 'correctly reports component as registered [1]');
        eq(isset($container->dummy), true, 'correctly reports component as registered [2]');

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

        $container = new TestContainer(__DIR__);

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

        $container = new TestContainer();

        $dummy_one = new TestDummy();
        $dummy_two = new TestDummy();

        $container->dummy = $dummy_one;

        $container->unregister('dummy');

        ok($container->isRegistered('dummy') === false, 'component has been unregistered');

        $container->register('dummy', function () use ($dummy_two) {
            return $dummy_two;
        });

        ok(invoke($container, 'isSealed') === false, "container has not yet been sealed");
        $container->seal();
        ok(invoke($container, 'isSealed') === true, "container has been sealed");

        eq($container->dummy, $dummy_two, 'component has been replaced');
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

        expect(
            $EXPECTED,
            'should throw on attempt to register an undefined component',
            function () use ($container) {
                // will fail because the component is undefined:
                $container->register(
                    'undefined',
                    function () {
                        return new TestDummy;
                    }
                );
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

        $container = new TestContainer;
        $container->register(
            'dummy',
            function () {
                return new TestDummy;
            }
        );
        expect(
            $EXPECTED,
            'should throw on attempted unregister() of undefined component',
            function () use ($container) {
                $container->unregister('nothing');
            }
        );
        $container->seal();
        expect(
            $EXPECTED,
            'should throw on attempted unregister() after seal()',
            function () use ($container) {
                $container->unregister('dummy');
            }
        );

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

        expect(
            $EXPECTED,
            'should throw on missing property-annotations',
            function () {
                $container = new EmptyContainer();
            }
        );
    }
);

test(
    'can inherit property annotations',
    function () {
        $container = new ExtendedContainer();

        $container->seal();

        ok($container->base, 'can get component inherited from base class');
        ok($container->extended, 'can get component from own class');
    }
);

test(
    'can cache parsed type information',
    function () {
        $container = new CachedTestContainer();

        $container->dummy = new TestDummy();

        $container->seal();

        eq(
            $container->cache->data,
            array(
                'test\CachedTestContainer' => array(
                    'string' => 'string',
                    'int'    => 'int',
                    'dummy'  => '\test\TestDummy',
                    'loaded' => 'string',
                )
            ),
            'property annotations were cached'
        );
    }
);

if (coverage()) {
    coverage()->stop();

    $report = new PHP_CodeCoverage_Report_Text(50, 90, false, false);

    echo $report->process(coverage(), false);

    $report = new PHP_CodeCoverage_Report_Clover();

    $report->process(coverage(), dirname(__DIR__) . '/build/logs/clover.xml');
}

exit(status());
