<?php

namespace System\Modules\Utils\Models\Cache;

/**
 *  Cache library interface
 */
interface CacheInterface
{
    /**
     *  Init cache.
     *
     * @access public
     * @static
     * @return void
     */
    public function __construct(?array $config = null);

    /**
     *  Prefix $key
     *
     * @access public
     * @static
     * @return string
     */
    public function prefix(string $key): string;

    /**
     *  Set key and value with ttl (time to live).
     *
     * @access public
     * @static
     * @return bool
     */
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     *  Get value by key.
     *
     * @access public
     * @static
     * @return mixed
     */
    public function getValue(string $key): mixed;

    /**
     *  Remove value by $key from cache.
     *
     * @access public
     * @static
     * @return bool
     */
    public function removeKey(string $key): bool;
}
