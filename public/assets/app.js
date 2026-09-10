(() => {
    'use strict';

    const destructiveActions = new Map([
        ['/admin/bookings/end', 'Diese Buchung wirklich beenden? Das Schließfach wird sofort freigegeben. Bereits verbuchte Zahlungen werden nicht automatisch erstattet.'],
        ['/admin/bookings/cancel', 'Diese Buchung wirklich stornieren? Das Schließfach wird sofort freigegeben. Bereits verbuchte Zahlungen werden nicht automatisch erstattet.'],
    ]);

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const rawAction = form.getAttribute('action');
        if (rawAction === null) {
            return;
        }
        let pathname;
        try {
            pathname = new URL(rawAction, window.location.href).pathname;
        } catch (_error) {
            return;
        }

        const message = destructiveActions.get(pathname);
        if (message !== undefined && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const openMenu = document.querySelector(
                'details.mobile-navigation[open], details.account-menu[open], details.nav-menu[open]'
            );
            if (openMenu instanceof HTMLDetailsElement) {
                const summary = openMenu.querySelector(':scope > summary');
                openMenu.open = false;
                if (summary instanceof HTMLElement) {
                    summary.focus();
                }
            }
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.matches('.floorplan-marker[data-booking-map-group]')) {
            return;
        }

        const markers = Array.from(document.querySelectorAll('.floorplan-marker[data-booking-map-group]'))
            .filter((item) => item instanceof HTMLElement);
        const index = markers.indexOf(target);
        if (index < 0 || markers.length < 2) {
            return;
        }

        let nextIndex = null;
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            nextIndex = (index + 1) % markers.length;
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            nextIndex = (index - 1 + markers.length) % markers.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = markers.length - 1;
        }

        if (nextIndex !== null) {
            event.preventDefault();
            markers[nextIndex].focus();
        }
    });

    const firstError = document.querySelector('.alert-error');
    if (firstError instanceof HTMLElement) {
        firstError.setAttribute('tabindex', '-1');
        firstError.focus({preventScroll: false});
    }
})();
