<?php

namespace mindplay\stockpile\example;

use mindplay\stockpile\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

// ===== EXAMPLE =====

header('Content-type: text/plain');

/**
 * A simple class for testing
 */
class Greeter
{
    public $name; // this will be set explicitly when the Greeter is constructed
    public $day;  // this will be injected by the late-construction function
    public $mood; // this will be injected by the configuration function

    public function sayHello()
    {
        echo "Hello, {$this->name}! Today is {$this->day} and I'm feeling {$this->mood}!";
    }
}

/**
 * This class implements my application-specific configuration.
 *
 * The @property-annotations below will be parsed and used to configure the container.
 *
 * @property-read Greeter $greeter a sample configured object
 * @property int          $time    the time of day
 * @property string       $mood    my current mood
 */
class MyContainer extends Container
{
    protected function init()
    {
        // use an anonymous closure to configure a property for late construction:

        $this->register('greeter', function($time) {
            // the $time configuration-value is automatically injected
            $g = new Greeter;

            $g->day = date('l', $time);

            return $g;
        });

        // property-types must match those defined in the @property-annotations above:

        $this->time = time(); // ... setting this to a string would cause an exception

        $this->mood = 'happy';
    }
}

$container = new MyContainer();

// components using late construction can be configured using a callback-method:

$container->configure(function(Greeter $greeter, $mood) {
    // the parameter-name (greeter) defines the property to be configured
    // the $mood configuration-value is automatically injected
    $greeter->mood = $mood;
});

// components can also be configured via external configuration scripts:

$container->load('example.config.php');

// configuration container must be sealed before properties can be read:

$container->seal();

// components in the container can now be consumed:

$container->greeter->sayHello();
