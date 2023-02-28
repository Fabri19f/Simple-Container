<?php

namespace Src\Container;

use Closure;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use Src\Container\Exceptions\DependencyResolutionException;
use Src\Container\Exceptions\EntryNotFoundException;

class Container implements ContainerInterface
{
    /**
     * The container's instances (singletons).
     * Las instancias del contenedor (singletons).
     * 
     * @var array $instances
     */
    protected array $instances = [];

    /**
     * The container's bindings.
     * Los enlaces del contenedor.
     * 
     * @var array $bindings
     */
    protected array $bindings = [];

    /**
     * The container's contextual bindings.
     * Las enlaces contextuales del contenedor.
     * 
     * @var array $contextual
     */
    protected array $contextual = [];

    /**
     * The stack of dependencies being built.
     * La pila de dependencias que se están construyendo.
     * 
     * @var array $buildStack
     */
    protected array $buildStack = [];

    /**
     * Register a binding to the container.
     * Registra un enlace al contenedor.
     * 
     * @param string $abstract
     * @param Closure|string|null $concrete
     * @param bool $singleton
     * 
     * @return void
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $singleton = false): void
    {
        // If the concrete value is null, it is assumed to have
        // the same value as the abstraction.
        // Si el valor concreto es nulo, se asume que debe tener
        // el mismo valor que la abstracción.
        if (!$concrete) {
            $concrete = $abstract;
        }

        // If the concrete value is not a Closure,
        // it means that it is a class name that is being attempted to bind to the abstraction,
        // therefore it is necessary to wrap the concrete value in a Closure
        // so that when it is called, it recursively resolves the concrete class.
        // Si el valor concreto no es un Closure, 
        // significa que se trata de un nombre de clase que se intenta enlazar a la abstracción,
        // por ello es necesario envolver al valor concreto en un Closure
        // para que este, al ser llamado resuelva la clase concreta de forma recursiva.
        if (!$concrete instanceof Closure) {
            $concrete = function (array $arguments) use ($concrete): object {
                return $this->build($concrete, $arguments);
            };
        }

        $this->bindings[$abstract] = compact('concrete', 'singleton');
    }

    /**
     * Register a binding to the container that will be resolved as a singleton.
     * Registra un enlace al contenedor que será resuelto como singleton.
     * 
     * @param string $abstract
     * @param Closure|string|null $concrete
     * 
     * @return void
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Build an instance of the given concrete class.
     * Construye una instancia de la clase concreta dada.
     *
     * @param string $concrete
     * @param array $arguments
     *
     * @return object
     *
     * @throws DependencyResolutionException|ReflectionException
     */
    public function build(string $concrete, array $arguments = []): object
    {
        $reflectionClass = new ReflectionClass($concrete);

        $this->buildStack[] = $concrete;

        if (!$reflectionClass->isInstantiable()) {
            array_pop($this->buildStack);
            throw new DependencyResolutionException("Target [$concrete] is not instantiable.");
        }

        $constructor = $reflectionClass->getConstructor();
        $parameters = $constructor?->getParameters();

        if (!$parameters) {
            array_pop($this->buildStack);
            return new $concrete;
        }

        $dependencies = $this->getDependencies($parameters, $arguments);

        array_pop($this->buildStack);
        return $reflectionClass->newInstance(...$dependencies);
    }

    /**
     * Resolve the dependencies of a valid Callable.
     * Resuelve las dependencias de un Callable válido.
     *
     * @param Callable $callable
     * @param array $arguments
     *
     * @return mixed
     *
     *
     * @throws DependencyResolutionException|ReflectionException
     */
    public function call(Callable $callable, array $arguments = []): mixed
    {
        $reflectionCallable = is_array($callable)
            ? new ReflectionMethod($callable[0], $callable[1])
            : new ReflectionFunction($callable);

        $parameters = $reflectionCallable->getParameters();

        return $callable(...$this->getDependencies($parameters, $arguments));
    }

    /**
     * Retrieve and recursively resolve the dependencies of a concrete class.
     * Recupera y resuelve de forma recursiva las dependencias de una clase concreta.
     *
     * @param array $parameters
     * @param array $arguments
     *
     * @return array
     *
     * @throws DependencyResolutionException|ReflectionException
     */
    protected function getDependencies(array $parameters, array $arguments): array
    {
        $instances = [];
        foreach ($parameters as $parameter) {
            // If the parameter is a class, it is resolved recursively.
            // Si el parámetro es una clase, se resuelve de forma recursiva.
            if ($this->isNotBuiltin($parameter)) {
                $instances[$parameter->getName()] = $this->resolve($parameter->getType()->getName());
            }
        }
        // The resolved dependencies are mixed with primitive type parameters.
        // Las dependencias que han sido resueltas se mezclan con los parámetros de tipo primitivo.
        return array_merge($instances, $arguments);
    }

    /**
     * Check if the parameter is not a built-in type.
     * Comprueba si el parámetro no es un tipo integrado.
     * 
     * @param ReflectionParameter $parameter
     * 
     * @return bool
     */
    protected function isNotBuiltin(ReflectionParameter $parameter): bool
    {
        return $parameter->hasType() && !$parameter->getType()->isBuiltin();
    }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    public function get(string $id): mixed
    {
        try {
            return $this->resolve($id);
        } catch (Exception $e) {
            if($e instanceof DependencyResolutionException){
                throw $e;
            }
            throw new EntryNotFoundException("Entry not found: [$id]", is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }
    }

    /**
     * Resolve the given abstraction.
     * Resuelve la abstracción dada.
     *
     * @param string $abstract
     * @param array $arguments
     *
     * @return object
     *
     * @throws DependencyResolutionException|ReflectionException
     */
    public function make(string $abstract, array $arguments = []): object
    {
        return $this->resolve($abstract, $arguments);
    }

    /**
     * Determine the resolution method for a given abstraction.
     * Determina el método de resolución para una abstracción dada.
     * 
     * @param string $abstract
     * @param array $arguments
     * 
     * @return object
     * 
     * @throws DependencyResolutionException|ReflectionException
     */
    protected function resolve(string $abstract, array $arguments = []): object
    {
        // If a contextual binding is found, the implementation bound to that context is retrieved
        // and recursively resolved using the 'build' method.
        // Si se encuentra un enlace contextual, la implementación vinculada a ese contexto es recuperada
        // y resuelta de forma recursiva mediante el método 'build'.
        if ($implementation = $this->findContextualBinding($abstract)) {
            return $this->build($implementation);
        }

        // If a simple binding is found, the abstraction is resolved using the 'resolveBinding' method.
        // Si se encuentra un enlace simple, la abstracción es resuelta mediante el método 'resolveBinding'.
        if (isset($this->bindings[$abstract])) {
            return $this->resolveBinding($abstract, $arguments);
        }

        // If the abstraction is not bound to the container, it is directly and recursively resolved
        // using the 'build' method.
        // Si la abstracción no esta enlazada al contenedor, se resuelve de forma directa y de manera 
        // recursiva mediante el método 'build'
        return $this->build($abstract, $arguments);
    }

    /**
     * Resolve the container bindings (non-contextual bindings).
     * Resuelve los enlaces del contenedor (no enlace contextual).
     * 
     * @param string $abstract
     * @param array $arguments
     * 
     * @return object
     */
    protected function resolveBinding(string $abstract, array $arguments): object
    {
        // Retrieve the binding linked to the abstraction.
        // Se recupera el enlace vinculado con la abstracción
        $binding = $this->bindings[$abstract];
        $concrete = $binding['concrete'];

        // If the binding is not a singleton, call the Closure linked to the binding to recursively resolve the abstraction.
        // Si el enlace no es un singleton, se llama al Closure vinculado al enlace para que resuelva la abstracción de forma recursiva.
        if (!$binding['singleton']) {
            return $concrete($arguments);
        }

        // Check if there is already an instance in the container that corresponds to the given abstraction, if so, return the same instance,
        // otherwise call the Closure linked to the binding to recursively resolve the abstraction
        // and add it to the container instances.
        // Se comprueba si ya existe una instancia en el contenedor que corresponda con la abstracción dada, si es asi, se retorna la misma instancia,
        // de lo contrario se llama al Closure vinculado al enlace para que resuelva la abstracción de forma recursiva  
        // y se añada a las instancias del contenedor.
        return $this->instances[$abstract] ?? $this->instances[$abstract] = $concrete($arguments);
    }

    /**
     * Search for the contextual binding using the build stack.
     * Busca el enlace contextual haciendo uso de la pila de resolución.
     * 
     * @param string $abstract
     * 
     * @return string|null
     */
    protected function findContextualBinding(string $abstract): string|null
    {
        return $this->contextual[end($this->buildStack)][$abstract] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->bindings[$id]);
    }

    /**
     * Define a contextual binding.
     * Define un enlace contextual.
     *
     * @param  string|array  $concrete
     * @return ContextualBindingBuilder
     */
    public function when(string|array $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Create a contextual binding and assign it the implementation.
     * Crea un enlace contextual y le asigna la implementación.
     * 
     * @param string|array $concrete
     * @param string $abstract
     * @param string $implementation
     * 
     * @return void
     */
    public function addContextualBinding(string|array $concrete, string $abstract, string $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }
}
