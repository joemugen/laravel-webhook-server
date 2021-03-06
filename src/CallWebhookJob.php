<?php

namespace Spatie\WebhookServer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;

class CallWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var string|null
     */
    public $webhookUrl;

    /**
     * @var string
     */
    public $httpVerb;

    /**
     * @var int
     */
    public $tries;

    /**
     * @var int
     */
    public $requestTimeout;

    /**
     * @var string
     */
    public $backoffStrategyClass;

    /**
     * @var string|null
     */
    public $signerClass;

    /**
     * @var array
     */
    public $headers = [];

    /**
     * @var bool
     */
    public $verifySsl;

    /** @var string */
    public $queue;

    /**
     * @var array
     */
    public $payload = [];

    /**
     * @var array
     */
    public $meta = [];

    /**
     * @var array
     */
    public $tags = [];

    /**
     * @var string
     */
    public $uuid = '';

    /**
     * @var Response|null
     */
    private $response;

    /**
     * @var string|null
     */
    private $errorType;

    /**
     * @var string|null
     */
    private $errorMessage;

    public function handle()
    {
        /** @var \GuzzleHttp\Client $client */
        $client = app(Client::class);

        $lastAttempt = $this->attempts() >= $this->tries;

        try {
            $body = strtoupper($this->httpVerb) === 'GET'
                ? ['query' => $this->payload]
                : ['body' => json_encode($this->payload)];

            $this->response = $client->request($this->httpVerb, $this->webhookUrl, array_merge([
                'timeout' => $this->requestTimeout,
                'verify' => $this->verifySsl,
                'headers' => $this->headers,
            ], $body));

            if (! Str::startsWith($this->response->getStatusCode(), 2)) {
                throw new Exception('Webhook call failed');
            }

            $this->dispatchEvent(WebhookCallSucceededEvent::class);

            return;
        } catch (Exception $exception) {
            if ($exception instanceof RequestException) {
                $this->response = $exception->getResponse();
                $this->errorType = get_class($exception);
                $this->errorMessage = $exception->getMessage();
            }

            if (! $lastAttempt) {
                /** @var \Spatie\WebhookServer\BackoffStrategy\BackoffStrategy $backoffStrategy */
                $backoffStrategy = app($this->backoffStrategyClass);

                $waitInSeconds = $backoffStrategy->waitInSecondsAfterAttempt($this->attempts());

                $this->release($waitInSeconds);
            }

            $this->dispatchEvent(WebhookCallFailedEvent::class);
        }

        if ($lastAttempt) {
            $this->dispatchEvent(FinalWebhookCallFailedEvent::class);

            $this->delete();
        }
    }

    public function tags()
    {
        return $this->tags;
    }

    public function getResponse()
    {
        return $this->response;
    }

    private function dispatchEvent(string $eventClass)
    {
        event(new $eventClass(
            $this->httpVerb,
            $this->webhookUrl,
            $this->payload,
            $this->headers,
            $this->meta,
            $this->tags,
            $this->attempts(),
            $this->response,
            $this->errorType,
            $this->errorMessage,
            $this->uuid
        ));
    }
}
