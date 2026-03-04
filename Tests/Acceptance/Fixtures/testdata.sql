--
-- Test data for typo3-file-sync extension
-- Imported automatically by `ddev install` via import_sql_data()
--
-- Creates pages, content elements with images, and file records.
-- The image files do NOT exist locally — they are served by the fake
-- server at remote.typo3-file-sync.ddev.site and will be synced
-- on first frontend access via FileSyncDriver.
--
-- UIDs start at 100 to avoid conflicts with auto-generated records.
--

-- =====================================================
-- Pages
-- =====================================================
-- uid=1 (root) already exists from TYPO3 setup.
-- Add a Gallery subpage.

INSERT INTO `pages` (`uid`, `pid`, `tstamp`, `crdate`, `sorting`, `title`, `slug`, `doktype`, `is_siteroot`)
VALUES (100, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 256, 'Gallery', '/gallery', 1, 0);

-- =====================================================
-- sys_file — image records (files don't exist locally)
-- =====================================================

INSERT INTO `sys_file` (`uid`, `pid`, `tstamp`, `storage`, `identifier`, `identifier_hash`, `folder_hash`, `name`, `extension`, `mime_type`, `size`, `sha1`, `creation_date`, `modification_date`, `missing`, `type`, `tx_typo3_file_sync_identifier`)
VALUES
    (100, 0, UNIX_TIMESTAMP(), 1, '/user_upload/test-image-1.jpg', SHA1('/user_upload/test-image-1.jpg'), SHA1('/user_upload/'), 'test-image-1.jpg', 'jpg', 'image/jpeg', 3796, '0000000000000000000000000000000000000001', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 2, ''),
    (101, 0, UNIX_TIMESTAMP(), 1, '/user_upload/test-image-2.jpg', SHA1('/user_upload/test-image-2.jpg'), SHA1('/user_upload/'), 'test-image-2.jpg', 'jpg', 'image/jpeg', 4521, '0000000000000000000000000000000000000002', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 2, ''),
    (102, 0, UNIX_TIMESTAMP(), 1, '/user_upload/test-image-3.png', SHA1('/user_upload/test-image-3.png'), SHA1('/user_upload/'), 'test-image-3.png', 'png', 'image/png', 1026, '0000000000000000000000000000000000000003', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 2, ''),
    (103, 0, UNIX_TIMESTAMP(), 1, '/user_upload/test-image-4.jpg', SHA1('/user_upload/test-image-4.jpg'), SHA1('/user_upload/'), 'test-image-4.jpg', 'jpg', 'image/jpeg', 5810, '0000000000000000000000000000000000000004', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 2, '');

-- =====================================================
-- sys_file_metadata — image dimensions
-- =====================================================

INSERT INTO `sys_file_metadata` (`uid`, `pid`, `tstamp`, `crdate`, `file`, `width`, `height`)
VALUES
    (100, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 100, 300, 200),
    (101, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 101, 400, 300),
    (102, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 102, 200, 200),
    (103, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 103, 600, 400);

-- =====================================================
-- tt_content — textmedia content elements
-- =====================================================

-- Page uid=1 (Home): 2 content elements
INSERT INTO `tt_content` (`uid`, `pid`, `tstamp`, `crdate`, `sorting`, `CType`, `colPos`, `header`, `bodytext`, `assets`)
VALUES
    (100, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 256, 'textmedia', 0, 'Welcome to File Sync Demo', '<p>This page demonstrates the file sync functionality. The images below are fetched from a remote server on first access.</p>', 1),
    (101, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 512, 'textmedia', 0, 'Another Image Example', '<p>This content element contains a different image that is also synced from the remote instance.</p>', 1);

-- Page uid=100 (Gallery): 2 content elements
INSERT INTO `tt_content` (`uid`, `pid`, `tstamp`, `crdate`, `sorting`, `CType`, `colPos`, `header`, `bodytext`, `assets`)
VALUES
    (102, 100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 256, 'textmedia', 0, 'PNG Image Test', '<p>Testing file sync with a PNG image format.</p>', 1),
    (103, 100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 512, 'textmedia', 0, 'Large Image Test', '<p>Testing file sync with a larger image (600x400).</p>', 1);

-- =====================================================
-- sys_file_reference — connect images to content
-- =====================================================

INSERT INTO `sys_file_reference` (`uid`, `pid`, `tstamp`, `crdate`, `sorting_foreign`, `uid_local`, `uid_foreign`, `tablenames`, `fieldname`)
VALUES
    (100, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 1, 100, 100, 'tt_content', 'assets'),
    (101, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 1, 101, 101, 'tt_content', 'assets'),
    (102, 100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 1, 102, 102, 'tt_content', 'assets'),
    (103, 100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 1, 103, 103, 'tt_content', 'assets');
