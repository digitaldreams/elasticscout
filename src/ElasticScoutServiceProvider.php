<?php

namespace Rennokki\ElasticScout;

use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Scout\EngineManager;
use Rennokki\ElasticScout\Console\DeleteIndexCommand;
use Rennokki\ElasticScout\Console\MakeIndexCommand;
use Rennokki\ElasticScout\Console\MakeRuleCommand;
use Rennokki\ElasticScout\Console\SyncIndexCommand;

class ElasticScoutServiceProvider extends ServiceProvider
{
    /**
     * Map configuration array keys with ES ClientBuilder setters.
     *
     * @var array
     */
    protected $configMappings = [
        'sslVerification' => 'setSSLVerification',
        'sniffOnStart' => 'setSniffOnStart',
        'retries' => 'setRetries',
        'httpHandler' => 'setHandler',
        'connectionPool' => 'setConnectionPool',
        'connectionSelector' => 'setSelector',
        'serializer' => 'setSerializer',
        'connectionFactory'  => 'setConnectionFactory',
        'endpoint' => 'setEndpoint',
        'namespaces' => 'registerNamespace',
    ];

    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/elasticscout.php' => config_path('elasticscout.php'),
        ]);

        $this
            ->app
            ->make(EngineManager::class)
            ->extend('elasticscout', function () {
                $indexer = config('elasticscout.indexer', \Rennokki\ElasticScout\Indexers\SimpleIndexer::class);
                $syncMappingOnSave = config('elasticscout.sync_mapping_on_save', true);

                if (! class_exists($indexer)) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s indexer doesn\'t exist.',
                        $indexer
                    ));
                }

                return new ElasticScoutEngine(new $indexer(), $syncMappingOnSave);
            });

        $this->commands([
            SyncIndexCommand::class,
            DeleteIndexCommand::class,

            MakeIndexCommand::class,
            MakeRuleCommand::class,
        ]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this
            ->app
            ->singleton('elasticscout.client', function () {
                $connection = Config::get('elasticscout.connection', []);
                $clientBuilder = ClientBuilder::create();

                $clientBuilder->setHosts($connection['hosts']);

                // Set additional client configuration
                foreach ($this->configMappings as $key => $method) {
                    $value = Arr::get($connection, $key);

                    if (is_array($value)) {
                        foreach ($value as $vItem) {
                            $clientBuilder->$method($vItem);
                        }
                    } elseif ($value !== null) {
                        $clientBuilder->$method($value);
                    }
                }

                foreach ($connection['hosts'] as $host) {
                    if (isset($host['aws_enable']) && $host['aws_enable']) {
                        $clientBuilder->setHandler(function (array $request) use ($host) {
                            $psr7Handler = \Aws\default_http_handler();
                            $signer = new SignatureV4('es', $host['aws_region']);

                            // $request['headers']['Host'][0] = parse_url($request['headers']['Host'][0])['host'];

                            // Create a PSR-7 request from the array passed to the handler
                            $psr7Request = new Request(
                                $request['http_method'],
                                (new Uri($request['uri']))
                                    ->withScheme($request['scheme'])
                                    ->withHost($request['headers']['Host'][0]),
                                $request['headers'],
                                $request['body']
                            );

                            // Sign the PSR-7 request with credentials from the environment
                            $signedRequest = $signer->signRequest(
                                $psr7Request,
                                new Credentials($host['aws_key'], $host['aws_secret'])
                            );

                            // Send the signed request to Amazon ES.
                            /** @var \Psr\Http\Message\ResponseInterface $response */
                            $response = $psr7Handler($signedRequest)
                                ->then(function (\Psr\Http\Message\ResponseInterface $response) {
                                    return $response;
                                }, function ($error) {
                                    return $error['response'];
                                })->wait();

                            // Convert the PSR-7 response to a RingPHP response
                            return new CompletedFutureArray([
                                'status' => $response->getStatusCode(),
                                'headers' => $response->getHeaders(),
                                'body' => $response->getBody()->detach(),
                                'transfer_stats' => ['total_time' => 0],
                                'effective_url' => (string) $psr7Request->getUri(),
                            ]);
                        });
                    }
                }

                return $clientBuilder->build();
            });
    }
}
