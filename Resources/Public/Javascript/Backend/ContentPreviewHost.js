/**
 * Runs in the backend page. Two jobs:
 *
 * 1. Receives the content height of the sandboxed preview iframes (see iFramePreview.js) and
 *    resizes them. The iframes have an opaque origin, so they cannot touch this document.
 *
 * 2. Loads the previews that are rendered in a separate request. Such an iframe carries a signed
 *    descriptor (data-look-preview) instead of a src. When it comes near the viewport, the
 *    descriptor is exchanged for a short-lived token at the backend's token route (authenticated,
 *    this document has the session) and the returned URL becomes the frame's src. The token is
 *    valid for a few seconds only; should the frame report it as expired (throttled tab, slow
 *    network), one fresh token is fetched.
 */
const HEIGHT_MESSAGE = 'flowd-look-content-preview-height';
const EXPIRED_MESSAGE = 'flowd-look-content-preview-expired';
const SELECTOR = 'iframe.look-content-preview';

const retried = new WeakSet();

const findFrame = (source) => {
    for (const iframe of document.querySelectorAll(SELECTOR)) {
        if (iframe.contentWindow === source) {
            return iframe;
        }
    }
    return null;
};

const load = async (iframe) => {
    const descriptor = iframe.dataset.lookPreview;
    const tokenUrl = iframe.dataset.lookPreviewTokenUrl;
    if (!descriptor || !tokenUrl) {
        return;
    }
    try {
        const body = new URLSearchParams({ descriptor });
        const response = await fetch(tokenUrl, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
            throw new Error('Token request failed with status ' + response.status);
        }
        const data = await response.json();
        if (typeof data.url !== 'string') {
            throw new Error('Token response carries no URL');
        }
        // only the preview route of this backend, never somewhere else; the route appears in the path with
        // URL rewriting ("/typo3/look/preview") and as "route" parameter without ("/typo3/index.php?route=/look/preview")
        const url = new URL(data.url, window.location.href);
        const isPreviewRoute = url.pathname.endsWith('/look/preview')
            || (url.pathname.endsWith('/index.php') && url.searchParams.get('route') === '/look/preview');
        if (url.origin !== window.location.origin || !isPreviewRoute) {
            throw new Error('Token response points outside the preview route');
        }
        if (iframe.dataset.lookPreviewLoaded && iframe.contentWindow) {
            // a reload after an expired token: replace instead of navigating, so no history entry is added
            try {
                iframe.contentWindow.location.replace(url.href);
            } catch {
                iframe.src = url.href;
            }
        } else {
            iframe.src = url.href;
        }
        iframe.dataset.lookPreviewLoaded = '1';
    } catch (error) {
        console.error('Look: preview could not be loaded', error);
        iframe.classList.add('look-content-preview--failed');
    }
};

const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
        if (entry.isIntersecting) {
            observer.unobserve(entry.target);
            load(entry.target);
        }
    }
}, { rootMargin: '300px 0px' });

const observe = (root) => {
    for (const iframe of root.querySelectorAll(SELECTOR + '[data-look-preview]')) {
        if (!iframe.dataset.lookPreviewObserved) {
            iframe.dataset.lookPreviewObserved = '1';
            observer.observe(iframe);
        }
    }
};

window.addEventListener('message', (event) => {
    const data = event.data;
    if (!data || typeof data.type !== 'string') {
        return;
    }
    if (data.type === HEIGHT_MESSAGE) {
        if (typeof data.height !== 'number' || !Number.isFinite(data.height)) {
            return;
        }
        const iframe = findFrame(event.source);
        if (iframe) {
            iframe.style.height = Math.max(0, data.height) + 'px';
        }
        return;
    }
    if (data.type === EXPIRED_MESSAGE) {
        const iframe = findFrame(event.source);
        if (iframe && !retried.has(iframe)) {
            retried.add(iframe);
            load(iframe);
        }
    }
});

observe(document);
// previews added later, e.g. after drag and drop or inline editing in the page module
new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (!(node instanceof Element)) {
                continue;
            }
            if (node.matches(SELECTOR)) {
                observe(node.parentElement ?? document);
            } else if (node.firstElementChild) {
                observe(node);
            }
        }
    }
}).observe(document.body, { childList: true, subtree: true });
