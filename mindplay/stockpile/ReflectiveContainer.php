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

use ReflectionClass;

use mindplay\filereflection\ReflectionFile;

/**
 * Abstract base-class for service/configuration-containers using property-annotations.
 */
abstract class ReflectiveContainer extends Container
{
    /**
     * Regular expression used by the constructor to parse @property-annotations
     */
    const PROPERTY_PATTERN = '/^\s*\*+\s*\@property(?:\-read|\-write|)\s+([\w\\\\]+(?:\\[\\]|))\s+\$(\w+)/im';

    /**
     * Initializes the container by parsing property-annotations of the concrete class.
     *
     * @throws ContainerException if the container has no property-annotations
     */
    protected function _init()
    {
        // parse @property-annotations for property-names and types:

        $class = new ReflectionClass(get_class($this));

        if (preg_match_all(self::PROPERTY_PATTERN, $class->getDocComment(), $matches) === 0) {
            throw new ContainerException('class ' . get_class($this) . ' has no @property-annotations');
        }

        $file = new ReflectionFile($class->getFileName());

        foreach ($matches[2] as $i => $name) {
            $type = $file->resolveName($matches[1][$i]);

            $this->define($name, $type);
        }

        parent::_init();
    }

    /**
     * @internal write-accessor for directly setting configuration-properties
     */
    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    /**
     * @internal read-accessor for component properties
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * @internal isset() overloading for properties
     */
    public function __isset($name)
    {
        return $this->defined($name);
    }
}
