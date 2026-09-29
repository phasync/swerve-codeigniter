<?php

namespace Swerve\CodeIgniter;

/**
 * One of CodeIgniter's static arrays of shared instances (BaseService::$instances,
 * Database\Config::$instances), put in its place so that each request has its own entries: those
 * of the phasync context the request runs in, which the coroutines it starts share.
 *
 * Persistent keys, and every key outside a request, are shared by the worker. With $lend, an
 * entry a request leaves behind is kept and handed to the next request that asks for the same
 * key, after $lend has been called with it (a database connection, pinged).
 *
 * @internal
 */
final class RequestScope implements \ArrayAccess, \IteratorAggregate
{
    private \ArrayObject $shared;

    /** This scope's key in a phasync context (phasync 2.0.0-alpha15's contexts fail on object keys) */
    private string $key;

    /** @var array<string, list<object>> entries left by ended requests, by key */
    private array $idle = [];

    /**
     * @param array<string, object> $shared     the entries so far, kept for the persistent keys
     * @param list<string>          $persistent keys shared by all requests
     */
    public function __construct(array $shared, private readonly array $persistent = [], private readonly ?\Closure $lend = null)
    {
        $this->key    = self::class . '#' . \spl_object_id($this);
        $this->shared = new \ArrayObject(\array_intersect_key($shared, \array_flip($persistent)));
    }

    /** From now on, the current request's entries are its own. */
    public function begin(): void
    {
        \phasync::getContext()[$this->key] = new \ArrayObject();
    }

    /** The current request's entries are dropped, or with $lend kept for the next requests. */
    public function end(): void
    {
        $context = \phasync::getContext();
        if (null !== $this->lend) {
            foreach ($context[$this->key] as $key => $value) {
                $this->idle[$key][] = $value;
            }
        }
        unset($context[$this->key]);
    }

    private function items(mixed $key = null): \ArrayObject
    {
        if (null !== $key && \in_array($key, $this->persistent, true)) {
            return $this->shared;
        }
        $context = \phasync::getContext();

        return $context[$this->key] ?? $this->shared;
    }

    public function offsetExists(mixed $offset): bool
    {
        $items = $this->items($offset);
        if (!isset($items[$offset]) && [] !== ($this->idle[$offset] ?? []) && $items !== $this->shared) {
            $items[$offset] = \array_pop($this->idle[$offset]);
            ($this->lend)($items[$offset]);
        }

        return isset($items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items($offset)[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items($offset)[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items($offset)[$offset]);
    }

    public function getIterator(): \Iterator
    {
        return $this->items()->getIterator();
    }
}
