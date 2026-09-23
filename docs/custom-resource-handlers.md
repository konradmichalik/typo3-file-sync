# Custom resource handlers

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

A handler that can fetch several files at once may additionally implement `BatchRemoteResourceInterface`, so File Sync prefetches them concurrently ahead of the serial calls FAL makes one file at a time:

```php
use KonradMichalik\Typo3FileSync\Resource\BatchRemoteResourceInterface;

class MyHandler implements RemoteResourceInterface, BatchRemoteResourceInterface
{
    /**
     * @param list<string> $filePaths
     */
    public function prefetch(array $filePaths): void
    {
        // Download $filePaths concurrently and cache them for the getFile() calls that follow
    }
}
```
