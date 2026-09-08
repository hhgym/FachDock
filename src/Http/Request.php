<?php

declare(strict_types=1);

namespace FachDock\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $rawBody = file_get_contents('php://input');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            is_string($rawBody) ? $rawBody : '',
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function post(): array
    {
        return $this->post;
    }

    public function postString(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function uploadedFile(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file)) {
            return null;
        }

        $name = $file['name'] ?? null;
        $temporaryPath = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        $error = $file['error'] ?? null;
        if (!is_scalar($name) || !is_scalar($temporaryPath) || !is_numeric($size) || !is_numeric($error)) {
            return null;
        }

        return new UploadedFile(
            (string) $name,
            (string) $temporaryPath,
            (int) $size,
            (int) $error,
        );
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function header(string $name): string
    {
        $normalized = strtoupper(str_replace('-', '_', trim($name)));
        $serverKey = match ($normalized) {
            'CONTENT_TYPE', 'CONTENT_LENGTH' => $normalized,
            default => 'HTTP_' . $normalized,
        };
        $value = $this->server[$serverKey] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function clientIp(): string
    {
        $value = $this->server['REMOTE_ADDR'] ?? '';

        return is_scalar($value) ? mb_substr((string) $value, 0, 45) : '';
    }

    public function userAgent(): string
    {
        $value = $this->server['HTTP_USER_AGENT'] ?? '';

        return is_scalar($value) ? mb_substr((string) $value, 0, 500) : '';
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
