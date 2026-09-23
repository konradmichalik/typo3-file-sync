# Resource handlers

File Sync ships two built-in resource handlers. Chain them in a storage's configuration (see [Configuration](configuration.md)) to try them in order; the first one that answers wins.

## Remote Instance

Fetches missing files from a remote TYPO3 instance via HTTP(S). The file path is appended to the configured base URL and requested with a `GET`; any non-`200` response is treated as "not available" so the next handler in the chain can take over.

```php
'identifier' => 'remote_instance',
'configuration' => 'https://production.example.com',
```

### Basic Auth

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

### Timeouts

Requests use a connect timeout of `5` seconds and a request timeout of `15` seconds by default, so a slow or unreachable remote instance cannot block page rendering indefinitely. Both can be adjusted via PHP configuration:

```php
'identifier' => 'remote_instance',
'configuration' => [
    'url' => 'https://production.example.com',
    'connect_timeout' => 5,
    'timeout' => 15,
],
```

## Placeholder Image

Generates local placeholder images with configurable colors. Supports GD-based formats (`jpg`, `png`, `gif`, `webp`, `avif`) and `svg`.

```php
'identifier' => 'placeholder_image',
'configuration' => '#CCCCCC, #969696', // backgroundColor, textColor
```

The generated image displays the original file dimensions as a text overlay (e.g. `1920 x 1080`).

> [!TIP]
> Chain both handlers to get real assets from production when available, falling back to a placeholder when they are not.
