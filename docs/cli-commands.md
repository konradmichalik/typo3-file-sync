# CLI commands

## Fetch missing files

Fetches every file of the enabled storages that has a `sys_file` record but is missing on disk, instead of waiting for a page to request it. Useful to warm up a staging or local system, to prepare a deterministic state before a test run, or to pull in one folder you are working on:

```bash
vendor/bin/typo3 file-sync:fetch --dry-run
vendor/bin/typo3 file-sync:fetch
vendor/bin/typo3 file-sync:fetch --storage=1 --path=/user_upload/project/
vendor/bin/typo3 file-sync:fetch --limit=500 --pause=1000
```

| Option | Effect |
| --- | --- |
| `--storage`, `-s` | Only this storage |
| `--path`, `-p` | Only files whose identifier starts with this string. Add a trailing slash to match a folder: `/user_upload` also matches `/user_upload_old/` |
| `--identifier`, `-i` | Only these resource handlers, can be repeated |
| `--limit`, `-l` | Fetch at most this many files |
| `--pause` | Milliseconds to wait between two batches of 50 files |
| `--dry-run` | Report the number and size of the missing files without fetching them |

Without `--identifier`, placeholder handlers are skipped, so a file the remote cannot deliver is reported as unavailable instead of being replaced by a placeholder. Pass `--identifier=placeholder_image` to generate placeholders on purpose. Run with `-v` to list every unavailable or failed file. The command exits with a non-zero code when a file failed with an error. Unavailable files do not count as errors.

An interrupted run can simply be started again: files already on disk are skipped. `--limit` is not a paging mechanism, though. Files the remote cannot deliver stay missing and are counted again by the next run, so repeated runs with the same limit can get stuck on them. Use `--path` to split a large storage instead.

Processed files are not fetched. They are still rendered on demand.

> [!NOTE]
> The command downloads files from the remote instance back to back. Lower the load with the `concurrency` option of the [Remote Instance handler](resource-handlers.md#concurrency) and with `--pause`.

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
