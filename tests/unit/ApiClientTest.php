<?php

namespace ComplyCube\Tests\Unit;

use ComplyCube\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleRetry\GuzzleRetryMiddleware;

/**
 * @covers \ComplyCube\ApiClient
 */
class ApiClientTest extends \PHPUnit\Framework\TestCase
{
    protected $apiClient;
    protected $mockHandler;

    protected function setUp() : void
    {
        $this->mockHandler = new MockHandler();
        $httpClient = new Client([
            'handler' => $this->mockHandler
        ]);
        $this->apiClient = new ApiClient('', 2);
        $this->apiClient->httpClient = $httpClient;
    }
    
    public function testGetRequest()
    {
        $this->mockHandler->append(new Response(200, [], "{ \"id\":\"value\"}"));
        $apiResponse = $this->apiClient->get('endpoint');
        $responseBody  = $apiResponse->getDecodedBody();
        $this->assertEquals(200, $apiResponse->getHttpStatusCode());
        $this->assertObjectHasAttribute('id', $responseBody);
        $this->assertEquals('value', $responseBody->id);
    }

    public function testNullBodyPostRequest()
    {
        $this->mockHandler->append(new Response(200, [], "{}"));
        $apiResponse = $this->apiClient->post('endpoint', [], null);
        $responseBody  = $apiResponse->getDecodedBody();
        $this->assertEquals(200, $apiResponse->getHttpStatusCode());
    }

    public function testEmptyPostRequest()
    {
        $this->mockHandler->append(new Response(200, [], "{}"));
        $apiResponse = $this->apiClient->post('endpoint', [], (object)[]);
        $this->assertEquals(200, $apiResponse->getHttpStatusCode());
    }

    public function testDeleteRequest()
    {
        $this->mockHandler->append(new Response(200, [], "{}"));
        $apiResponse = $this->apiClient->delete('endpoint');
        $this->assertEquals(null, $apiResponse);
    }

    public function testRetryAttemptRequest()
    {
        $value = ApiClient::randomJitter(3, null);
        $this->assertLessThan(3**1.5, $value);
    }

    public function testClientExceptionRequest()
    {
        $this->expectException(\ComplyCube\Exception\ComplyCubeClientException::class);
        $stack = HandlerStack::create(new MockHandler([new Response(404, [], "{}")]));
        $stack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 1,
            'default_retry_multiplier' => [ApiClient::class, 'randomJitter']]));
        $this->apiClient->httpClient = new Client(['handler' => $stack]);
        $apiResponse = $this->apiClient->get('endpoint');
    }

    public function testRetryPass()
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(503, [], "{}"),
            new Response(200, [], "{}"),
            new Response(503, [], "{}")
        ]));
        $stack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 1,
            'default_retry_multiplier' => function ($numRequests, $response): float {
                return (float) rand(0, $numRequests ** 1.5);
            }
            ]));
        $this->apiClient->httpClient = new Client(['handler' => $stack]);
        $apiResponse = $this->apiClient->get('endpoint');
        $this->assertEquals(200, $apiResponse->getHttpStatusCode());
    }

    public function testRetryFail()
    {
        $this->expectException(\ComplyCube\Exception\ComplyCubeServerException::class);
        $stack = HandlerStack::create(new MockHandler([
            new Response(503, [], "{}"),
            new Response(503, [], "{}"),
            new Response(200, [], "{}")
        ]));
        $stack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 1,
            'default_retry_multiplier' => function ($numRequests, $response): float {
                return (float) rand(0, $numRequests ** 1.5);
            }
            ]));
        $this->apiClient->httpClient = new Client(['handler' => $stack]);
        $apiResponse = $this->apiClient->get('endpoint', []);
    }

}
