<?php
namespace Shel\Neos\NginxCache\Service;

use FOS\HttpCache\CacheInvalidator;
use FOS\HttpCache\Exception\ExceptionCollection;
use FOS\HttpCache\Exception\ProxyResponseException;
use FOS\HttpCache\Exception\ProxyUnreachableException;
use FOS\HttpCache\ProxyClient;
use Http\Client\Common\Plugin\ErrorPlugin;
use Http\Client\Common\PluginClient;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Exception;
use Psr\Log\LoggerInterface;
use Http\Adapter\Guzzle6\Client as Guzzle6;

/**
 * @Flow\Scope("singleton")
 */
class CacheFlushService
{

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var ProxyClient\Nginx
     */
    protected $nginxProxyClient;

    /**
     * @var CacheInvalidator
     */
    protected $cacheInvalidator;

    /**
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @return void
     */
    public function initializeObject()
    {
        $servers = is_array($this->settings['servers']) ? $this->settings['servers'] : [$this->settings['servers'] ?: '127.0.0.1'];
        $baseUri = $this->settings['baseUri'] ?: '';

        $client = Guzzle6::createWithConfig([
            'base_uri' => $baseUri,
            'verify' => false
        ]);

        $httpClient = new PluginClient(
            $client,
            [new ErrorPlugin()]
        );

        $httpDispatcher = new ProxyClient\HttpDispatcher($servers, $baseUri, $httpClient);
        $options = [];

        $this->nginxProxyClient = new ProxyClient\Nginx($httpDispatcher, $options);
        $this->cacheInvalidator = new CacheInvalidator($this->nginxProxyClient);
    }

    /**
     * @param $path
     */
    public function refreshPath($path)
    {
        $this->logger->info('Refreshed path ' . $path);
        $this->cacheInvalidator->refreshPath($path);
        $this->execute();
    }

    /**
     * @param $path
     */
    public function invalidatePath($path)
    {
        if ($this->settings['purge']['installed']) {
            $this->logger->info('Invalidated path ' . $path);
            $this->cacheInvalidator->invalidatePath($path);
            $this->execute();
        } else {
            $this->refreshPath($path);
        }
    }

    /**
     * @return void
     */
    protected function execute()
    {
        try {
            $this->cacheInvalidator->flush();
        } catch (ExceptionCollection $exceptions) {
            foreach ($exceptions as $exception) {
                if ($exception instanceof ProxyResponseException) {
                    $this->logger->error(sprintf('Error calling nginx with request (cannot connect to the caching proxy). Error %s',
                        $exception->getMessage()));
                } elseif ($exception instanceof ProxyUnreachableException) {
                    $this->logger->error(sprintf('Error calling nginx with request (caching proxy returned an error response). Error %s',
                        $exception->getMessage()));
                } else {
                    $this->logger->error(sprintf('Error calling nginx with request. Error %s',
                        $exception->getMessage()));
                }
            }
        }
    }
}
