<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_file_sync`

[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/typo3-file-sync?color=brightgreen)](https://packagist.org/packages/konradmichalik/typo3-file-sync)
![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.0-orange.svg)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-file-sync/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-file-sync)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-file-sync/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-file-sync/actions/workflows/cgl.yml)
[![Coverage](https://coveralls.io/repos/github/konradmichalik/typo3-file-sync/badge.svg?branch=main)](https://coveralls.io/github/konradmichalik/typo3-file-sync)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-file-sync/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-file-sync/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

A lightweight TYPO3 extension that synchronizes missing files on demand — either by fetching them from a remote instance or by generating local placeholder images.

> [!TIP]
> Typical use case: staging systems or local development environments that get refreshed from production on a regular basis (e.g. via a database sync) without copying the full file storage. Missing files are fetched — or replaced with a placeholder — the moment they're actually requested, instead of shipping every asset on each refresh.

## 🔥 Installation

### Requirements

* TYPO3 13.4 LTS or 14.0+
* PHP 8.2 – 8.5
* PHP extension `ext-gd` (for placeholder image generation)

### Composer

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/typo3-file-sync?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/typo3-file-sync)
[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/typo3-file-sync?color=brightgreen)](https://packagist.org/packages/konradmichalik/typo3-file-sync)

```bash
composer require konradmichalik/typo3-file-sync
```

### TER

[![TER version](https://typo3-badges.dev/badge/typo3_file_sync/version/shields.svg)](https://extensions.typo3.org/extension/typo3_file_sync)
[![TER downloads](https://typo3-badges.dev/badge/typo3_file_sync/downloads/shields.svg)](https://extensions.typo3.org/extension/typo3_file_sync)

Download the zip file from [TYPO3 extension repository (TER)](https://extensions.typo3.org/extension/typo3_file_sync).

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

Fetches missing files from a remote TYPO3 instance via HTTP(S). The file path is appended to the configured base URL and requested with a `GET`; any non-`200` response is treated as "not available" so the next handler in the chain can take over.

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

> [!WARNING]
> `%env()%` placeholders resolve **any** environment variable of the process. Since File Sync is configured on `sys_file_storage` records, anyone able to edit a file storage can read arbitrary environment values (e.g. database credentials) by sending them to a remote host. Editing file storages is an admin-level task — keep it restricted to trusted backend administrators.

#### Timeouts

Requests use a connect timeout of `5` seconds and a request timeout of `15` seconds by default, so a slow or unreachable remote instance cannot block page rendering indefinitely. Both can be adjusted via PHP configuration:

```php
'identifier' => 'remote_instance',
'configuration' => [
    'url' => 'https://production.example.com',
    'connect_timeout' => 5,
    'timeout' => 15,
],
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

    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed
    {
        // Return the file content as a string or stream resource,
        // or false if this handler cannot provide the file
    }
}
```

## 🙏 Acknowledgments

This project is inspired by the great [filefill](https://github.com/IchHabRecht/filefill) extension. File Sync targets TYPO3 13.4 LTS and 14.0+, generates placeholder images (GD and SVG) fully locally without relying on an external service like Placehold.co, and adds CLI commands for resetting and deleting synced files.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
