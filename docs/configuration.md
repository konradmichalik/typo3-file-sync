# Configuration

File Sync can be configured in two ways: via the **TYPO3 backend** (per storage) or via **PHP configuration** (e.g. in `ext_localconf.php` or `additional.php`).

## Backend

1. Go to the **List** module and edit a **File Storage** record
2. Enable **File Sync** and configure the resource handlers

## PHP

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'][1] = [
    [
        'identifier' => 'remote_instance',
        'configuration' => 'https://production.example.com',
    ],
    [
        'identifier' => 'placeholder_image',
        'configuration' => '#CCCCCC, #969696',
    ],
];
```

The array key (`1`) is the UID of the file storage. Handlers are tried in the order listed, so chaining `remote_instance` before `placeholder_image` fetches real assets when available and falls back to a placeholder when they are not.

See [Resource handlers](resource-handlers.md) for the available handler identifiers and their configuration options.

## Deferred loading via PHP

The **Defer remote fetching in the frontend (experimental)** checkbox (see [Deferred image loading](deferred-image-loading.md)) lives on the storage record, so a database sync from production, which does not carry that checkbox, resets it back to off. A storage configured entirely through PHP needs the same override for that flag, otherwise every sync would silently turn deferred loading back off:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['deferredStorages'][] = 1;
```

A storage UID listed here is deferred regardless of its own checkbox. It still requires `fileSync.deferredLoading` to be enabled and a non-deferrable fallback handler configured, see [Deferred image loading](deferred-image-loading.md).
