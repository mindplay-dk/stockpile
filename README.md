stockpile
=========

https://github.com/mindplay-dk/stockpile

THIS LIBRARY IS CURRENTLY UNDER DEVELOPMENT.

This is an experimental configuration-container for PHP 5.3, attempting to
solve a number of problems with configuration in general.

See "example.php" in the root-folder for an example of how to use this class.

Configuration containers in most frameworks are either built into a central
application-object or array, which typically means that your IDE has no
awareness of which named objects/values are available in the container, nor
what type they are. By starting with an abstract configuration-container
and requiring you to extend it and document the names/types, you configuration
object can provide IDE-support, and the container can perform type-checking.

Other configuration containers frequently rely on multi-level nested arrays
as a means of providing late construction - since arrays receive no IDE-support,
this container instead relies on anonymous functions for late construction.

When configuration happens in layers (e.g. multiple configuration-files for
different environments) other containers typically merge multi-level nested
arrays recursively; this container instead allows you to further configure
a named object/value by using anonymous functions with typed arguments, which
also provides IDE-support.

Other containers in general are "open", in the sense that you can overwrite
named objects/values after loading the configuration - and they also don't
generally care if the configuration is complete or correct. This container
requires a complete configuration as defined by your property-annotations,
and requires you to seal the configuration-container once fully populated -
making it read-only and performing a check for completeness. (If you have
optional components, you must explicitly set these to null.)

Some containers provide an option to toggle early/late loading - this container
simply expects you to construct objects that should load early, at the time
of configuration.

This container also addresses the issue of co-dependency between the configured
components in the container. For example, let's say two different components
depend on a configured cache-component - with some configuration-containers, the
other components need to go back to the configuration-container and obtain the
cache-component by name; instead, the container lets you define dependencies
by simply adding parameters to configuration-closures, causing a dependent
component to automatically initialize when needed somewhere else.

Finally, note that the configuration-container itself is made available in the
container using the property-name 'config' - this enables you to inject the
configuration-container itself into late-construction and configuration functions.
