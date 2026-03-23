<?php

declare(strict_types=1);

namespace Qdrant\Transport\Rest;

use Qdrant\Exceptions\TransportException;

final class CurlHttpTransport implements HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new TransportException('Unable to initialize cURL for Qdrant request.');
        }

        $headers = [];

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $request->method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, (int) round(((float) $request->timeout) * 1000));
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, (int) round(((float) $request->timeout) * 1000));
        curl_setopt(
            $handle,
            CURLOPT_HEADERFUNCTION,
            static function ($curl, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($line);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $headers[trim($name)] = trim($value);

                return strlen($line);
            }
        );

        if ($request->headers !== []) {
            $flattened = [];
            foreach ($request->headers as $name => $value) {
                $flattened[] = sprintf('%s: %s', $name, $value);
            }
            curl_setopt($handle, CURLOPT_HTTPHEADER, $flattened);
        }

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $body = curl_exec($handle);
        if ($body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new TransportException(
                $message !== '' ? $message : 'Qdrant HTTP request failed without a cURL error message.'
            );
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, $headers, (string) $body);
    }
}
