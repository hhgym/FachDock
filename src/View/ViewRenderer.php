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

        return $content;
    }
}
