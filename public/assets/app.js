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

    const desktopMenus = document.querySelectorAll('details.nav-menu, details.account-menu');
    desktopMenus.forEach((menu) => {
        if (!(menu instanceof HTMLDetailsElement)) {
            return;
        }

        let closeTimer = null;
        const cancelClose = () => {
            if (closeTimer !== null) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
        };

        menu.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'mouse') {
                cancelClose();
            }
        });

        menu.addEventListener('pointerleave', (event) => {
            if (event.pointerType !== 'mouse' || !menu.open) {
                return;
            }

            cancelClose();
            closeTimer = window.setTimeout(() => {
                menu.open = false;
                closeTimer = null;
            }, 120);
        });

        menu.addEventListener('toggle', () => {
            if (!menu.open) {
                cancelClose();
            }
        });
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

    const editableFloorplan = document.querySelector('.floorplan-canvas[data-editable="1"]');
    if (editableFloorplan instanceof HTMLElement) {
        const floorplanScroll = editableFloorplan.closest('.floorplan-scroll');
        const zoomLabel = document.querySelector('[data-floorplan-zoom-label]');
        const clamp = (value, minimum, maximum) => Math.max(minimum, Math.min(maximum, value));

        const currentZoom = () => {
            if (!(zoomLabel instanceof HTMLElement)) {
                return 1;
            }
            const match = (zoomLabel.textContent || '').match(/(\d+)/);
            if (match === null) {
                return 1;
            }

            return clamp(Number(match[1]) / 100, 0.5, 3);
        };

        const normalizeZoom = () => {
            if (!(floorplanScroll instanceof HTMLElement)) {
                return;
            }
            const zoom = currentZoom();
            editableFloorplan.style.transform = 'none';
            editableFloorplan.style.width = `${zoom * 100}%`;
            editableFloorplan.style.minWidth = `${640 * zoom}px`;
            floorplanScroll.style.removeProperty('width');
            floorplanScroll.style.removeProperty('min-height');
        };

        document.querySelectorAll(
            '[data-floorplan-zoom-in], [data-floorplan-zoom-out], [data-floorplan-zoom-reset]'
        ).forEach((button) => {
            button.addEventListener('click', () => window.queueMicrotask(normalizeZoom));
        });
        normalizeZoom();

        const placementForm = (marker) => {
            const groupId = marker.dataset.groupId || '';
            const form = document.querySelector(`[data-placement-form="${groupId}"]`);

            return form instanceof HTMLFormElement ? form : null;
        };

        const updatePlacementField = (form, name, value) => {
            const input = form.querySelector(`[name="${name}"]`);
            if (input instanceof HTMLInputElement) {
                input.value = value.toFixed(3);
            }
        };

        editableFloorplan.querySelectorAll('.floorplan-marker[data-group-id]').forEach((marker) => {
            if (!(marker instanceof HTMLElement) || marker.querySelector('.floorplan-resize-handle') !== null) {
                return;
            }

            const handle = document.createElement('span');
            handle.className = 'floorplan-resize-handle';
            handle.setAttribute('aria-hidden', 'true');
            marker.append(handle);

            handle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
            });

            handle.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                event.stopPropagation();
                handle.setPointerCapture(event.pointerId);
                marker.dataset.dragged = '1';
                const rect = editableFloorplan.getBoundingClientRect();
                const x = parseFloat(marker.style.left) || 0;
                const y = parseFloat(marker.style.top) || 0;

                const move = (moveEvent) => {
                    const width = clamp(
                        ((moveEvent.clientX - rect.left) / rect.width) * 100 - x,
                        2,
                        100 - x
                    );
                    const height = clamp(
                        ((moveEvent.clientY - rect.top) / rect.height) * 100 - y,
                        2,
                        100 - y
                    );
                    marker.style.width = `${width}%`;
                    marker.style.height = `${height}%`;

                    const form = placementForm(marker);
                    if (form !== null) {
                        updatePlacementField(form, 'width_percent', width);
                        updatePlacementField(form, 'height_percent', height);
                        form.classList.add('floorplan-placement-dirty');
                    }
                };

                const finish = (finishEvent) => {
                    if (handle.hasPointerCapture(finishEvent.pointerId)) {
                        handle.releasePointerCapture(finishEvent.pointerId);
                    }
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', finish);
                    handle.removeEventListener('pointercancel', finish);
                    marker.dataset.dragged = '0';
                };

                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', finish);
                handle.addEventListener('pointercancel', finish);
            });
        });

        const addForm = document.querySelector('form.floorplan-add-placement');
        if (addForm instanceof HTMLFormElement) {
            const submitButton = addForm.querySelector('button[type="submit"]');
            const groupSelect = addForm.querySelector('select[name="cabinet_group_id"]');
            const help = document.createElement('p');
            help.className = 'floorplan-draw-help';
            help.textContent = 'Wähle eine noch nicht positionierte Schrankgruppe und ziehe ihre Fläche direkt im Lageplan auf. Neue Schrankgruppen mit Korpussen und Fächern werden unter Standorte angelegt.';

            const drawButton = document.createElement('button');
            drawButton.type = 'button';
            drawButton.className = 'button button-secondary';
            drawButton.textContent = 'Rechteck im Plan einzeichnen';

            const actions = document.createElement('div');
            actions.className = 'floorplan-add-actions';
            actions.append(drawButton);
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.textContent = 'Mit Standardgröße setzen';
                actions.append(submitButton);
            }
            addForm.append(actions, help);

            let drawing = false;
            const setDrawing = (active) => {
                drawing = active;
                editableFloorplan.classList.toggle('floorplan-drawing', active);
                drawButton.textContent = active ? 'Zeichnen abbrechen' : 'Rechteck im Plan einzeichnen';
                help.textContent = active
                    ? 'Zeichnen aktiv: Ziehe auf dem Lageplan das Rechteck für die ausgewählte Schrankgruppe auf.'
                    : 'Wähle eine noch nicht positionierte Schrankgruppe und ziehe ihre Fläche direkt im Lageplan auf. Neue Schrankgruppen mit Korpussen und Fächern werden unter Standorte angelegt.';
            };

            drawButton.addEventListener('click', () => setDrawing(!drawing));

            editableFloorplan.addEventListener('pointerdown', (event) => {
                if (!drawing || !(event.target instanceof Element)
                    || event.target.closest('[data-floorplan-marker]') !== null
                ) {
                    return;
                }
                if (!(groupSelect instanceof HTMLSelectElement) || groupSelect.value === '') {
                    help.textContent = 'Bitte zuerst eine Schrankgruppe auswählen.';
                    return;
                }

                event.preventDefault();
                editableFloorplan.setPointerCapture(event.pointerId);
                const rect = editableFloorplan.getBoundingClientRect();
                const startX = clamp(((event.clientX - rect.left) / rect.width) * 100, 0, 100);
                const startY = clamp(((event.clientY - rect.top) / rect.height) * 100, 0, 100);
                const draft = document.createElement('div');
                draft.className = 'floorplan-draft-rectangle';
                draft.style.left = `${startX}%`;
                draft.style.top = `${startY}%`;
                draft.style.width = '0';
                draft.style.height = '0';
                editableFloorplan.append(draft);

                let finalX = startX;
                let finalY = startY;
                let finalWidth = 0;
                let finalHeight = 0;

                const move = (moveEvent) => {
                    const endX = clamp(((moveEvent.clientX - rect.left) / rect.width) * 100, 0, 100);
                    const endY = clamp(((moveEvent.clientY - rect.top) / rect.height) * 100, 0, 100);
                    finalX = Math.min(startX, endX);
                    finalY = Math.min(startY, endY);
                    finalWidth = Math.abs(endX - startX);
                    finalHeight = Math.abs(endY - startY);
                    draft.style.left = `${finalX}%`;
                    draft.style.top = `${finalY}%`;
                    draft.style.width = `${finalWidth}%`;
                    draft.style.height = `${finalHeight}%`;
                };

                const cleanup = (pointerId) => {
                    if (editableFloorplan.hasPointerCapture(pointerId)) {
                        editableFloorplan.releasePointerCapture(pointerId);
                    }
                    editableFloorplan.removeEventListener('pointermove', move);
                    editableFloorplan.removeEventListener('pointerup', finish);
                    editableFloorplan.removeEventListener('pointercancel', cancel);
                    draft.remove();
                };

                const finish = (finishEvent) => {
                    cleanup(finishEvent.pointerId);
                    if (finalWidth < 2 || finalHeight < 2) {
                        help.textContent = 'Das Rechteck muss mindestens 2 % breit und 2 % hoch sein. Bitte erneut zeichnen.';
                        return;
                    }

                    updatePlacementField(addForm, 'x_percent', finalX);
                    updatePlacementField(addForm, 'y_percent', finalY);
                    updatePlacementField(addForm, 'width_percent', finalWidth);
                    updatePlacementField(addForm, 'height_percent', finalHeight);
                    setDrawing(false);
                    addForm.requestSubmit();
                };

                const cancel = (cancelEvent) => {
                    cleanup(cancelEvent.pointerId);
                    setDrawing(false);
                };

                editableFloorplan.addEventListener('pointermove', move);
                editableFloorplan.addEventListener('pointerup', finish);
                editableFloorplan.addEventListener('pointercancel', cancel);
            });
        }
    }

    const firstError = document.querySelector('.alert-error');
    if (firstError instanceof HTMLElement) {
        firstError.setAttribute('tabindex', '-1');
        firstError.focus({preventScroll: false});
    }
})();
