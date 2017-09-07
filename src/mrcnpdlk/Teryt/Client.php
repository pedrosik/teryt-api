<?php
/**
 * TERYT-API
 *
 * Copyright (c) 2017 pudelek.org.pl
 *
 * For the full copyright and license information, please view source file
 * that is bundled with this package in the file LICENSE
 *
 * Author Marcin Pudełek <marcin@pudelek.org.pl>
 *
 */

declare (strict_types=1);

namespace mrcnpdlk\Teryt;

use mrcnpdlk\Teryt\Exception\Connection;
use mrcnpdlk\Teryt\Exception\Response;
use phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface;

/**
 * Class Client
 *
 * @package mrcnpdlk\Teryt
 */
class Client
{
    /**
     * Client instance
     *
     * @var \mrcnpdlk\Teryt\Client
     */
    protected static $_instance;
    /**
     * @var \mrcnpdlk\Teryt\TerytSoapClient
     */
    private $soapClient;
    /**
     * @var \phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface
     */
    private $oCache;
    /**
     * @var array
     */
    private $tTerytConfig = [];
    /**
     * @var array
     */
    private $tDefTerytConfig
        = [
            'url'      => 'https://uslugaterytws1test.stat.gov.pl/wsdl/terytws1.wsdl',
            'username' => 'TestPubliczny',
            'password' => '1234abcd',
        ];

    /**
     * Client constructor.
     */
    protected function __construct()
    {
        $this->tTerytConfig = $this->tDefTerytConfig;
    }

    /**
     * @return \mrcnpdlk\Teryt\Client
     */
    public static function create()
    {
        if (!static::$_instance) {
            static::$_instance = new static;
        }

        return static::$_instance;
    }

    /**
     * Get class instance
     *
     * @return \mrcnpdlk\Teryt\Client Instancja klasy
     * @throws \mrcnpdlk\Teryt\Exception
     */
    public static function getInstance()
    {
        if (!static::$_instance) {
            throw new Exception(sprintf('First use Client::create() method to instancate class'));
        }

        return static::$_instance;
    }

    /**
     * @param array $tConfig
     *
     * @return $this
     * @throws \mrcnpdlk\Teryt\Exception\Connection
     */
    public function setTerytConfig(array $tConfig = [])
    {
        if (empty($tConfig)) {
            $tConfig = $this->tDefTerytConfig;
        }
        $this->tTerytConfig['url']      = $tConfig['url'] ?? 'https://uslugaterytws1.stat.gov.pl/wsdl/terytws1.wsdl';
        $this->tTerytConfig['username'] = $tConfig['username'] ?? null;
        $this->tTerytConfig['password'] = $tConfig['password'] ?? null;;

        if (!$this->tTerytConfig['username'] || !$this->tTerytConfig['password']) {
            throw new Connection(sprintf('Username and password for TERYT WS1 is required'));
        }
        $this->getSoap(true);

        return $this;
    }

    /**
     * @param bool $bReinit
     *
     * @return \mrcnpdlk\Teryt\TerytSoapClient
     */
    private function getSoap(bool $bReinit = false)
    {
        try {
            if (!$this->soapClient || $bReinit) {
                $this->soapClient = new TerytSoapClient($this->tTerytConfig['url'], [
                    'soap_version' => SOAP_1_1,
                    'exceptions'   => true,
                    'cache_wsdl'   => WSDL_CACHE_BOTH,
                ]);
                $this->soapClient->addUserToken($this->tTerytConfig['username'], $this->tTerytConfig['password']);
            }

        } catch (\Exception $e) {
            Helper::handleException($e);
        }

        return $this->soapClient;
    }

    public function setLogger(\Psr\Log\LoggerInterface $oLogger = null)
    {
        Logger::create($oLogger);

        return $this;
    }

    /**
     * @param \phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface|null $oCache
     *
     * @return $this
     * @see \phpFastCache\CacheManager::setDefaultConfig();
     * @see https://packagist.org/packages/phpfastcache/phpfastcache Documentation od Phpfastcache
     */
    public function setCacheInstance(ExtendedCacheItemPoolInterface $oCache = null)
    {
        $this->oCache = $oCache;

        return $this;
    }

    /**
     * @param string $method
     * @param array  $args
     *
     * @return mixed
     * @throws \Throwable
     * @throws \mrcnpdlk\Teryt\Exception
     * @throws \mrcnpdlk\Teryt\Exception\Connection
     * @todo dodac logowanie IN/OUT oraz rozpoznawanie w cache ustawien seriwsu czy prod/test
     */
    public function request(string $method, array $args = [])
    {
        try {
            if (!array_key_exists('DataStanu', $args)) {
                $args['DataStanu'] = (new \DateTime())->format('Y-m-d');
            }
            $hashKey = md5(json_encode([__METHOD__, $method, $args]));
            $self    = $this;
            Logger::debug($method,$args);
            return $this->useCache(
                function () use ($self, $method, $args) {
                    $res       = $self->getSoap()->__soapCall($method, [$args]);
                    $resultKey = $method . 'Result';
                    if (!property_exists($res, $resultKey)) {
                        throw new Response(sprintf('%s doesnt exist in response', $resultKey));
                    }

                    return $res->{$resultKey};
                },
                $hashKey);

        } catch (\Exception $e) {
            throw Helper::handleException($e);
        }
    }

    /**
     * Cache management
     *
     * @param \Closure $closure
     * @param mixed    $hashKey
     * @param int|null $ttl
     *
     * @return mixed
     */
    private function useCache(\Closure $closure, $hashKey, int $ttl = null)
    {
        if (is_array($hashKey) || is_object($hashKey)) {
            $hashKey = md5(json_encode($hashKey));
        } else {
            $hashKey = strval($hashKey);
        }

        if ($this->oCache && $this->oCache->hasItem($hashKey)) {
            return $this->oCache->getItem($hashKey)->get();
        } else {
            $answer = $closure();

            if ($this->oCache) {
                $CachedString = $this->oCache
                    ->getItem($hashKey)
                    ->set($answer)
                ;
                if ($ttl) {
                    $CachedString->expiresAfter($ttl);
                }
                $this->oCache->save($CachedString); // Save the cache item just like you do with doctrine and entities
            }

            return $answer;
        }
    }
}
