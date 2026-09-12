(() => {
    'use strict';

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) {
        return;
    }

    const duration = 650;
    const formatter = new Intl.NumberFormat('de-DE');
    const counters = Array.from(document.querySelectorAll('[data-dashboard-counter]'));
    const progressBars = Array.from(document.querySelectorAll('[data-dashboard-progress]'));

    for (const counter of counters) {
        const target = Number.parseInt(counter.dataset.dashboardCounter ?? '0', 10);
        if (!Number.isFinite(target) || target < 0) {
            continue;
        }
        counter.textContent = '0';
        const start = performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            counter.textContent = formatter.format(Math.round(target * eased));
            if (progress < 1) {
                window.requestAnimationFrame(tick);
            }
        };
        window.requestAnimationFrame(tick);
    }

    for (const progressBar of progressBars) {
        const target = Number.parseInt(progressBar.dataset.dashboardProgress ?? '0', 10);
        if (!Number.isFinite(target) || target < 0) {
            continue;
        }
        progressBar.value = 0;
        window.setTimeout(() => {
            progressBar.value = target;
        }, 80);
    }
})();
