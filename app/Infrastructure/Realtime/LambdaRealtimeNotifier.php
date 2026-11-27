<?php

namespace App\Infrastructure\Realtime;

use Aws\Lambda\LambdaClient;

/**
 * Delivers hint-only realtime envelopes to the realtime Lambda (API Gateway WebSocket
 * fan-out) with an asynchronous invoke. Replaces Redis Pub/Sub where no long-lived
 * subscriber process exists. Credentials come from the default AWS provider chain
 * (the Lambda execution role in AWS).
 */
class LambdaRealtimeNotifier
{
    private ?LambdaClient $client;

    public function __construct(?LambdaClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function dispatch(array $envelope): void
    {
        $this->client()->invoke([
            'FunctionName' => (string) config('pvp.realtime.lambda.function'),
            'InvocationType' => 'Event',
            'Payload' => json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
    }

    private function client(): LambdaClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $timeout = (float) config('pvp.realtime.lambda.timeout_seconds', 2);
        $args = [
            'version' => '2015-03-31',
            'region' => (string) config('pvp.realtime.lambda.region', 'us-east-1'),
            'retries' => 1,
            'http' => [
                'connect_timeout' => $timeout,
                'timeout' => $timeout,
            ],
        ];

        $endpoint = config('pvp.realtime.lambda.endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $args['endpoint'] = $endpoint;
        }

        return $this->client = new LambdaClient($args);
    }
}
