stockpile
=========

https://github.com/mindplay-dk/stockpile

[![Build Status](https://travis-ci.org/mindplay-dk/stockpile.png)](https://travis-ci.org/mindplay-dk/stockpile)

[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/stockpile/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/stockpile/?branch=master)

This is service/configuration-container for PHP 5.3+ attempts to solve a number
of problems with configuration of application/modules/services - you can think
of it as a configuration and service container, or a base class for apps/modules.

See "example.php" in the root-folder for an example of how to use this class.

Application/module containers in most frameworks are either built into a central
application-object or array, which typically means that your IDE has no
awareness of which named objects/values are available in the container, nor
what type they are. By starting with an abstract base class for your container,
and requiring you to extend it, and document the names/types, your container
can provide proper IDE-support, and the container can perform type-checking.

    use mindplay\stockpile\Container;

    /**
     * @property string $db_username
     * @property string $db_password
     * @property PDO $db application-wide database connection
     */
    class MyApp extends Container
    {
        ...
    }

    $container = new MyApp(__DIR__ . '/config');

    $container->load('default.php'); // load and execute "config/default.php"

Containers in various frameworks frequently rely on multi-level nested arrays
as a means of providing late construction - but arrays do not receive proper
IDE-support, so this container relies on anonymous functions (closures) with
static type-hints, as a means of providing late construction.

    // e.g. in "config/default.php":

    $container->register('db', function($db_username, $db_password) {
        return new PDO(...);
    }

When configuration happens in layers (e.g. multiple configuration-files for
different environments) other containers typically merge multi-level nested
arrays recursively; this container instead allows you to further configure
a named object/value by using additional anonymous functions with typed
arguments, which also provides IDE-support.

    $container->configure(
        function (PDO $db) {
            $db->exec("set names utf8");
        }
    );

Containers are often "open", in the sense that you can overwrite the properties
of the container after loading and configuring it - they also don't generally
care whether the configuration is complete or correct. This container requires
a complete configuration as defined by your property-annotations, and requires
you to seal the configuration-container once it is fully populated - making it
read-only, and performing a check for completeness. If you have components
that are deliberately absent, you must explicitly set these to null - this
forces you to actively make decisions and encourages self-documenting code.

    $container->db_username = '...';
    $container->db_password = '...';

    $container->seal(); // prevent further changes (exception if incomplete)

Some containers provide an option to toggle early/late loading - this container
simply expects you to construct objects that should load early, at the time
of configuration.

    $container->logger = new Logger(...); // eager construction, vs register()

This container also addresses the issue of co-dependency between the configured
components in the container. For example, let's say two different components
depend on a configured cache-component - with some configuration-containers, a
component may need to reach into the configuration-container to obtain a needed
component by name; this container instead lets you define dependencies by
simply adding parameters to configuration-closures, causing a dependent
component to automatically initialize when needed somewhere else. (ask for what
you need and let the container provider - rather than looking for things!)

    $container->register(
        'view',
        function (FileCache $cache) {
            // cache argument injected via $container->cache

            return new ViewEngine($cache, ...);
        }
    );
