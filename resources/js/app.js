/**
 * The public site's client-side behaviour.
 *
 * The map is a Google Maps <iframe> rendered by the server — no client-side
 * map library and no third-party script. Everything else (the mobile menu,
 * the booking form, the feedback form) is a few lines of inline script in
 * the view that owns it.
 */

/**
 * A missing image must never leave a broken box.
 *
 * An <img> that carries `data-fallback` is replaced when it fails to load:
 *
 *   data-fallback="initials"  data-fallback-text="A"  -> the person's initial
 *   data-fallback="placeholder"                        -> a neutral block
 *   data-fallback="hide"                              -> the element is removed
 *
 * The neutral block keeps the SIZE of the image it replaces (the width and
 * height attributes and the classes), so the layout does not jump when the
 * picture is missing.
 */
function initImageFallbacks() {
    document.querySelectorAll('img[data-fallback]').forEach((image) => {
        image.addEventListener(
            'error',
            () => {
                const mode = image.dataset.fallback;

                if (mode === 'hide') {
                    image.remove();

                    return;
                }

                const replacement = document.createElement(mode === 'initials' ? 'span' : 'div');

                if (mode === 'initials') {
                    replacement.className = 'flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700';
                    replacement.setAttribute('aria-hidden', 'true');
                } else {
                    // The same box as the image: the classes and the
                    // intrinsic ratio are kept, only the picture is missing.
                    replacement.className = `flex items-center justify-center bg-slate-100 text-sm font-medium text-slate-500 ${image.getAttribute('class') || ''}`;

                    const width = image.getAttribute('width');
                    const height = image.getAttribute('height');

                    if (width && height) {
                        replacement.style.aspectRatio = `${width} / ${height}`;
                    }

                    replacement.setAttribute('role', 'img');

                    if (image.alt) {
                        replacement.setAttribute('aria-label', image.alt);
                    }
                }

                replacement.textContent = image.dataset.fallbackText || '';
                image.replaceWith(replacement);
            },
            { once: true }
        );
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initImageFallbacks);
} else {
    initImageFallbacks();
}

/**
 * Cards rise into place when they scroll into view.
 *
 * The hidden state lives in the CSS (`.js .reveal`), so it only applies
 * while this script runs: a script that fails or is blocked leaves the
 * page fully visible instead of blank.
 *
 * A card is RISEN while it is on the screen and RESETS when it leaves it,
 * in either direction — so the rise plays again on every pass, instead of
 * only the first time the card is reached.
 */
function initReveal() {
    const items = Array.from(document.querySelectorAll('.reveal'));

    if (items.length === 0) {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        items.forEach((item) => item.classList.add('reveal-visible'));

        return;
    }

    // A grid of cards rises one after the other.
    const counters = new Map();

    items.forEach((item) => {
        const parent = item.parentElement;
        const index = counters.get(parent) || 0;

        counters.set(parent, index + 1);
        item.style.setProperty('--reveal-delay', `${Math.min(index, 6) * 70}ms`);
    });

    let queued = false;

    const check = () => {
        const height = window.innerHeight;

        items.forEach((item) => {
            const rect = item.getBoundingClientRect();
            const onScreen = rect.top < height && rect.bottom > 0;

            item.classList.toggle('reveal-visible', onScreen);
        });
    };

    function onScroll() {
        if (queued) {
            return;
        }

        queued = true;

        window.requestAnimationFrame(() => {
            queued = false;
            check();
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);

    check();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReveal);
} else {
    initReveal();
}
