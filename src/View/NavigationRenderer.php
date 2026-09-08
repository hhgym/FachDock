<?php

declare(strict_types=1);

namespace FachDock\View;

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Parent\AuthenticatedParent;

final class NavigationRenderer
{
    /** @param array<string, mixed> $data */
    public function inject(string $content, array $data, string $currentPath): string
    {
        $csrfToken = isset($data['csrfToken']) && is_string($data['csrfToken'])
            ? $data['csrfToken']
            : '';
        $navigation = null;
        $staff = $data['staff'] ?? null;
        $parent = $data['parent'] ?? null;

        if ($staff instanceof AuthenticatedStaff) {
            $navigation = $this->staff($staff, $csrfToken, $currentPath);
        } elseif ($parent instanceof AuthenticatedParent) {
            $navigation = $this->parent($parent, $csrfToken, $currentPath);
        }

        if ($navigation === null) {
            return $content;
        }

        $updated = preg_replace(
            '~<header\s+class="[^"]*\btopbar\b[^"]*"[^>]*>.*?</header>~s',
            $navigation,
            $content,
            1,
        );

        return is_string($updated) ? $updated : $content;
    }

    public function staff(AuthenticatedStaff $staff, string $csrfToken, string $currentPath): string
    {
        $groups = $this->staffGroups($staff);
        $dashboard = $this->link('Dashboard', '/', $currentPath);
        $account = $this->accountMenu(
            $staff->displayName,
            $staff->role->label(),
            '/account/password',
            '/account/sessions',
            '/logout',
            $csrfToken,
            $currentPath,
        );

        return '<header class="topbar app-header">'
            . '<div class="topbar-inner">'
            . $this->brand('Verwaltung', '/')
            . '<nav class="primary-nav" aria-label="Hauptnavigation">'
            . $dashboard
            . $this->desktopGroups($groups, $currentPath)
            . '</nav>'
            . $account
            . $this->mobileStaffMenu($groups, $staff, $csrfToken, $currentPath)
            . '</div>'
            . '</header>';
    }

    public function parent(AuthenticatedParent $parent, string $csrfToken, string $currentPath): string
    {
        $overview = $this->link('Übersicht', '/parent', $currentPath);
        $booking = $this->link(
            'Schließfach buchen',
            '/parent/booking',
            $currentPath,
            ['/parent/booking', '/parent/payment'],
        );

        return '<header class="topbar app-header">'
            . '<div class="topbar-inner">'
            . $this->brand('Elternportal', '/parent')
            . '<nav class="primary-nav" aria-label="Elternportal">'
            . $overview
            . $booking
            . '</nav>'
            . $this->parentAccountMenu($parent, $csrfToken)
            . $this->mobileParentMenu($parent, $csrfToken, $currentPath)
            . '</div>'
            . '</header>';
    }

    /**
     * @return list<array{
     *     label: string,
     *     items: list<array{label: string, href: string, matches?: list<string>}>
     * }>
     */
    private function staffGroups(AuthenticatedStaff $staff): array
    {
        $groups = [
            [
                'label' => 'Schließfächer',
                'items' => [
                    ['label' => 'Standorte', 'href' => '/admin/locations'],
                ],
            ],
            [
                'label' => 'Buchungen',
                'items' => [
                    [
                        'label' => 'Buchungen',
                        'href' => '/admin/bookings',
                        'matches' => ['/admin/bookings'],
                    ],
                    [
                        'label' => 'Zahlungen',
                        'href' => '/admin/payments',
                        'matches' => ['/admin/payments'],
                    ],
                    ['label' => 'BuT-Prüfung', 'href' => '/admin/but'],
                ],
            ],
        ];

        if (!$staff->isAdministrator()) {
            return $groups;
        }

        $groups[0]['items'][] = ['label' => 'Empfehlungen', 'href' => '/admin/recommendations'];
        $groups[1]['items'][] = ['label' => 'Buchungsauswahl', 'href' => '/admin/booking-selection'];
        $groups[] = [
            'label' => 'Personen',
            'items' => [
                ['label' => 'Schüler', 'href' => '/admin/students'],
                ['label' => 'Eltern', 'href' => '/admin/parents'],
            ],
        ];
        $groups[] = [
            'label' => 'Konfiguration',
            'items' => [
                ['label' => 'Schuljahre', 'href' => '/admin/school-years'],
                ['label' => 'Zuteilungsregeln', 'href' => '/admin/allocation-rules'],
                ['label' => 'E-Mail', 'href' => '/admin/mail'],
                [
                    'label' => 'Stripe & Zahlung',
                    'href' => '/admin/config/stripe',
                    'matches' => ['/admin/config/stripe'],
                ],
            ],
        ];
        $groups[] = [
            'label' => 'System',
            'items' => [
                ['label' => 'Updates', 'href' => '/admin/system/update'],
            ],
        ];

        return $groups;
    }

    /**
     * @param list<array{
     *     label: string,
     *     items: list<array{label: string, href: string, matches?: list<string>}>
     * }> $groups
     */
    private function desktopGroups(array $groups, string $currentPath): string
    {
        $html = '';
        foreach ($groups as $group) {
            $activeClass = $this->groupIsActive($group['items'], $currentPath)
                ? ' nav-menu-active'
                : '';
            $html .= '<details class="nav-menu' . $activeClass . '">';
            $html .= '<summary>' . $this->escape($group['label']) . '</summary>';
            $html .= '<div class="nav-popover">';
            foreach ($group['items'] as $item) {
                $html .= $this->link(
                    $item['label'],
                    $item['href'],
                    $currentPath,
                    $item['matches'] ?? [],
                );
            }
            $html .= '</div></details>';
        }

        return $html;
    }

    /**
     * @param list<array{
     *     label: string,
     *     items: list<array{label: string, href: string, matches?: list<string>}>
     * }> $groups
     */
    private function mobileStaffMenu(
        array $groups,
        AuthenticatedStaff $staff,
        string $csrfToken,
        string $currentPath,
    ): string {
        $html = '<details class="mobile-navigation">'
            . '<summary><span class="mobile-menu-icon" aria-hidden="true">☰</span>'
            . '<span class="mobile-menu-label">Menü</span></summary>'
            . '<div class="mobile-navigation-panel">';
        $html .= '<div class="mobile-nav-home">' . $this->link('Dashboard', '/', $currentPath) . '</div>';

        foreach ($groups as $group) {
            $active = $this->groupIsActive($group['items'], $currentPath);
            $html .= '<details class="mobile-nav-section' . ($active ? ' mobile-nav-section-active' : '') . '"'
                . ($active ? ' open' : '') . '>';
            $html .= '<summary>' . $this->escape($group['label']) . '</summary>';
            $html .= '<div class="mobile-nav-section-links">';
            foreach ($group['items'] as $item) {
                $html .= $this->link(
                    $item['label'],
                    $item['href'],
                    $currentPath,
                    $item['matches'] ?? [],
                );
            }
            $html .= '</div></details>';
        }

        $html .= '<div class="mobile-account-card">';
        $html .= '<span class="mobile-account-label">Konto</span>';
        $html .= '<strong class="mobile-account-name">' . $this->escape($staff->displayName) . '</strong>';
        $html .= '<span class="mobile-account-role">' . $this->escape($staff->role->label()) . '</span>';
        $html .= '<div class="mobile-account-links">';
        $html .= $this->link('Passwort', '/account/password', $currentPath);
        $html .= $this->link('Sitzungen', '/account/sessions', $currentPath);
        $html .= $this->logoutForm('/logout', $csrfToken);
        $html .= '</div></div></div></details>';

        return $html;
    }

    private function mobileParentMenu(
        AuthenticatedParent $parent,
        string $csrfToken,
        string $currentPath,
    ): string {
        $html = '<details class="mobile-navigation">'
            . '<summary><span class="mobile-menu-icon" aria-hidden="true">☰</span>'
            . '<span class="mobile-menu-label">Menü</span></summary>'
            . '<div class="mobile-navigation-panel">';
        $html .= '<div class="mobile-parent-links">';
        $html .= $this->link('Übersicht', '/parent', $currentPath);
        $html .= $this->link(
            'Schließfach buchen',
            '/parent/booking',
            $currentPath,
            ['/parent/booking', '/parent/payment'],
        );
        $html .= '</div>';
        $html .= '<div class="mobile-account-card">';
        $html .= '<span class="mobile-account-label">Elternkonto</span>';
        $html .= '<strong class="mobile-account-name">' . $this->escape($parent->displayName()) . '</strong>';
        $html .= '<div class="mobile-account-links">' . $this->logoutForm('/parent/logout', $csrfToken) . '</div>';
        $html .= '</div></div></details>';

        return $html;
    }

    private function accountMenu(
        string $displayName,
        string $role,
        string $passwordHref,
        string $sessionsHref,
        string $logoutAction,
        string $csrfToken,
        string $currentPath,
    ): string {
        $activeClass = $this->isActive($currentPath, [$passwordHref, $sessionsHref])
            ? ' account-menu-active'
            : '';

        return '<details class="account-menu' . $activeClass . '">'
            . '<summary><span class="account-name">' . $this->escape($displayName) . '</span>'
            . '<span class="account-role">' . $this->escape($role) . '</span></summary>'
            . '<div class="nav-popover nav-popover-right">'
            . $this->link('Passwort', $passwordHref, $currentPath)
            . $this->link('Sitzungen', $sessionsHref, $currentPath)
            . $this->logoutForm($logoutAction, $csrfToken)
            . '</div></details>';
    }

    private function parentAccountMenu(AuthenticatedParent $parent, string $csrfToken): string
    {
        return '<details class="account-menu">'
            . '<summary><span class="account-name">' . $this->escape($parent->displayName()) . '</span>'
            . '<span class="account-role">Elternkonto</span></summary>'
            . '<div class="nav-popover nav-popover-right">'
            . $this->logoutForm('/parent/logout', $csrfToken)
            . '</div></details>';
    }

    private function brand(string $areaLabel, string $href): string
    {
        return '<a class="app-brand" href="' . $this->escape($href) . '">'
            . '<span class="app-brand-mark" aria-hidden="true">FD</span>'
            . '<span class="app-brand-text"><strong>FachDock</strong><small>'
            . $this->escape($areaLabel)
            . '</small></span></a>';
    }

    /** @param list<string> $matches */
    private function link(string $label, string $href, string $currentPath, array $matches = []): string
    {
        $matches = $matches === [] ? [$href] : $matches;
        $current = $this->isActive($currentPath, $matches) ? ' aria-current="page"' : '';

        return '<a class="nav-link" href="' . $this->escape($href) . '"' . $current . '>'
            . $this->escape($label)
            . '</a>';
    }

    private function logoutForm(string $action, string $csrfToken): string
    {
        if ($csrfToken === '') {
            return '';
        }

        return '<form class="nav-logout" method="post" action="' . $this->escape($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . $this->escape($csrfToken) . '">'
            . '<button class="nav-link nav-logout-button" type="submit">Abmelden</button>'
            . '</form>';
    }

    /**
     * @param list<array{label: string, href: string, matches?: list<string>}> $items
     */
    private function groupIsActive(array $items, string $currentPath): bool
    {
        foreach ($items as $item) {
            if ($this->isActive($currentPath, $item['matches'] ?? [$item['href']])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $matches */
    private function isActive(string $currentPath, array $matches): bool
    {
        foreach ($matches as $match) {
            if ($match === '/' && $currentPath === '/') {
                return true;
            }
            if ($match !== '/' && ($currentPath === $match || str_starts_with($currentPath, $match . '/'))) {
                return true;
            }
        }

        return false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
