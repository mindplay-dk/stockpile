stockpile
=========

https://github.com/mindplay-dk/stockpile

[![Build Status](https://travis-ci.org/mindplay-dk/stockpile.png)](https://travis-ci.org/mindplay-dk/stockpile)

[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/stockpile/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/stockpile/?branch=master)

Stockpile provides a base-class for easy implementation of the [service locator](http://en.wikipedia.org/wiki/Service_locator_pattern)
pattern, and provides simple means for implementing simple, efficient [dependency injection](http://en.wikipedia.org/wiki/Dependency_injection).

Tested and designed for PHP 5.3 and up.

See "example.php" in the root-folder for an example of how to use this class.


### Overview

The `Container` base class will parse `@property` annotations on your class - these
provide design-time IDE support, while the type-hints and property-names in your
class-level doc-block are also picked up and parsed by the base-class, which is then
able to provide run-time type-checking and extra safety.


### API Overview

The life-cycle of a `Container` class has two stages: it is initially open for
registration and configuration, and then gets sealed (using the `seal()` method)
prevent any further modifications. In other words, it is initially write-only,
and then becomes read-only.

Configuration methods, available prior to calling `seal()`:

    register(string $name, Closure $init)   # register component creation function
    unregister(string $name)                # unregister a component
    configure(Closure|Closure[] $config)    # configure a registered component
    shutdown(Closure $function)             # dispose of components after use
    load(string $path)                      # load an external configuration file

Other methods, available at all times:

    getRootPath()                           # get configuration files root path
    invoke(callable $function, $params)     # invoke a function with components as arguments
    isActive(string $name)                  # check if a component has been initialized
    isDefined(string $name)                 # check if a component has been defined                 
    isRegistered(string $name)              # check if a component has been registered

A "defined" component, is a property of your container that has been defined with
an `@property` annotation. A "registered" component has been registered using the
`register()` method, or has been initialized directly by setting the property.
An "active" component has been initialized, e.g. by accessing the property after
the container has been sealed.


### Usage

By using this class as the base-class for the global point of entry for your
application or module, your container will receive proper IDE-support and
run-time type-checking e.g. for service interfaces and configuration values.

A basic container class migth look like this: 

```PHP
use mindplay\stockpile\Container;

/**
 * @property string $db_username
 * @property string $db_password
 *
 * @property-read PDO $db application-wide database connection
 */
class MyApp extends Container
{
    ...
}
```

Usage of the class might be something like this:

```PHP
$container = new MyApp(__DIR__ . '/config');

$container->load('default.php'); // load and execute "config/default.php"
```

Note that there is deliberately no support for configuration via nested arrays,
XML/JSON/YAML data files, or any other schema-less means of configuration - these
add complexity, they provide no support for design-time inspections in a modern IDE,
they are unnecessary and provide no clear benefits.


#### Configuration Files

Your `config/default.php` being loaded in the example s simply a PHP script, which
might look something like this:

```PHP
/** @var MyApp $this */

$this->db_username = 'foo';
$this->db_password = 'bar';
```

Notice the `@var` type-hint, which provides design-time IDE support.

Once the container has been sealed, when the `$db` property is accessed for
the first time, the registered creation function will be called. Arguments
to this function correspond to property-names - you should type-hint these
for IDE support, when possible; in this example both properties are strings.


#### Dependency Resolution

Asking for required components (via arguments to closures), enables the
container to initialize dependencies (other components) in cascade. For example,
let's say that several different components depend upon a cache component - here's
an example of registering a view engine with a dependency a cache component:

```PHP
$container->register(
    'view',
    function (FileCache $cache) {
        // cache argument injected via $container->cache

        return new ViewEngine($cache, ...);
    }
);
```


#### Layered Configuration

When configuration happens in layers (e.g. multiple configuration-files for
different environments) you can further configure a named component, by using
additional anonymous functions, with type-hints for IDE-support.

For example, to send a `set names utf8` query to MySQL when the `$db` component
gets initialized, you might add this:

```PHP
$container->configure(
    function (PDO $db) {
        $db->exec("set names utf8");
    }
);
```


#### Sealing

Once you're all done configuring your container, before you can start using the
components, you need to seal it - this prevents any further attempts to make
changes accidentally, and also verifies that the configuration of every defined
component is complete.

```PHP
$container->db_username = '...';
$container->db_password = '...';

$container->seal(); // prevent further changes (exception if incomplete)
```

Note that, if you have components that are deliberately absent, you must
explicitly set these to null - this forces you to actively make decisions
and leads to more self-documenting code.


#### Eager vs Lazy

You won't find an option to toggle eager/lazy loading of components - it is
assumed you want everything to initialize as late as possible. If you do have
a component that is available immediately, simply inject that component
directly - for example:

```PHP
$container->logger = new Logger(...); // eager construction, vs lazy register()
```


### Advanced Use

For advanced uses, such as building a Container with specialized behavior (e.g.
defining components by other means besides parsing `@property` annotations) an
abstract base class `AbstractContainer` is available, with a bunch more protected
API methods available. Explore on your own, if needed.
