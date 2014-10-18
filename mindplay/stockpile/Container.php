<?php

/**
 * stockpile
 * =========
 *
 * A strongly-typed service/configuration-container for PHP 5.3.
 *
 * @author Rasmus Schultz <rasmus@mindplay.dk>
 *
 * https://github.com/mindplay-dk/stockpile
 */

namespace mindplay\stockpile;

use Closure;
use ReflectionClass;
use ReflectionProperty;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionFunctionAbstract;

/**
 * Abstract base-class for service/configuration-containers.
 */
abstract class Container
{
    /**
     * Regular expression used to determine if a path is absolute.
     *
     * @see load()
     */
    const ABS_PATH_PATTERN = '/^(?:\/|\\\\|\w\:\\\\).*$/';

    /**
     * @var array map of component-names to type-names
     */
    private $_types = array();

    /**
     * @var Closure[] map of component-names to initialization closures
     *
     * @see register()
     */
    private $_init = array();

    /**
     * @var (Closure[])[] additional (late) configuration-functions.
     *                    map where component-name => Closure[]
     *
     * @see configure()
     */
    private $_config = array();

    /**
     * @var array map of component-names to initialized objects/values
     * @see get()
     */
    protected $_values = array();

    /**
     * @var bool true if the container has been sealed
     */
    private $_sealed = false;

    /**
     * @var (Closure[])[] list of closures to invoke when the container is destroyed.
     *                    map where component-name => Closure[]
     *
     * @see shutdown()
     */
    private $_shutdown = array();

    /**
     * @var string the root-path of configuration-files
     *
     * @see load()
     */
    private $_rootPath;

    /**
     * Map of methods to use for type-checking known PHP pseudo-types.
     *
     * @see http://www.phpdoc.org/docs/latest/for-users/types.html
     */
    public static $checkers = array(
        'string'   => 'is_string',
        'integer'  => 'is_int',
        'int'      => 'is_int',
        'boolean'  => 'is_bool',
        'bool'     => 'is_bool',
        'float'    => 'is_float',
        'double'   => 'is_float',
        'object'   => 'is_object',
        'array'    => 'is_array',
        'resource' => 'is_resource',
        'null'     => 'is_null',
        'callback' => 'is_callable',
    );

    /**
     * Initializes the container by calling the concrete init() implementation.
     *
     * @param string $rootPath the root-path of configuration-files, which can be loaded using {@see load()}
     */
    public function __construct($rootPath = null)
    {
        // configure root-path:

        $this->_rootPath = rtrim($rootPath === null ? getcwd() : $rootPath, '/\\');

        // initialize:

        $this->_init();
    }

    /**
     * @return string the root-path of configuration-files
     * @see load()
     */
    public function getRootPath()
    {
        return $this->_rootPath;
    }

    /**
     * Runs any shutdown-functions registered in the container.
     *
     * @see shutdown()
     */
    public function __destruct()
    {
        foreach ($this->_shutdown as $name => $functions) {
            if (array_key_exists($name, $this->_values)) {
                foreach ($functions as $function) {
                    $this->_invoke($function);
                }
            }
        }
    }

    /**
     * Initialize the container after construction.
     */
    protected function _init()
    {
        $this->init();
    }

    /**
     * Userland container initialization.
     */
    abstract protected function init();

    /**
     * Registers a Closure that initializes the component with the given name.
     *
     * @param string  $name name of component to register.
     * @param Closure $init a closure that creates and returns an object or value
     *                      of the type defined for the specified component
     *
     * @throws ContainerException
     */
    public function register($name, Closure $init)
    {
        if ($this->_sealed === true) {
            throw new ContainerException('attempted access to sealed configuration container');
        }

        if (array_key_exists($name, $this->_init)) {
            throw new ContainerException("component '{$name}' has already been registered for initialization");
        }

        if (array_key_exists($name, $this->_values)) {
            throw new ContainerException("component '{$name}' has already been initialized by direct assignment");
        }

        $this->_init[$name] = $init;
    }

    /**
     * Add a configuration-function to the container - the function will be called the first
     * time a registered component is accessed. Configuration-functions are called in the
     * order in which they were added.
     *
     * @param Closure|Closure[] $config a single configuration-function (a Closure) or an array of functions
     *
     * @throws ContainerException
     */
    public function configure($config)
    {
        $this->_register((array) $config, $this->_config);
    }

    /**
     * Register a shutdown-function.
     *
     * When the container is destroyed, any registered shutdown-functions for
     * components that were initialized, will be run. The first parameter of a shutdown-function
     * identifies the component that causes the shutdown-function to run - additional parameters
     * specify other components, which will be initialized (if needed) and injected.
     *
     * Shutdown-functions are run in the order they are added.
     *
     * @param Closure $function shutdown-function with parameter-names corresponding to
     *                          the names of configured components - the first parameter
     *                          specifies which component triggers the shutdown-function.
     *
     * @throws ContainerException
     */
    public function shutdown(Closure $function)
    {
        $this->_register((array) $function, $this->_shutdown);
    }

    /**
     * Load a php configuration-file.
     *
     * A configuration-file is simply a php-script running in this Container's `$this` context.
     *
     * @param string $path an absolute path to the configuration-file; or a relative path to {@see getRootPath()}
     *
     * @return mixed return value from the loaded php script, if any
     *
     * @throws ContainerException if the specific field
     */
    public function load($path)
    {
        if (preg_match(self::ABS_PATH_PATTERN, $path) === 0) {
            $path = $this->_rootPath . DIRECTORY_SEPARATOR . $path;
        }

        if (! file_exists($path)) {
            throw new ContainerException('configuration file not found: ' . $path);
        }

        return require $path;
    }

    /**
     * Seals the configuration-container, preventing any further changes, and checks
     * to make sure that the container is fully configured.
     */
    public function seal()
    {
        if ($this->_sealed) {
            throw new ContainerException("Container has already been sealed");
        }

        foreach ($this->_types as $name => $type) {
            if (! array_key_exists($name, $this->_init) && ! array_key_exists($name, $this->_values)) {
                throw new ContainerException('missing configuration of component: ' . $name);
            }
        }

        foreach ($this->_values as $name => $value) {
            $this->_configure($name); // configure components that were initialized directly
        }

        $this->_sealed = true; // seal container against further changes
    }

    /**
     * Injects values from this container as arguments to a given function/method/closure.
     *
     * @param callable $callable the function/method/closure to invoke
     * @param array    $params   optional (name=>value) parameters for the call (overrides values in the container)
     *
     * @return mixed the return value from the function/method/closure call
     *
     * @throws ContainerException if the given argument is not a callable
     */
    public function invoke($callable, array $params = array())
    {
        if (! is_callable($callable)) {
            throw new ContainerException("invalid argument: \$callable is not callable");
        }

        /** @var ReflectionFunctionAbstract $func */
        $func = is_array($callable)
            ? new ReflectionMethod($callable[0], $callable[1])
            : new ReflectionFunction($callable);

        $args = array();

        foreach ($func->getParameters() as $param) {
            $name = $param->name;

            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
            } else if (isset($this->_types[$name])) {
                if ($param->isDefaultValueAvailable() && ! $this->active($name)) {
                    $args[] = $param->getDefaultValue();
                } else {
                    $args[] = $this->get($name);
                }
            } else if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else if ($param->isOptional() || $param->allowsNull()) {
                $args[] = null;
            } else {
                throw new ContainerException("invokation failed: unable to satisfy the argument \${$name}");
            }
        }

        return call_user_func_array($callable, $args);
    }

    /**
     * Check if a given component is active (has been initialized) - this only makes sense
     * after the Container has been sealed, and will throw if called prematurely.
     *
     * @param string $name component name
     *
     * @return bool true, if the given component is active (has been initialized)
     *
     * @throws ContainerException if this Container has not been sealed
     */
    public function active($name)
    {
        if (! $this->_sealed) {
            throw new ContainerException("Container must be sealed before this method can be called");
        }

        return array_key_exists($name, $this->_values);
    }

    /**
     * Initialize and configure a component (as needed) and return it.
     *
     * @param string $name name of component to return
     *
     * @return mixed the component
     *
     * @throws ContainerException on attempt to get an uninitialized component prior to sealing the container
     */
    public function get($name)
    {
        if (! array_key_exists($name, $this->_values)) {
            // initialization is required - check if sealed:

            if (! $this->_sealed) {
                throw new ContainerException('Container must be sealed before this component can be initialized: ' . $name);
            }

            $this->_initialize($name);
            $this->_configure($name);
        }

        return $this->_values[$name];
    }

    /**
     * Directly initialize a component.
     *
     * @param string $name  name of component to initialize
     * @param mixed  $value component
     *
     * @throws ContainerException on attempted write-access to a sealed container,
     *                            on attempted overwrite of already-registered component
     */
    public function set($name, $value)
    {
        if ($this->_sealed === true) {
            throw new ContainerException('attempted write-access to sealed Container');
        }

        if (array_key_exists($name, $this->_init)) {
            throw new ContainerException("attempted overwrite of registered component: '{$name}'");
        }

        $this->checkType($name, $value);

        $this->_values[$name] = $value;
    }

    /**
     * Define a component and it's type.
     *
     * @param string $name component name
     * @param string $type fully-qualified class/interface name
     *
     * @throws ContainerException on attempted redefinition of an already-defined component
     */
    protected function define($name, $type)
    {
        if (isset($this->_types[$name])) {
            throw new ContainerException(
                "attempted redefinition of component '{$name}' as: {$type}"
                . " - component previously defined as: {$this->_types[$name]}"
            );
        }

        if (substr_compare($type, '[]', - 2) === 0) {
            $type = 'array'; // shallow type-checking for array-types
        }

        $this->_types[$name] = $type;
    }

    /**
     * @param string $name component name
     *
     * @return bool true, if a component with the given name has been defined
     *
     * @see define()
     */
    public function defined($name)
    {
        return isset($this->_types[$name]);
    }

    /**
     * @param string $name component name
     *
     * @return bool true, if a component with the given name has been registered (or initialized directly)
     *
     * @see register()
     * @see set()
     */
    protected function registered($name)
    {
        return array_key_exists($name, $this->_init) || array_key_exists($name, $this->_values);
    }

    /**
     * Checks that the specified value conforms to the type defined with define()
     *
     * @param string $name component name
     * @param mixed $value value
     *
     * @throws ContainerException if the given name is not a valid component name
     * @throws ContainerException if the given value does not pass a type-check
     *
     * @return void
     */
    protected function checkType($name, $value)
    {
        if (! isset($this->_types[$name])) {
            throw new ContainerException("undefined component: '{$name}'");
        }

        $type = $this->_types[$name];

        if (strcasecmp($type, 'mixed') === 0) {
            return; // any type is allowed
        }

        if (array_key_exists($type, self::$checkers) === true) {
            // check a known PHP pseudo-type:
            if (call_user_func(self::$checkers[$type], $value) === false) {
                throw new ContainerException("component-type mismatch - component '{$name}' was defined as: {$type}");
            }
        } else {
            // check a class or interface type:
            if (($value instanceof $type) === false) {
                throw new ContainerException("component-type mismatch - component '{$name}' was defined as: {$type}");
            }
        }
    }

    /**
     * Invokes a Closure, automatically filling in any missing parameters using configured components.
     *
     * @param Closure $closure the Closure to invoke
     * @param array   $params  parameters that have already been determined; optional
     *
     * @return mixed the return-value from the invoked Closure
     */
    private function _invoke(Closure $closure, $params = array())
    {
        $fn = new ReflectionFunction($closure);

        foreach ($fn->getParameters() as $index => $param) {
            if (! array_key_exists($index, $params)) {
                $name = $param->name;

                if ($param->isDefaultValueAvailable() && ! array_key_exists($name, $this->_values)) {
                    // skip uninitialized optional argument:
                    $params[$index] = $param->getDefaultValue();
                } else {
                    // initialize (as necessary) and fill argument:
                    $params[$index] = $this->get($name);
                }
            }
        }

        return call_user_func_array($closure, $params);
    }

    /**
     * Initializes the specified component and any dependencies
     *
     * @param string $name
     *
     * @throws ContainerException
     */
    private function _initialize($name)
    {
        if (! array_key_exists($name, $this->_types)) {
            throw new ContainerException("undefined component '{$name}'");
        }

        // run initialization function:

        $value = $this->_invoke($this->_init[$name]);

        $this->checkType($name, $value); // will throw if the initialized value is bad

        $this->_values[$name] = $value; // store initialized value

        unset($this->_init[$name]); // dispose of initialization function
    }

    /**
     * @param string $name
     *
     * @throws ContainerException
     */
    private function _configure($name)
    {
        if (! array_key_exists($name, $this->_values)) {
            throw new ContainerException("internal error: attempted configuration of uninitialized component");
        }

        if (!isset($this->_config[$name])) {
            return; // nothing to configure
        }

        // invoke configuration-functions:

        $param = array($this->_values[$name]);

        foreach ($this->_config[$name] as $config) {
            $this->_invoke($config, $param);
        }

        // dispose of configuration-functions:

        unset($this->_config[$name]);
    }

    /**
     * @param Closure[] $functions list of functions to register
     * @param Closure[] &$registry the registry to which to append
     *
     * @throws ContainerException
     */
    private function _register($functions, &$registry)
    {
        if ($this->_sealed === true) {
            throw new ContainerException('attempted configuration of sealed Container');
        }

        foreach ($functions as $function) {
            // verify parameter names:

            $fn = new ReflectionFunction($function);

            $params = $fn->getParameters();

            if (count($params) === 0) {
                throw new ContainerException('configuration-functions must have at least one parameter');
            }

            foreach ($params as $param) {
                if (! isset($this->_types[$param->getName()])) {
                    throw new ContainerException("undefined component '{$param->getName()}' - all components must be defined using define()");
                }
            }

            // add the configuration-function:

            $name = $params[0]->getName();

            if (! array_key_exists($name, $registry)) {
                $registry[$name] = array();
            }

            $registry[$name][] = $function;
        }
    }
}
