<?php

declare(strict_types=1);

namespace Tests\Laravel\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\Laravel\Middleware\GdprLogMiddleware;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;
use Tests\TestHelpers;

#[CoversClass(GdprLogMiddleware::class)]
final class GdprLogMiddlewareTest extends TestCase
{
    use TestHelpers;

    private GdprProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_MASKED, TestConstants::PATTERN_SECRET => Mask::MASK_MASKED],
            fieldPaths: []
        );
    }

    public function testMiddlewareCanBeInstantiated(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $this->assertInstanceOf(GdprLogMiddleware::class, $middleware);
    }

    public function testGetRequestBodyReturnsNullForGetRequest(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);
        $request = Request::create('/test', 'GET');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getRequestBody');

        $result = $method->invoke($middleware, $request);

        $this->assertNull($result);
    }

    public function testGetRequestBodyHandlesJsonContent(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $data = ['key' => 'value', TestConstants::CONTEXT_PASSWORD => 'secret'];
        $request = Request::create('/test', 'POST', [], [], [], [], json_encode($data));
        $request->headers->set('Content-Type', 'application/json');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getRequestBody');

        $result = $method->invoke($middleware, $request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('key', $result);
    }

    public function testGetRequestBodyHandlesFormUrlEncoded(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $request = Request::create('/test', 'POST', ['field' => 'value']);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getRequestBody');

        $result = $method->invoke($middleware, $request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('field', $result);
    }

    public function testGetRequestBodyHandlesMultipartFormData(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $request = Request::create('/test', 'POST', ['field' => 'value', '_token' => 'csrf-token']);
        $request->headers->set('Content-Type', 'multipart/form-data');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getRequestBody');

        $result = $method->invoke($middleware, $request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('field', $result);
        $this->assertArrayNotHasKey('_token', $result); // _token should be excluded
        $this->assertArrayHasKey('files', $result);
    }

    public function testGetRequestBodyReturnsNullForUnsupportedContentType(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $request = Request::create('/test', 'POST', []);
        $request->headers->set('Content-Type', 'text/plain');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getRequestBody');

        $result = $method->invoke($middleware, $request);

        $this->assertNull($result);
    }

    public function testGetResponseBodyReturnsNullForNonContentResponse(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $response = new class {
            // Response without getContent method
        };

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getResponseBody');

        $result = $method->invoke($middleware, $response);

        $this->assertNull($result);
    }

    public function testGetResponseBodyDecodesJsonResponse(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $responseData = ['status' => 'success', 'data' => 'value'];
        $response = new JsonResponse($responseData);

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getResponseBody');

        $result = $method->invoke($middleware, $response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('success', $result['status']);
    }

    public function testGetResponseBodyHandlesInvalidJson(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $response = new Response('invalid json {', 200);
        $response->headers->set('Content-Type', 'application/json');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getResponseBody');

        $result = $method->invoke($middleware, $response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid JSON response', $result['error']);
    }

    public function testGetResponseBodyTruncatesLongContent(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $longContent = str_repeat('a', 2000);
        $response = new Response($longContent, 200);
        $response->headers->set('Content-Type', 'text/html');

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('getResponseBody');

        $result = $method->invoke($middleware, $response);

        $this->assertIsString($result);
        $this->assertLessThanOrEqual(1003, strlen($result)); // 1000 + '...'
        $this->assertStringEndsWith('...', $result);
    }

    public function testFilterHeadersMasksSensitiveHeaders(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $headers = [
            'content-type' => ['application/json'],
            'authorization' => ['Bearer token123'],
            'x-api-key' => ['secret-key'],
            'cookie' => ['session=abc123'],
            'user-agent' => ['Mozilla/5.0'],
        ];

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('filterHeaders');

        $result = $method->invoke($middleware, $headers);

        $this->assertSame(['application/json'], $result['content-type']);
        $this->assertSame([Mask::MASK_FILTERED], $result['authorization']);
        $this->assertSame([Mask::MASK_FILTERED], $result['x-api-key']);
        $this->assertSame([Mask::MASK_FILTERED], $result['cookie']);
        $this->assertSame(['Mozilla/5.0'], $result['user-agent']);
    }

    public function testFilterHeadersIsCaseInsensitive(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $headers = [
            'Authorization' => ['Bearer token'],
            'X-API-KEY' => ['secret'],
            'COOKIE' => ['session'],
        ];

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('filterHeaders');

        $result = $method->invoke($middleware, $headers);

        $this->assertSame([Mask::MASK_FILTERED], $result['Authorization']);
        $this->assertSame([Mask::MASK_FILTERED], $result['X-API-KEY']);
        $this->assertSame([Mask::MASK_FILTERED], $result['COOKIE']);
    }

    public function testFilterHeadersFiltersAllSensitiveHeaderTypes(): void
    {
        $middleware = new GdprLogMiddleware($this->processor);

        $headers = [
            'set-cookie' => ['cookie-value'],
            'php-auth-user' => ['username'],
            'php-auth-pw' => [TestConstants::CONTEXT_PASSWORD],
            'x-auth-token' => ['token123'],
        ];

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('filterHeaders');

        $result = $method->invoke($middleware, $headers);

        $this->assertSame([Mask::MASK_FILTERED], $result['set-cookie']);
        $this->assertSame([Mask::MASK_FILTERED], $result['php-auth-user']);
        $this->assertSame([Mask::MASK_FILTERED], $result['php-auth-pw']);
        $this->assertSame([Mask::MASK_FILTERED], $result['x-auth-token']);
    }
}
