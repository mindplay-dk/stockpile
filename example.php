<?php

namespace mindplay\stockpile;

require './mindplay/stockpile/Configuration.php';
require './mindplay/stockpile/ConfigurationException.php';

#use mindplay\stockpile\Configuration;
#use mindplay\stockpile\ConfigurationException;

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
 * @property mindplay\stockpile\Greeter $greeter a sample configured object
 * @property int $time the time of day
 * @property string $mood my current mood
 */
class MyConfig extends Configuration
{}

$config = new MyConfig;

// use an anonymous closure to configure a property for late construction:

$config->greeter = function($time) {
  // the $time configuration-value is automatically injected
  $g = new Greeter;
  
  $g->day = date('l', $time);
  
  return $g;
};

// property-types must match those defined in the @property-annotations above:

$config->time = time(); // ... setting this to a string would cause an exception

$config->mood = 'happy';

// properties using late construction can be configured using a callback-method:

$config->configure(function(Greeter $greeter, $mood) {
  // the parameter-name (greeter) defines the property to be configured
  // the $mood configuration-value is automatically injected
  $greeter->name = 'World';
  $greeter->mood = $mood;
});

// configuration container must be sealed before properties can be read:

$config->seal();

$config->greeter->sayHello();

// we can also inject values into public object properties:

class Foo
{
  public $time;
}

class Bar extends Foo
{
  private $mood='unaffected';
  
  public function getMood()
  {
    return $this->mood;
  }
}

$foo = new Bar();

$config->inject($foo);

echo "\ninjected time: {$foo->time}";
echo "\nmood: ".$foo->getMood();
