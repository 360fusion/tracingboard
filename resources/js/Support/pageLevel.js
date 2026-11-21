import { router } from '@inertiajs/vue3';

// While a page loads, a small level at the top of the screen rocks its bubble (app.css .tb-page-level). It only shows when
// the load takes longer than a quarter of a second, and never for visits that ask for no progress (polling, quiet reloads).
export function startPageLevel() {
    const level = document.createElement('div');
    level.className = 'tb-page-level';
    level.setAttribute('role', 'progressbar');
    level.setAttribute('aria-label', 'Loading');
    level.innerHTML = '<span><span></span></span>';
    level.hidden = true;
    document.body.appendChild(level);

    let timer = null;

    router.on('start', (event) => {
        if (event.detail.visit.showProgress === false) {
            return;
        }
        clearTimeout(timer);
        timer = setTimeout(() => { level.hidden = false; }, 250);
    });

    router.on('finish', () => {
        clearTimeout(timer);
        level.hidden = true;
    });
}
