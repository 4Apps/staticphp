<?php

namespace System\Modules\Utils\Models\Cache;

use Memcached;
use System\Modules\Core\Models\Config;

/**
 *  Memcached cache implementation
 */
class CacheMemcached extends Cache
{
    protected Memcached $memcached;

    /**
     *  Init Memcached cache
     *
     * @access public
     * @static
     * @return void
     */
    public function __construct(?array $config = null)
    {
        parent::__construct($config);

        if (isset($this->config['timeout']) && $this->config['timeout'] !== null) {
            ini_set('memcached.default_connect_timeout', $this->config['timeout'] * 1000);
        }

        $persistentId = empty($this->config['persistent_id']) ? null : $this->config['persistent_id'];
        $this->memcached = new Memcached($persistentId);
        $this->memcached->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        if (!count($this->memcached->getServerList())) {
            if (isset($this->config['timeout']) && $this->config['timeout'] !== null) {
                $this->memcached->setOption(Memcached::OPT_RECV_TIMEOUT, $this->config['timeout'] * 1000);
                $this->memcached->setOption(Memcached::OPT_SEND_TIMEOUT, $this->config['timeout'] * 1000);
            }
            $this->memcached->addServers($this->config['servers']);
        }
    }

    /**
     *  Set cached value by key using Memcached.
     *
     * @access public
     * @static
     * @return bool
     */
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->memcached->set($this->prefix($key), $value, $ttl);
    }

    /**
     *  Get cached value by key using Memcached.
     *
     * @access public
     * @static
     * @return mixed
     */
    public function getValue(string $key): mixed
    {
        return $this->memcached->get($this->prefix($key));
    }

    /**
     *  Remove cached value by key using Memcached.
     *
     * @access public
     * @static
     * @return bool
     */
    public function removeKey(string $key): bool
    {
        return $this->memcached->delete($this->prefix($key));
    }
}
