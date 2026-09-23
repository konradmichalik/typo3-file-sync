// Read off the tag that loaded this module rather than hardcoded, because a
// site below a subdirectory answers at /subdir/tx-file-sync/materialize and
// a module cannot work that out from import.meta.url: it is served from
// _assets/ or from typo3conf/ext/ depending on the installation.
// document.currentScript is null inside a module, hence the query.
const ENDPOINT =
    document.querySelector('script[data-file-sync-endpoint]')?.dataset.fileSyncEndpoint ??
    '/tx-file-sync/materialize';
// Must never exceed MaterializationService::MAX_TOKENS. The server answers a
// larger batch with 400, which leaves every image in the batch a placeholder
// for the rest of the page view and a warning in the console.
const BATCH_SIZE = 50;

const prefersMotion = () => window.matchMedia('(prefers-reduced-motion: no-preference)').matches;

const canAnimate = () => typeof document.startViewTransition === 'function' && prefersMotion();

const inViewport = (element) => {
    const box = element.getBoundingClientRect();
    return box.top < window.innerHeight && box.bottom > 0;
};

const collect = () => {
    const nodes = Array.from(document.querySelectorAll('img[data-file-sync]'));
    return [...nodes.filter(inViewport), ...nodes.filter((node) => !inViewport(node))];
};

// Consecutive batches, viewport-first order preserved across the cut, so a
// page with more than BATCH_SIZE deferred images still materializes all of
// them instead of leaving everything past the first batch a placeholder for
// the rest of the page view. One element costs one token until an element
// can carry more than one, which is what tokensOf() will name once srcset
// lands.
const batches = (elements) => {
    const result = [];
    for (let index = 0; index < elements.length; index += BATCH_SIZE) {
        result.push(elements.slice(index, index + BATCH_SIZE));
    }
    return result;
};

// A pulse on the image itself, not a spinner in a wrapper: wrapping an <img>
// this module does not own is the one layout change this branch has refused
// throughout. Keyed by element rather than by attribute, so swapping in a
// preview partway through (src changes, data-file-sync stays) does not
// restart the animation from its first frame.
const shimmers = new WeakMap();

const startShimmer = (element) => {
    if (typeof element.animate !== 'function' || shimmers.has(element)) return;
    shimmers.set(
        element,
        element.animate([{ filter: 'brightness(1)' }, { filter: 'brightness(0.85)' }, { filter: 'brightness(1)' }], {
            duration: 1600,
            iterations: Infinity,
            easing: 'ease-in-out',
        }),
    );
};

const stopShimmer = (element) => {
    shimmers.get(element)?.cancel();
    shimmers.delete(element);
};

const request = async (tokens, stage) => {
    try {
        const response = await fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stage, tokens }),
        });
        if (!response.ok) {
            // Every failure of this endpoint shows as an image that stays
            // grey, or with previews on, one that stays blurred, and a
            // blurred page looks like a slow success rather than a fault.
            // Without this line a bug report about it carries nothing.
            console.warn(`File Sync: the ${stage} stage answered ${response.status}.`);
            return {};
        }

        return await response.json();
    } catch {
        return {};
    }
};

// One function for both stages: key is 'preview' for the data URI and 'url'
// for the real file, and final says whether what lands is the last thing this
// element will be given.
const apply = async (elements, result, key, final) => {
    // Every src is assigned before anything is awaited, so the browser starts
    // all the downloads at once. Awaiting decode() inside the loop instead
    // would delay the single commit by the sum of the load times, which for a
    // batch of fifty is most of what this feature exists to avoid.
    const pending = elements
        .map((element) => [element, result[element.dataset.fileSync]?.[key]])
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

    // The last stage's own failures are the only ones this module can see: no
    // url came back, or the browser refused to decode the one it got. An
    // element the loop below will not touch keeps its shimmer running forever
    // otherwise, which claims progress on a page that has none left to make.
    // data-file-sync-failed is left for a site to style; nothing in this file
    // reads it back.
    if (final) {
        const swapped = new Set(swaps.map(([element]) => element));
        for (const element of elements) {
            if (element.hasAttribute('data-file-sync') && !swapped.has(element)) {
                element.setAttribute('data-file-sync-failed', '1');
                stopShimmer(element);
            }
        }
    }

    if (swaps.length === 0) return;

    // decode() already ran above, so the assignment below never shows a
    // blank frame, whether or not a transition wraps it.
    //
    // The staleness guard sits inside the commit rather than anywhere before
    // it, because the race between the two stages is decided at the moment of
    // assignment and not a frame earlier. An element that has lost
    // data-file-sync already shows its original, and a preview arriving after
    // it must not blur a sharp image.
    const commit = () => {
        for (const [element, url] of swaps) {
            if (!element.hasAttribute('data-file-sync')) continue;
            element.src = url;
            element.removeAttribute('data-file-sync-preview');
            if (final) {
                element.removeAttribute('data-file-sync');
                stopShimmer(element);
            }
        }
    };

    // Starting a transition skips whichever one is still running, so a late
    // preview whose swaps the guard will all skip would cut the original's
    // crossfade short in order to animate nothing. The guard in the commit
    // stays the one that decides; this only keeps a commit that can no longer
    // change anything from reaching for a transition.
    const stale = swaps.every(([element]) => !element.hasAttribute('data-file-sync'));
    canAnimate() && !stale ? document.startViewTransition(commit) : commit();
};

const runBatch = async (elements) => {
    // Only the images the middleware marked for this stage, which are the
    // ones that state their own size and have no preview stored yet. A
    // settled installation therefore asks for nothing here and costs the one
    // original request it always did. Where it does not settle is a rendition
    // whose original offers no usable source rendition at all: nothing is
    // ever stored for it, so it is marked again on every response and this
    // POST goes out on every page view.
    const unpreviewed = elements.filter((element) => element.hasAttribute('data-file-sync-preview'));

    // Both requests leave before either is awaited, so the two POSTs travel
    // together rather than one after the other. The preview stage wins the
    // race essentially always, moving kilobytes where the original moves
    // megabytes, but nothing here relies on that: the staleness guard in
    // apply() settles whichever order the answers come back in.
    const previews =
        unpreviewed.length > 0
            ? request(unpreviewed.map((element) => element.dataset.fileSync), 'preview').then((result) =>
                  apply(unpreviewed, result, 'preview', false),
              )
            : Promise.resolve();
    const originals = request(elements.map((element) => element.dataset.fileSync), 'original').then((result) =>
        apply(elements, result, 'url', true),
    );

    await Promise.allSettled([previews, originals]);
};

const run = async () => {
    const elements = collect();
    if (elements.length === 0) return;

    // Started here rather than inside each request handler, because every
    // element this module will ever touch is already known at this point,
    // and motion is the only thing being decided: whether a preview or an
    // original lands first changes nothing about which images are pending.
    if (prefersMotion()) elements.forEach(startShimmer);

    // One batch after another, never in parallel: batches() already keeps
    // each one within MAX_TOKENS on its own, but firing all of a large
    // gallery's batches at once would still turn the per-address rate limit
    // into a wall the later batches hit.
    for (const batch of batches(elements)) {
        await runBatch(batch);
    }
};

if (document.readyState === 'complete') {
    run();
} else {
    window.addEventListener('load', run, { once: true });
}
