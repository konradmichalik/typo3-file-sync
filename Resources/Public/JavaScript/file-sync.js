// Read off the tag that loaded this module rather than hardcoded, because a
// site below a subdirectory answers at /subdir/tx-file-sync/materialize and
// a module cannot work that out from import.meta.url: it is served from
// _assets/ or from typo3conf/ext/ depending on the installation.
// document.currentScript is null inside a module, hence the query.
const ENDPOINT =
    document.querySelector('script[data-file-sync-endpoint]')?.dataset.fileSyncEndpoint ??
    '/tx-file-sync/materialize';
// Must never exceed MaterializationService::MAX_TOKENS. The server answers a
// larger batch with 400, request() swallows that into {}, and every image in
// the batch silently stays a placeholder for the rest of the page view.
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
    // Every src is assigned before anything is awaited, so the browser starts
    // all the downloads at once. Awaiting decode() inside the loop instead
    // would delay the single commit by the sum of the load times, which for a
    // batch of fifty is most of what this feature exists to avoid.
    const pending = elements
        .map((element) => [element, result[element.dataset.fileSync]?.url])
        .filter(([, url]) => Boolean(url))
        .map(([element, url]) => {
            const next = new Image();
            next.src = url;
            return next.decode().then(
                () => [element, url],
                () => null,
            );
        });

    // Each decode already resolves to null on failure, so Promise.all cannot
    // reject here: one image the browser refuses must not take the rest of
    // the batch down with it.
    const swaps = (await Promise.all(pending)).filter(Boolean);
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
