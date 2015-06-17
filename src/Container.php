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

use mindplay\filereflection\ReflectionFile;
use mindplay\filereflection\CacheProvider;
use ReflectionClass;

/**
 * Abstract base-class for service/configuration-containers using property-annotations.
 */
abstract class Container extends AbstractContainer
{
    /**
     * Regular expression used by the constructor to parse property-annotations
     *
     * @type string
     */
    const PROPERTY_PATTERN = '/^\s*\*+\s*\@property(?:\-read|\-write|)\s+([\w\\\\]+(?:\\[\\]|))\s+\$(\w+)/im';

    /**
     * Initializes the container by parsing property-annotations of the concrete class.
     *
     * @return void
     *
     * @throws ContainerException if the container has no property-annotations
     */
    protected function _init()
    {
        $cache = $this->getCache();

        if ($cache) {
            $class = new ReflectionClass($this);
            $self = $this;

            $this->_types = $cache->read(
                $class->getName(),
                filemtime($class->getFileName()),
                function () use ($class, $self) {
                    $method = $class->getMethod('parseDocBlocks');
                    $method->setAccessible(true);

                    return $method->invoke($self);
                }
            );
        } else {
            $this->parseDocBlocks();
        }

        parent::_init();
    }

    /**
     * @internal write-accessor for directly setting configuration-properties
     *
     * @param string $name
     * @param mixed $value
     *
     * @return void
     *
     * @throws ContainerException
     */
    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    /**
     * @internal read-accessor for component properties
     *
     * @param string $name
     *
     * @return mixed
     *
     * @throws ContainerException
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * @internal isset() overloading for properties
     *
     * @param string $name
     *
     * @return bool
     */
    public function __isset($name)
    {
        return $this->isDefined($name);
    }

    /**
     * Creates a cache provider for internal caching of parsed annotations.
     *
     * Default implementation returns NULL - to enable caching, override this
     * method in your container class, with e.g.:
     *
     *     return new \mindplay\filereflection\FileCache(...)
     *
     * @return CacheProvider|null cache provider (or NULL to initialize without caching)
     */
    protected function getCache()
    {
        return null; // default implementation; caching disabled
    }

    /**
     * Parse doc-blocks for @property-annotations
     *
     * @return array map of component-names to type-names
     *
     * @see _init()
     *
     * @throws ContainerException if no property-annotations are available
     */
    protected function parseDocBlocks()
    {
        // collect doc-blocks from parent classes:

        $class_name = get_class($this);

        $this->_types = array();

        do {
            $class = new ReflectionClass($class_name);

            $docs = $class->getDocComment();

            // parse @property-annotations for property-names and types:

            if (preg_match_all(self::PROPERTY_PATTERN, $docs, $matches) > 0) {
                $file = new ReflectionFile($class->getFileName());

                foreach ($matches[2] as $i => $name) {
                    $type = $file->resolveName($matches[1][$i]);

                    $this->define($name, $type);
                }
            }

            $class_name = get_parent_class($class_name);
        } while ($class_name !== __CLASS__);

        if (count($this->_types) === 0) {
            throw new ContainerException('class ' . get_class($this) . ' has no @property-annotations');
        }

        return $this->_types;
    }
}
