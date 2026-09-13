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
        $content = $this->versionScripts($content);
        $currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($currentPath) || $currentPath === '') {
            $currentPath = '/';
        }

        $content = (new NavigationRenderer())->inject($content, $data, $currentPath);
        $content = $this->injectAccessibilityShell($content);
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

        $lockerGridVersion = $this->assetVersion($root . '/public/assets/locker-grid.css');
        if ($lockerGridVersion !== null) {
            $updated = preg_replace(
                '~href="/assets/locker-grid\.css(?:\?[^\"]*)?"~',
                'href="/assets/locker-grid.css?v=' . $lockerGridVersion . '"',
                $content,
            );
            if (is_string($updated)) {
                $content = $updated;
            }
        }

        $navigationVersion = $this->assetVersion($root . '/public/assets/navigation.css');
        if ($navigationVersion !== null && !str_contains($content, '/assets/navigation.css')) {
            $content = $this->injectStylesheet($content, 'navigation.css', $navigationVersion);
        }

        $accessibilityVersion = $this->assetVersion($root . '/public/assets/accessibility.css');
        if ($accessibilityVersion !== null && !str_contains($content, '/assets/accessibility.css')) {
            $content = $this->injectStylesheet($content, 'accessibility.css', $accessibilityVersion);
        }

        if (str_contains($content, 'floorplan-page')) {
            $version = $this->assetVersion($root . '/public/assets/floorplans.css');
            if ($version !== null && !str_contains($content, '/assets/floorplans.css')) {
                $content = $this->injectStylesheet($content, 'floorplans.css', $version);
            }
        }
        if (str_contains($content, 'platform-page')) {
            $version = $this->assetVersion($root . '/public/assets/platform.css');
            if ($version !== null && !str_contains($content, '/assets/platform.css')) {
                $content = $this->injectStylesheet($content, 'platform.css', $version);
            }
        }

        return $content;
    }

    private function versionScripts(string $content): string
    {
        $root = dirname($this->templateDirectory);
        $version = $this->assetVersion($root . '/public/assets/app.js');
        if ($version !== null && !str_contains($content, '/assets/app.js')) {
            $script = '    <script src="/assets/app.js?v=' . $version . '" defer></script>' . "\n";
            $content = str_replace('</body>', $script . '</body>', $content);
        }

        if (str_contains($content, 'floorplan-page') && str_contains($content, 'data-booking-map-dialog-content')) {
            $mapVersion = $this->assetVersion($root . '/public/assets/locker-grid-map.js');
            if ($mapVersion !== null && !str_contains($content, '/assets/locker-grid-map.js')) {
                $script = '    <script src="/assets/locker-grid-map.js?v=' . $mapVersion . '" defer></script>' . "\n";
                $content = str_replace('</body>', $script . '</body>', $content);
            }
        }

        return $content;
    }

    private function injectAccessibilityShell(string $content): string
    {
        if (!str_contains($content, '<main')) {
            return $content;
        }

        if (!preg_match('~<main\b[^>]*\bid=~', $content)) {
            $updated = preg_replace('~<main\b([^>]*)>~', '<main id="main-content"$1>', $content, 1);
            if (is_string($updated)) {
                $content = $updated;
            }
        }

        if (!str_contains($content, 'class="skip-link"')) {
            $updated = preg_replace(
                '~<body([^>]*)>~',
                '<body$1><a class="skip-link" href="#main-content">Zum Hauptinhalt</a>',
                $content,
                1,
            );
            if (is_string($updated)) {
                $content = $updated;
            }
        }

        return $content;
    }

    private function injectStylesheet(string $content, string $name, string $version): string
    {
        $stylesheet = '    <link rel="stylesheet" href="/assets/' . $name . '?v=' . $version . '">' . "\n";

        return str_replace('</head>', $stylesheet . '</head>', $content);
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
