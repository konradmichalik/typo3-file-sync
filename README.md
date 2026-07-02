<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_file_sync`

[![Supported TYPO3 versions](https://typo3-badges.dev/badge/typo3_file_sync/typo3/shields.svg)](https://extensions.typo3.org/extension/typo3_file_sync)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-file-sync/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-file-sync/actions/workflows/cgl.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-file-sync/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-file-sync/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/konradmichalik/typo3-file-sync/license)](LICENSE)

</div>

A lightweight TYPO3 extension that synchronizes missing files on demand — either by fetching them from a remote instance or by generating local placeholder images. Inspired by [filefill](https://github.com/IchHabRecht/filefill), this is a leaner reimplementation with TYPO3 v13 + v14 support and self-contained placeholder generation without external service dependencies.

> [!NOTE]
> Multiple resource handlers can be chained per storage. They are processed in order until one successfully delivers the file.

## 🔥 Installation

### Requirements

* TYPO3 >= 13.4
* PHP 8.2+
* PHP extension `ext-gd` (for placeholder image generation)

### Composer

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/typo3-file-sync?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/typo3-file-sync)

```bash
composer require konradmichalik/typo3-file-sync
```

### Setup

```bash
vendor/bin/typo3 extension:setup --extension=typo3_file_sync
```

## ⚙️ Configuration

File Sync can be configured in two ways: via the **TYPO3 backend** (per storage) or via **PHP configuration** (e.g. in `ext_localconf.php` or `additional.php`).

### Backend

1. Go to the **List** module and edit a **File Storage** record
2. Enable **File Sync** and configure the resource handlers

### PHP

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

The array key (`1`) is the UID of the file storage.

## ✨ Resource Handlers

### Remote Instance

Fetches missing files from a remote TYPO3 instance via HTTP(S). A `HEAD` request checks existence before downloading; the file path is appended to the configured base URL.

```php
'identifier' => 'remote_instance',
'configuration' => 'https://production.example.com',
```

#### Basic Auth

If the remote instance is protected by `.htaccess` or similar, credentials can be included in the URL:

```
https://user:password@production.example.com
```

For environment variable support (works in both backend and PHP configuration), use `%env()%` placeholders:

```
https://%env(REMOTE_USER)%:%env(REMOTE_PASS)%@production.example.com
```

### Placeholder Image

Generates local placeholder images with configurable colors. Supports GD-based formats (`jpg`, `png`, `gif`, `webp`, `avif`) and `svg`.

```php
'identifier' => 'placeholder_image',
'configuration' => '#CCCCCC, #969696', // backgroundColor, textColor
```

The generated image displays the original file dimensions as a text overlay (e.g. `1920 x 1080`).

> [!TIP]
> Chain both handlers to get real assets from production when available, falling back to a placeholder when they are not.

## 💡 CLI Commands

### Reset missing-file flags

Resets the `missing` flag on `sys_file` records for all enabled storages or a specific one:

```bash
vendor/bin/typo3 file-sync:reset
vendor/bin/typo3 file-sync:reset --storage=1
```

### Delete synced files

Removes files previously fetched by File Sync, optionally filtered by handler or storage:

```bash
vendor/bin/typo3 file-sync:delete --all
vendor/bin/typo3 file-sync:delete --identifier=remote_instance
vendor/bin/typo3 file-sync:delete --identifier=remote_instance --storage=1
```

> [!WARNING]
> `file-sync:delete --all` permanently removes all files that were fetched by any handler. Run `file-sync:reset` afterwards to allow them to be re-synced on next access.

## 🧩 Custom Resource Handlers

Register a custom handler in your `ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler']['my_handler'] = [
    'title' => 'LLL:EXT:my_extension/Resources/Private/Language/locallang.xlf:my_handler',
    'config' => [
        'label' => 'LLL:EXT:my_extension/Resources/Private/Language/locallang.xlf:my_handler.config',
        'config' => [
            'type' => 'input',
        ],
    ],
    'handler' => \Vendor\MyExtension\Resource\Handler\MyHandler::class,
];
```

The handler class must implement `RemoteResourceInterface`:

```php
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use TYPO3\CMS\Core\Resource\FileInterface;

class MyHandler implements RemoteResourceInterface
{
    public function __construct(array|string|null $configuration) {}

    public function hasFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): bool
    {
        // Return true when this handler can provide the file
    }

    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): string|false
    {
        // Return file content as string, or false if unavailable
    }
}
```

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## 📜 License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE).
