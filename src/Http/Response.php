<?php

declare(strict_types=1);

namespace FachDock\Http;

use FachDock\Security\SecurityHeaders;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public static function text(string $body, string $contentType = 'text/plain; charset=utf-8', int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => $contentType]);
    }

    public static function download(string $body, string $filename, string $contentType = 'application/octet-stream'): self
    {
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

        return new self($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if ($status === 302 && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $status = 303;
        }

        return new self('', $status, [
            'Location' => $location,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $headers = SecurityHeaders::forRequest();
        foreach ($this->headers as $name => $value) {
            $headers[$name] = $value;
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
