<?php

namespace Ivuorinen\MonologGdprFilter\Laravel\Middleware;

use JsonException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

/**
 * Middleware for GDPR-compliant request/response logging.
 *
 * This middleware automatically logs HTTP requests and responses
 * with GDPR filtering applied to sensitive data.
 *
 * @api
 */
class GdprLogMiddleware
{
    protected GdprProcessor $processor;

    public function __construct(GdprProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        // Log the incoming request
        $this->logRequest($request);

        // Process the request
        $response = $next($request);

        // Log the response
        $this->logResponse($request, $response, $startTime);

        return $response;
    }

    /**
     * Log the incoming request with GDPR filtering.
     */
    protected function logRequest(Request $request): void
    {
        $requestData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->filterHeaders($request->headers->all()),
            'query' => $request->query(),
            'body' => $this->getRequestBody($request),
        ];

        // Apply GDPR filtering to the entire request data
        $this->processor->recursiveMask($requestData);

        Log::info('HTTP Request', $filteredData);
    }

    /**
     * Log the response with GDPR filtering.
     */
    protected function logResponse(Request $request, $response, float $startTime): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $responseData = [
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'content_length' => $response->headers->get('Content-Length'),
            'response_headers' => $this->filterHeaders($response->headers->all()),
        ];

        // Only log response body for errors or if specifically configured
        if ($response->getStatusCode() >= 400 && config('gdpr.log_error_responses', false)) {
            $responseData['body'] = $this->getResponseBody($response);
        }

        // Apply GDPR filtering
        $this->processor->recursiveMask($responseData);

        $response->getStatusCode() >= 500 ? 'error' : ($response->getStatusCode() >= 400 ? 'warning' : 'info');

        Log::$level('HTTP Response', array_merge(
            ['method' => $request->method(), 'url' => $request->fullUrl()],
            $filteredData
        ));
    }

    /**
     * Get request body safely.
     */
    protected function getRequestBody(Request $request): mixed
    {
        // Only log body for specific content types and methods
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return null;
        }

        $contentType = $request->header('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            return $request->json()->all();
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $request->all();
        }

        if (str_contains($contentType, 'multipart/form-data')) {
            // Don't log file uploads, just the form fields
            return $request->except(['_token']) + ['files' => array_keys($request->allFiles())];
        }

        return null;
    }

    /**
     * Get response body safely.
     */
    protected function getResponseBody($response): mixed
    {
        if (!method_exists($response, 'getContent')) {
            return null;
        }

        $content = $response->getContent();

        // Try to decode JSON responses
        if (
            $response->headers->get('Content-Type') &&
            str_contains((string) $response->headers->get('Content-Type'), 'application/json')
        ) {
            try {
                return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return ['error' => 'Invalid JSON response'];
            }
        }

        // For other content types, limit length to prevent massive logs
        return strlen((string) $content) > 1000 ? substr((string) $content, 0, 1000) . '...' : $content;
    }

    /**
     * Filter sensitive headers.
     */
    protected function filterHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'x-api-key',
            'x-auth-token',
            'cookie',
            'set-cookie',
            'php-auth-user',
            'php-auth-pw',
        ];

        $filtered = [];
        foreach ($headers as $name => $value) {
            $filtered[$name] = in_array(strtolower($name), $sensitiveHeaders) ? ['***FILTERED***'] : $value;
        }

        return $filtered;
    }
}
