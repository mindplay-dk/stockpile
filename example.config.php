<?php

namespace mindplay\stockpile\example;

/**
 * @var MyContainer $this
 */

$this->configure(function(Greeter $greeter) {
    $greeter->name = 'World';
});
