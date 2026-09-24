# Deferred image loading

Enabled per storage, deferred loading renders a placeholder immediately and swaps in the real file once the page has loaded, skipping every network-bound resource handler on the initial render.

## Enabling it

Enable it by hand in `config/system/additional.php` (or `settings.php`), since the Install Tool only surfaces core feature toggles:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['fileSync.deferredLoading'] = true;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['fileSync.previewImages'] = true; // optional, requires deferredLoading
```

A per-storage checkbox, **Defer remote fetching in the frontend (experimental)** (`tx_typo3_file_sync_deferred`), then needs to be set on the **File Storage** record; it only appears in TCA once the toggle above is on. Both the toggle and the checkbox are required.

A storage with deferred loading enabled needs a non-deferrable fallback handler, such as the placeholder image generator, configured alongside the remote one. Without it the render has nothing left to answer with and produces no file at all rather than a placeholder.

> [!WARNING]
> This feature is experimental. The JSON contract of the materialize endpoint and the `data-file-sync` attribute name may change without a major release.

## How it works

A placeholder for an image on a storage with deferred loading enabled renders immediately, skipping every network-bound resource handler. The browser fetches the real file once the page has loaded, and a small script swaps it in with a crossfade.

![Placeholder, blurred preview and real image swapping in with a crossfade](img/deferred-image-loading.gif)

With preview images enabled as well, the same image passes three stages:

1. the grey placeholder, rendered with the page
2. a blurred preview of the rendition, a couple of hundred bytes
3. the original, fetched from the remote instance

The swap is injected as an external `<script type="module">` tag that carries no nonce, so it only runs where `script-src` is unset and `default-src` covers it. TYPO3's own default frontend content security policy sets `script-src` to a nonce proxy, so on any site with that policy enabled the injected tag is blocked and this feature does nothing there. That policy only applies once the core feature toggle `security.frontend.enforceContentSecurityPolicy` is switched on, and it ships off, so the block is the exception rather than the default.

While an image is pending, the module pulses its brightness gently, so a visitor can tell something is still on its way without any layout changing. Motion is gated the same way the crossfade is: only under `prefers-reduced-motion: no-preference`. If the last stage does not deliver a file, the pulse stops and the tag gains `data-file-sync-failed`, a hook a site can style; nothing in this extension reads it back.

## Preview images

The middle stage is a second toggle, set in the same file:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['fileSync.previewImages'] = true;
```

It requires `fileSync.deferredLoading` and does nothing without it: no image is marked, so nothing ever asks for a preview. Set on its own it is simply inert, and nothing warns about it.

A preview is a WebP of 32 pixels on its longest edge, blurred twice. It weighs a couple of hundred bytes, so roughly 300 characters once it is base64-encoded into the HTML. A GD build without WebP support produces no previews at all and leaves the grey placeholder in place. The stage answers before it fetches, so nothing is downloaded from the remote instance either.

The source it is built from is another rendition of the same original recorded in `sys_file_processedfile`, preferring the backend thumbnail and otherwise taking the narrowest one there is, fetched from the remote instance and blurred locally with GD. That is where the traffic goes, not into the previews themselves. Where a backend thumbnail exists it is a few kilobytes against an original in the megabytes. Where none does, the narrowest recorded rendition can be a full-size one, and the preview then costs as much to fetch as that rendition does, to produce the same couple of hundred bytes of blur. Where the narrowest one is the rendition the browser is already waiting for, no preview is built at all, because blurring it would download the very file the third stage is about to deliver.

The crop follows the aspect ratio of the rendition the browser is waiting for, so a square slot is not filled with a stretched landscape blur. That means one preview per rendition, not per picture: three renditions of one picture are three previews, each fetching its source once over the lifetime of the store. Within a single page view that download is shared, so all renditions of one picture fetch their source once per batch.

A responsive `srcset` is the one exception: since its candidates typically share one crop, the whole tag shares a single preview instead, built from `src`'s own rendition where `src` is itself pending, otherwise from the first pending candidate in `srcset`. A `<picture>` gets no preview at all; see Known Limitations below.

Previews live in `var/file-sync/previews/`, outside the database and outside the file storage. A database sync from production does not touch them, and neither does `file-sync:delete` or `file-sync:reset`. A sync does replace `sys_file_processedfile`, though, and previews are keyed by the processed identifier, so the previews belonging to the renditions it replaced become orphans that nothing prunes. Deleting the directory costs nothing but a repeat of the preview stage, since every preview is rebuilt the next time a page holding that image is visited.

Nothing has to be installed or configured on the remote instance. The renditions are fetched through the same `remote_instance` handler as the originals, so all it takes is that the remote `_processed_` folder is publicly served.

Where a preview is already stored and the tag states its own size, it is inlined as a `data:` URI instead of being requested at all: into `src` for a plain `<img>`, or as the sole candidate of `srcset` where one is present, since `srcset`, once there, is all the browser ever reads. TYPO3's default frontend content security policy permits `data:` in `img-src`, so that works under it; only a hand-written policy dropping `data:` would block it. This is a different question from the `script-src` one above, which is about the injected module not running in the first place.

An image that stays blurred is a failure rather than a slow success: the preview arrived and the original never did. The module writes a warning to the browser console when the materialize endpoint answers with an error status, so that case is visible. The commoner one is not: a `200` carrying `unavailable` for a single image is indistinguishable from a working response and is logged nowhere, so a report about one blurred image among many has to start with `var/log/typo3_file_sync.log`. Either way the tag stops shimmering and gains `data-file-sync-failed` once the last stage has run, so a visitor sees a static image rather than one that keeps animating toward a result that already failed.

## Known limitations

- A `srcset` on an `<img>`, and every `<source>` inside a `<picture>`, swaps candidate by candidate, each with its own token, independently of `src` and of each other. A `srcset` the strict candidate parser cannot confidently read (an unescaped comma inside a filename, a `data:` URI among the candidates) or one naming more candidates than one materialize batch could carry takes the whole tag down with it, left exactly as it was rather than half-marked for a swap the browser would never read. A `<picture>`'s own `<img>` and every `<source>` inside it take no part in the preview stage: the browser renders whichever source matches or, failing all of them, the img, never more than one, so a preview built for a crop the visitor might never see would be wasted.
- At most 50 images are materialized per page view, in document order with the visible ones first. There is no second pass on scroll, so anything past that limit stays a placeholder until the page is reloaded.
- A storage with deferred loading enabled needs a non-deferrable fallback handler, such as the placeholder image generator, configured alongside the remote one. Without it the render has nothing left to answer with and produces no file at all rather than a placeholder.
- A provisional `<img>`'s `src` carries a query suffix so a browser that already cached the placeholder does not go on serving it once the real file has replaced it on disk under the same path, which is what TYPO3's own default `.htaccess` caches for a month. A CDN or reverse proxy that strips or normalizes query strings on static assets defeats this, so materialized images stay stuck at whatever it last cached until that layer's own cache expires.
- The materialize endpoint is public and unauthenticated, so it is rate limited to 60 requests per minute per client address and 600 per minute for the site as a whole, answering `429` beyond either. A page view costs at most two requests, one per stage. The per-address figure assumes the address is trustworthy: with `$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue']` set to `first` and `reverseProxyIP` matching your proxy, TYPO3 reads the client address from the first `X-Forwarded-For` entry, which the client writes. The site-wide limit is what still holds there.
- An image whose markup states no `width` and `height` gets no preview and keeps the grey placeholder until the original arrives. A preview is 32 pixels on its longest edge, so a tag laid out from whatever its `src` turns out to be would collapse and grow back again: two layout shifts where the placeholder alone costs none. TYPO3's own image rendering (`f:image`, `f:media`, the `IMAGE` cObj) always writes both attributes, so this concerns hand-written markup only.
- A rendition whose `sys_file_processedfile` row records no dimensions, and whose original has no sibling rendition that does, has nothing to build a preview from and keeps the grey placeholder. A row whose `identifier` is still empty behaves the same way, and so does a picture whose only recorded rendition is the one being waited for. Nothing is ever stored in either case, so the image is marked again on every response and costs one preview request per page view for as long as that stays true. This is a steady state, not a failure.

## After a database sync

After a database sync from production, `tx_typo3_file_sync_identifier` is empty again while the provisional files still sit on disk. They then count as real and are never replaced. This is the existing behaviour for placeholders, and the remedy belongs in the sync routine:

```bash
vendor/bin/typo3 file-sync:delete --identifier=placeholder_image
vendor/bin/typo3 file-sync:reset
```

A sync also replaces `sys_file_processedfile`, so previews stored under the replaced renditions' identifiers are orphaned. Nothing prunes them, and nothing reads them either. Deleting `var/file-sync/previews/` belongs in the same routine if the directory matters to you.
