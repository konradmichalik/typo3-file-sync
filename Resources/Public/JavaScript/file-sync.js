// Read off the tag that loaded this module rather than hardcoded, because a
// site below a subdirectory answers at /subdir/tx-file-sync/materialize and
// a module cannot work that out from import.meta.url: it is served from
// _assets/ or from typo3conf/ext/ depending on the installation.
// document.currentScript is null inside a module, hence the query.
const ENDPOINT =
    document.querySelector('script[data-file-sync-endpoint]')?.dataset.fileSyncEndpoint ??
    '/tx-file-sync/materialize';
const BATCH_SIZE = 50;

const canAnimate = () =>
    typeof document.startViewTransition === 'function' &&
    window.matchMedia('(prefers-reduced-motion: no-preference)').matches;

const inViewport = (element) => {
    const box = element.getBoundingClientRect();
    return box.top < window.innerHeight && box.bottom > 0;
};

const collect = () => {
    const nodes = Array.from(document.querySelectorAll('img[data-file-sync]'));
    return [...nodes.filter(inViewport), ...nodes.filter((node) => !inViewport(node))].slice(0, BATCH_SIZE);
};

const request = async (tokens, stage) => {
    try {
        const response = await fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stage, tokens }),
        });
        return response.ok ? await response.json() : {};
    } catch {
        return {};
    }
};

const apply = async (elements, result) => {
    const swaps = [];
    for (const element of elements) {
        const url = result[element.dataset.fileSync]?.url;
        if (!url) continue;
        const next = new Image();
        next.src = url;
        try {
            await next.decode();
        } catch {
            continue;
        }
        swaps.push([element, url]);
    }
    if (swaps.length === 0) return;

    // decode() already ran above, so the assignment below never shows a
    // blank frame, whether or not a transition wraps it.
    const commit = () => {
        for (const [element, url] of swaps) {
            element.src = url;
            element.removeAttribute('data-file-sync');
        }
    };
    canAnimate() ? document.startViewTransition(commit) : commit();
};

const run = async () => {
    const elements = collect();
    if (elements.length === 0) return;
    await apply(elements, await request(elements.map((element) => element.dataset.fileSync), 'original'));
};

if (document.readyState === 'complete') {
    run();
} else {
    window.addEventListener('load', run, { once: true });
}
