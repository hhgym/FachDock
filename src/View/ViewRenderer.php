<?php

declare(strict_types=1);

namespace FachDock\View;

use FachDock\Parent\AuthenticatedParent;
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

        $content = (new NavigationRenderer())->inject($content, $data, $currentPath);
        $parent = $data['parent'] ?? null;
        if ($parent instanceof AuthenticatedParent && $parent->adminPreview) {
            $csrfToken = isset($data['csrfToken']) && is_string($data['csrfToken']) ? $data['csrfToken'] : '';
            $content = $this->injectAdminParentPreviewBanner($content, $parent, $csrfToken);
        }

        return $content;
    }

    private function injectAdminParentPreviewBanner(
        string $content,
        AuthenticatedParent $parent,
        string $csrfToken,
    ): string {
        $name = htmlspecialchars($parent->displayName(), ENT_QUOTES, 'UTF-8');
        $csrf = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $banner = '<aside class="alert alert-neutral admin-parent-preview-banner" role="status">'
            . '<div><strong>Administrator-Testansicht</strong>'
            . '<span>Du siehst das Elternportal als ' . $name
            . '. Für diesen Zugriff wurde keine E-Mail-Anmeldung durchgeführt.</span></div>';
        if ($csrfToken !== '') {
            $banner .= '<form method="post" action="/admin/parents/preview/stop">'
                . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<button class="button button-secondary" type="submit">Testansicht beenden</button>'
                . '</form>';
        }
        $banner .= '</aside>';

        $updated = preg_replace('~</header>~', '</header>' . $banner, $content, 1);

        return is_string($updated) ? $updated : $content;
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
        if (!is_string($hash)) {
            return null;
        }

        return substr($hash, 0, 12);
    }
}
