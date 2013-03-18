<?php

/**
 * stockpile
 * =========
 *
 * A strongly-typed configuration-container for PHP 5.3.
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

/**
 * Abstract base-class for configuration containers.
 *
 * @property-read Configuration $config self-reference to this configuration-container.
 */
abstract class Configuration
{
    /**
     * Regular expression used by the constructor to parse
     * @property-annotations
     */
    const PROPERTY_PATTERN = '/^\s*\*+\s*\@property(?:\-read|\-write|)\s+([\w\\\\]+(?:\\[\\]|))\s+\$(\w+)/im';

    /**
     * Regular expression used to determine if a path is absolute.
     *
     * @see load()
     */
    const ABS_PATH_PATTERN = '/^(?:\/|\\\\|\w\:\\\\).*$/';

    /**
     * @var array map of property-names to type-names (parsed from PHP-DOC block)
     */
    private $_types = array();

    /**
     * @var array map of property-names to intermediary (un-initialized) objects, closures or values
     */
    private $_container = array();

    /**
     * @var array map of where $property_name => Closure[] (list of late configuration-functions)
     */
    private $_config = array();

    /**
     * @var array map of property-names to initialized objects or values
     */
    private $_objects = array();

    /**
     * @var bool true if the configuration-container has been sealed
     */
    private $_sealed = false;

    /**
     * @var array map where $property_name => Closure[] (list of closures to invoke
     *            when the configuration-container is destroyed)
     */
    private $_shutdown = array();

    /**
     * @var string the root-path of configuration-files (with trailing directory-separator)
     */
    private $_rootPath;

    /**
     * Map of methods to use for type-checking known PHP pseudo-types.
     *
     * @see http://www.phpdoc.org/docs/latest/for-users/types.html
     */
    public static $checkers = array(
        'string' => 'is_string',
        'integer' => 'is_int',
        'int' => 'is_int',
        'boolean' => 'is_bool',
        'bool' => 'is_bool',
        'float' => 'is_float',
        'double' => 'is_float',
        'object' => 'is_object',
        'array' => 'is_array',
        'resource' => 'is_resource',
        'null' => 'is_null',
        'callback' => 'is_callable',
    );

    /**
     * Initializes the configuration-container by parsing
     *
     * @property-annotations of the concrete class.
     *
     * @param string $rootPath the root-path of configuration-files, which can be loaded using {@see load()}
     * @throws ConfigurationException if the configuration container has no @property-annotations
     */
    public function __construct($rootPath = null)
    {
        // parse @property-annotations for property-names and types:

        $class = new ReflectionClass(get_class($this));

        if (preg_match_all(self::PROPERTY_PATTERN, $class->getDocComment(), $matches) === 0) {
            throw new ConfigurationException('class ' . get_class($this) . ' has no @property-annotations');
        }

        for ($i = 0; $i < count($matches[0]); $i++) {
            $type = $matches[1][$i];
            $name = $matches[2][$i];

            if (substr_compare($type, '[]', -2) === 0) {
                $type = 'array'; // shallow type-checking for array-types
            }

            $this->_types[$name] = $type;
        }

        // make this Configuration-instance available for parameter-binding:

        $this->_types['config'] = get_class($this);

        $this->_container['config'] = $this;

        // configure root-path:

        $this->_rootPath = rtrim($rootPath === null ? getcwd() : $rootPath, '/\\') . DIRECTORY_SEPARATOR;

        // initialize:

        $this->init();
    }

    /**
     * Runs any shutdown-functions registered in the configuration-container.
     *
     * @see shutdown()
     */
    public function __destruct()
    {
        foreach ($this->_shutdown as $name => $functions) {
            if (array_key_exists($name, $this->_objects)) {
                foreach ($functions as $function) {
                    $this->_invoke($function);
                }
            }
        }
    }

    /**
     * Initialize the configuration container. Override as needed.
     */
    protected function init()
    {}

    /**
     * Registers the component with the given name.
     *
     * Calling this is equivalent to setting the property directly on the container, but
     * avoids type-violation warnings as detected by an IDE that supports and inspects
     * the property-annotations on your configuration-container class.
     *
     * @param string $name  name of component to register.
     * @param mixed  $value an object or value of the type required for the specified component,
     *                      or a closure that creates and returns such an object or value.
     */
    public function register($name, $value)
    {
        $this->__set($name, $value);
    }

    /**
     * Register a shutdown-function.
     *
     * When the configuration-container is destroyed, any registered shutdown-functions for
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
     * @throws ConfigurationException
     */
    public function shutdown(Closure $function)
    {
        // check parameters:

        $fn = new ReflectionFunction($function);

        $params = $fn->getParameters();

        if (count($params) === 0) {
            throw new ConfigurationException('shutdown-functions must have at least one parameter');
        }

        $name = $params[0]->getName();

        if (!isset($this->_types[$name])) {
            throw new ConfigurationException('undefined property: $' . $name . ' (all properties must be defined using @property-annotations.)');
        }

        // add the configuration-function:

        if (!array_key_exists($name, $this->_shutdown)) {
            $this->_shutdown[$name] = array();
        }

        $this->_shutdown[$name][] = $function;
    }

    /**
     * Add a configuration-function to the container - the function will be called the first
     * time a configured property is accessed. Configuration-functions are called in the
     * order in which they were added.
     *
     * @param Closure|Closure[] $config a single configuration-function (a Closure) or an array of functions
     * @throws ConfigurationException
     */
    public function configure($config)
    {
        if ($this->_sealed === true) {
            throw new ConfigurationException('attempted configuration of sealed configuration-container');
        }

        if (!is_array($config)) {
            $config = array($config);
        }

        foreach ($config as $index => $function) {
            if (($function instanceof Closure) === false) {
                throw new ConfigurationException('parameter #' . $index . ' is not a Closure');
            }

            // obtain the first parameter-name:

            $fn = new ReflectionFunction($function);

            $params = $fn->getParameters();

            if (count($params) === 0) {
                throw new ConfigurationException('configuration-functions must have at least one parameter');
            }

            $name = $params[0]->getName();

            if (!isset($this->_types[$name])) {
                throw new ConfigurationException('undefined property: $' . $name . ' (all properties must be defined using @property-annotations.)');
            }

            // add the configuration-function:

            if (!array_key_exists($name, $this->_config)) {
                $this->_config[$name] = array();
            }

            $this->_config[$name][] = $function;
        }
    }

    /**
     * Load a configuration-file.
     *
     * (a configuration-file is simply a php-script running in an isolated function-context.)
     *
     * @param string $path either an absolute path to the configuration-file, or relative to {@see $rootPath}
     * @throws ConfigurationException
     */
    public function load($path)
    {
        if (preg_match(self::ABS_PATH_PATTERN, $path) === 0) {
            $path = $this->_rootPath . $path;
        }

        if (file_exists($path) === false) {
            throw new ConfigurationException('configuration file not found: ' . $path);
        }

        require $path;
    }

    /**
     * Seals the configuration-container, preventing any further changes, and checks
     * to make sure that the container is fully configured.
     */
    public function seal()
    {
        foreach ($this->_types as $name => $type) {
            if (array_key_exists($name, $this->_container) === false) {
                throw new ConfigurationException('missing configuration of component: ' . $name);
            }
        }

        foreach ($this->_config as $name => $config) {
            if (!array_key_exists($name, $this->_types)) {
                throw new ConfigurationException('attempted configuration of undefined component: ' . $name);
            }
        }

        $this->_sealed = true;
    }

    /**
     * Injects values from this container into the given object - optionally, you can
     * have values injected into protected properties, but by default, only public
     * properties are populated; private properties are never injected.
     *
     * @param object $object    the object to populate using values from this container.
     * @param bool   $protected if true, protected properties will also be injected.
     */
    public function inject($object, $protected = false)
    {
        $class = new ReflectionClass(get_class($object));

        do {
            $properties = $class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

            foreach ($properties as $property) {
                if (!$this->__isset($property->getName())) {
                    continue; // no value with that name exists in this container
                }

                if ($property->isProtected()) {
                    if ($protected === true) {
                        $property->setAccessible(true);
                    } else {
                        continue; // skip protected property
                    }
                }

                $property->setValue($object, $this->__get($property->getName()));
            }

            $class = $class->getParentClass();
        } while ($class !== false);
    }

    /**
     * Checks that the specified value conforms to the type defined by the
     * @property-annotations of the concrete configuration-class.
     */
    protected function checkType($name, $value)
    {
        $type = $this->_types[$name];

        if (strcasecmp($type, 'mixed') === 0) {
            return; // any type is allowed
        }

        if (array_key_exists($type, self::$checkers) === true) {
            // check a known PHP pseudo-type:
            if (call_user_func(self::$checkers[$type], $value) === false) {
                throw new ConfigurationException('property-type mismatch - property $' . $name . ' was defined as: ' . $type);
            }
        } else {
            // check a class or interface type:
            if (($value instanceof $type) === false) {
                throw new ConfigurationException('property-type mismatch - property $' . $name . ' was defined as: ' . $type);
            }
        }
    }

    /**
     * Invokes a Closure, automatically filling in any missing parameters using configured properties.
     *
     * @param Closure $closure the Closure to invoke
     * @param array   $params  parameters that have already been determined; optional
     * @return mixed the return-value from the invoked Closure
     */
    private function _invoke(Closure $closure, $params = array())
    {
        $fn = new ReflectionFunction($closure);

        foreach ($fn->getParameters() as $index => $param) {
            if (!array_key_exists($index, $params)) {
                $params[$index] = $this->__get($param->getName());
            }
        }

        return call_user_func_array($closure, $params);
    }

    /**
     * @internal write-accessor for configuration-properties
     */
    public function __set($name, $value)
    {
        if ($this->_sealed === true) {
            throw new ConfigurationException('attempted access to sealed configuration container');
        }

        if (array_key_exists($name, $this->_container)) {
            throw new ConfigurationException('attempted overwrite of property: $' . $name);
        }

        if (!isset($this->_types[$name])) {
            throw new ConfigurationException('undefined configuration property $' . $name);
        }

        if (($value instanceof Closure) === false) {
            $this->checkType($name, $value);
        }

        $this->_container[$name] = $value;
    }

    /**
     * @internal read-accessor for configuration-properties
     */
    public function __get($name)
    {
        if (array_key_exists($name, $this->_objects) === false) {
            // first use - check if sealed:

            if ($this->_sealed === false) {
                throw new ConfigurationException('configuration container must be sealed before properties can be read');
            }

            // first use - initialize the property:

            if (!array_key_exists($name, $this->_container)) {
                throw new ConfigurationException('undefined configuration property: $' . $name);
            }

            $object = $this->_container[$name];

            if ($object instanceof Closure) {
                // "unwrap" an object created by a late-construction Closure:

                $object = $this->_invoke($object);
            }

            $this->checkType($name, $object);

            // apply any configuration-functions:

            if (array_key_exists($name, $this->_config)) {
                foreach ($this->_config[$name] as $config) {
                    $this->_invoke($config, array($object));
                }
            }

            // store the initialized object:

            $this->_objects[$name] = $object;

            // destroy the late-construction and/or configuration functions:

            unset($this->_config[$name], $this->_container[$name]);
        }

        return $this->_objects[$name];
    }

    /**
     * @internal isset() overloading for properties
     */
    public function __isset($name)
    {
        return array_key_exists($name, $this->_objects) || array_key_exists($name, $this->_container);
    }
}
