<?php

namespace Src\Container;

class ContextualBindingBuilder
{
    /**
     * The underlying container instance.
     * La instancia del contenedor subyacente.
     *
     * @var \Src\Container\Container
     */
    protected Container $container;

    /**
     * The concrete class or classes.
     * La clase o clases concretas.
     *
     * @var string|array
     */
    protected string|array $concrete;

    /**
     * The abstract target.
     * La abstracción objetivo.
     *
     * @var string
     */
    protected string $abstract;

    /**
     * Create a new contextual binding builder.
     * Crea un nuevo constructor de enlace contextual.
     *
     * @param \Src\Container\Container $container
     * @param string|array $concrete
     * @return void
     */
    public function __construct(Container $container, string|array $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    /**
     * Define the abstract target that depends on the context.
     * Define el objetivo abstracto que depende del contexto.
     *
     * @param string $abstract
     * 
     * @return \Src\Container\ContextualBindingBuilder
     */
    public function needs(string $abstract): ContextualBindingBuilder
    {
        $this->abstract = $abstract;
        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
     * Define la implementación para el enlace contextual.
     *
     * @param string $implementation
     * 
     * @return void
     */
    public function give(string $implementation): void
    {
        foreach ($this->wrap($this->concrete) as $concrete) {
            $this->container->addContextualBinding($concrete, $this->abstract, $implementation);
        }
    }

    /**
     * Wrap the concrete value in an array if it is a string.
     * Envuelve el valor concreto en un arreglo si se trata de una cadena.
     * 
     * @param string|array
     * 
     * @return array
     */
    protected function wrap(string|array $concrete): array
    {
        return is_array($concrete) ? $concrete : [$concrete];
    }
}