<?php

use Src\Container\Container;

require_once "vendor/autoload.php";

$container = new Container;

class Baz {}

class Foo {
    public function __construct(Baz $baz)
    {
        var_dump($baz);
    }
}

$container->bind(Foo::class, function (Container $container) {
    return new Foo($container->make(Baz::class));
});

var_dump($container->get(Foo::class));