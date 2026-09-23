<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_file_sync`

![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.3-orange.svg)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-file-sync/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-file-sync)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-file-sync/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-file-sync/actions/workflows/cgl.yml)
[![Coverage](https://coveralls.io/repos/github/konradmichalik/typo3-file-sync/badge.svg?branch=main)](https://coveralls.io/github/konradmichalik/typo3-file-sync)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-file-sync/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-file-sync/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

A lightweight TYPO3 extension that synchronizes missing files on demand: either by fetching them from a remote instance or by generating local placeholder images.

> [!TIP]
> Typical use case: staging systems or local development environments that get refreshed from production on a regular basis (e.g. via a database sync) without copying the full file storage. Missing files are fetched (or replaced with a placeholder) the moment they're actually requested, instead of shipping every asset on each refresh.

## ✨ Features

- [**Remote instance fetching**](docs/resource-handlers.md): pull missing files from a remote TYPO3 instance on demand
- [**Placeholder image generation**](docs/resource-handlers.md): generate local placeholder images (GD and SVG) when no remote instance is configured or reachable
- [**CLI commands**](docs/cli-commands.md): reset missing-file flags or delete previously synced files
- [**Deferred image loading**](docs/deferred-image-loading.md) *(experimental)*: render a placeholder immediately and swap in the real file after page load, with an optional blurred preview stage
- [**Custom resource handlers**](docs/custom-resource-handlers.md): implement your own remote source

## 🔥 Installation

### Requirements

* TYPO3 13.4 LTS or 14.3 LTS
* PHP 8.2 to 8.5
* PHP extension `ext-gd` (for placeholder and preview image generation; previews additionally need a GD build with WebP support)

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

### Upgrading

Run the same command after every update, or use the database analyser in the Install Tool. This release adds the column `tx_typo3_file_sync_failed` to `sys_file`, and deferred image loading needs it: between `composer update` and the schema update every materialize request fails with a database error and answers `500`, so every deferred image stays a grey placeholder.

## 🚀 Quick start

Configure a storage in `ext_localconf.php` (or `additional.php`) to fetch missing files from a remote instance:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'][1] = [
    [
        'identifier' => 'remote_instance',
        'configuration' => 'https://production.example.com',
    ],
];
```

The array key (`1`) is the UID of the file storage. See [Configuration](docs/configuration.md) for the backend UI alternative, chaining handlers, and the full option set.

## 📚 Documentation

| Page | What's inside |
| --- | --- |
| [Configuration](docs/configuration.md) | Backend UI setup, the full PHP option set, and chaining handlers |
| [Resource handlers](docs/resource-handlers.md) | The built-in Remote Instance and Placeholder Image handlers, Basic Auth, and timeouts |
| [CLI commands](docs/cli-commands.md) | Resetting missing-file flags and deleting synced files |
| [Deferred image loading](docs/deferred-image-loading.md) | The experimental three-stage pipeline, preview image generation internals, known limitations, and database-sync behavior |
| [Custom resource handlers](docs/custom-resource-handlers.md) | Implementing `RemoteResourceInterface` and the optional batch-fetch extension point |

## 💎 Credits

This project is inspired by the great [filefill](https://github.com/IchHabRecht/filefill) extension. File Sync targets TYPO3 13.4 LTS and 14.0+, generates placeholder images (GD and SVG) fully locally without relying on an external service like Placehold.co, and adds CLI commands for resetting and deleting synced files.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
