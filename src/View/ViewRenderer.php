<?php

declare(strict_types=1);

namespace FachDock\View;

use RuntimeException;

final class ViewRenderer
{
    public function __construct(private readonly string $templateDirectory)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDirectory . '/' . ltrim($template, '/');
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = ob_get_clean();

        if (!is_string($content)) {
            throw new RuntimeException('Unable to render template: ' . $template);
        }

        $content = $this->versionStylesheets($content);
        $currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($currentPath) || $currentPath === '') {
            $currentPath = '/';
        }

        return (new NavigationRenderer())->inject($content, $data, $currentPath);
    }

    private function versionStylesheets(string $content): string
    {
        $root = dirname($this->templateDirectory);
        $appVersion = $this->assetVersion($root . '/public/assets/app.css');
        if ($appVersion !== null) {
            $updated = preg_replace(
                '~href="/assets/app\.css(?:\?[^\"]*)?"~',
                'href="/assets/app.css?v=' . $appVersion . '"',
                $content,
                1,
            );
            if (is_string($updated)) {
                $content = $updated;
            }
        }

        $navigationVersion = $this->assetVersion($root . '/public/assets/navigation.css');
        if ($navigationVersion !== null && !str_contains($content, '/assets/navigation.css')) {
            $stylesheet = '    <link rel="stylesheet" href="/assets/navigation.css?v=' . $navigationVersion . '">' . "\n";
            $content = str_replace('</head>', $stylesheet . '</head>', $content);
        }

        return $content;
    }

    private function assetVersion(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $hash = hash_file('sha256', $file);
        if (!is_string($hash) || $hash === '') {
            return null;
        }

        return substr($hash, 0, 12);
    }
}
