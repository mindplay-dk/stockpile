stockpile
=========

https://github.com/mindplay-dk/stockpile

[![Build Status](https://travis-ci.org/mindplay-dk/stockpile.png)](https://travis-ci.org/mindplay-dk/stockpile)

[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/stockpile/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/stockpile/?branch=master)

Stockpile provides an abstract base-class for service/configuration-containers for PHP 5.3.

See "example.php" in the root-folder for an example of how to use this class.

By using this class as the base-class for the global point of entry for your
application or module, your container will receive proper IDE-support and
run-time type-checking e.g. for service interfaces and configuration values.

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

$container = new MyApp(__DIR__ . '/config');

$container->load('default.php'); // load and execute "config/default.php"
```

Containers in many frameworks rely on nested arrays, data files or other
schema-less means of configuration, providing no support for design-time
inspections in a modern IDE - this container relies on anonymous functions
(closures) with static type-hints, as a means of providing late construction:

```PHP
// e.g. in "config/default.php":

$container->register('db', function($db_username, $db_password) {
    return new PDO(...);
}
```

When configuration happens in layers (e.g. multiple configuration-files for
different environments) frameworks often have to merge multi-level nested
arrays recursively; this container instead allows you to further configure
a named component by using additional anonymous functions, with type-hints,
for proper IDE-support:

```PHP
$container->configure(
    function (PDO $db) {
        $db->exec("set names utf8");
    }
);
```

Containers are often "open", in the sense that you can overwrite components
in the container after loading and configuring it - they also don't generally
care whether the configuration is complete or correct. This container requires
a complete configuration as defined by your property-annotations, and requires
you to seal the configuration-container once it is fully populated - making it
read-only, and performing a check for completeness. If you have components
that are deliberately absent, you must explicitly set these to null - this
forces you to actively make decisions and encourages self-documenting code.

```PHP
$container->db_username = '...';
$container->db_password = '...';

$container->seal(); // prevent further changes (exception if incomplete)
```

Some containers provide an option to toggle early/late loading of specific
components - instead of having to configure this, simply initialize objects
that should load early, directly, at the time of configuration:

```PHP
$container->logger = new Logger(...); // eager construction, vs register()
```

Dependencies between components in the container can be automatically resolved
via arguments to closures. For example, let's say two different components
depend on a configured cache-component - rather than reaching into the
configuration-container to obtain a needed component by name, smiply define
dependencies by adding parameters to configuration-closures:

```PHP
$container->register(
    'view',
    function (FileCache $cache) {
        // cache argument injected via $container->cache

        return new ViewEngine($cache, ...);
    }
);
```
