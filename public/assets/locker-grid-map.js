(() => {
    'use strict';

    const enhance = (container) => {
        const list = container.querySelector('.entity-list:not([data-locker-map-grid])');
        if (!(list instanceof HTMLElement)) {
            return;
        }

        const entries = [];
        Array.from(list.children).forEach((row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const strong = row.querySelector('strong');
            const meta = row.querySelector('.muted');
            const shortName = strong instanceof HTMLElement ? (strong.textContent || '').trim() : '';
            const metaText = meta instanceof HTMLElement ? (meta.textContent || '') : '';
            const shortMatch = shortName.match(/^(.+)-([0-9]+)-([0-9]+)$/);
            const metaMatch = metaText.match(/Korpus\s+([0-9]+).*Fachposition\s+([0-9]+)/i);
            if (shortMatch === null && metaMatch === null) {
                return;
            }
            entries.push({
                row,
                corpus: metaMatch !== null ? Number(metaMatch[1]) : Number(shortMatch[2]),
                position: metaMatch !== null ? Number(metaMatch[2]) : Number(shortMatch[3]),
            });
        });
        if (entries.length < 2) {
            return;
        }

        const corpuses = Array.from(new Set(entries.map((entry) => entry.corpus))).sort((a, b) => a - b);
        const maxPosition = Math.max(...entries.map((entry) => entry.position));
        const lookup = new Map(entries.map((entry) => [`${entry.corpus}:${entry.position}`, entry.row]));

        const scroll = document.createElement('div');
        scroll.className = 'locker-grid-scroll';
        const table = document.createElement('table');
        table.className = 'locker-grid-table locker-grid-map-table';
        const thead = document.createElement('thead');
        const header = document.createElement('tr');
        const corner = document.createElement('th');
        corner.textContent = 'Fach';
        header.append(corner);
        corpuses.forEach((corpus) => {
            const th = document.createElement('th');
            th.textContent = `Korpus ${String(corpus).padStart(2, '0')}`;
            header.append(th);
        });
        thead.append(header);
        table.append(thead);

        const tbody = document.createElement('tbody');
        for (let position = 1; position <= maxPosition; position += 1) {
            const tr = document.createElement('tr');
            const th = document.createElement('th');
            th.textContent = `Position ${position}`;
            tr.append(th);
            corpuses.forEach((corpus) => {
                const td = document.createElement('td');
                td.className = 'locker-grid-map-cell';
                const row = lookup.get(`${corpus}:${position}`);
                if (row instanceof HTMLElement) {
                    td.append(row);
                } else {
                    const empty = document.createElement('span');
                    empty.className = 'locker-grid-empty';
                    empty.textContent = '–';
                    td.append(empty);
                }
                tr.append(td);
            });
            tbody.append(tr);
        }
        table.append(tbody);
        scroll.append(table);
        list.dataset.lockerMapGrid = '1';
        list.replaceWith(scroll);
    };

    const container = document.querySelector('[data-booking-map-dialog-content]');
    if (!(container instanceof HTMLElement)) {
        return;
    }
    enhance(container);
    new MutationObserver(() => enhance(container)).observe(container, {childList: true, subtree: true});
})();
