#
# Table structure for table 'sys_file'
#
CREATE TABLE sys_file (
    tx_typo3_file_sync_identifier varchar(255) DEFAULT '' NOT NULL
);

#
# Table structure for table 'sys_file_storage'
#
CREATE TABLE sys_file_storage (
    tx_typo3_file_sync_enable tinyint(4) DEFAULT '0' NOT NULL,
    tx_typo3_file_sync_resources text
);
