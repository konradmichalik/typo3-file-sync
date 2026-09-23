# CLI commands

## Reset missing-file flags

Resets the `missing` flag on `sys_file` records for all enabled storages or a specific one:

```bash
vendor/bin/typo3 file-sync:reset
vendor/bin/typo3 file-sync:reset --storage=1
```

## Delete synced files

Removes files previously fetched by File Sync, optionally filtered by handler or storage:

```bash
vendor/bin/typo3 file-sync:delete --all
vendor/bin/typo3 file-sync:delete --identifier=remote_instance
vendor/bin/typo3 file-sync:delete --identifier=remote_instance --storage=1
```

> [!WARNING]
> `file-sync:delete --all` permanently removes all files that were fetched by any handler. Run `file-sync:reset` afterwards to allow them to be re-synced on next access.
