<?php

namespace Zaius\Engage\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UpgradeSchemaInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{

    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        if (version_compare($context->getVersion(), '0.0.1') < 0) {
            $setup->getConnection()->query("CREATE TABLE `zaius_job` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `handler` TEXT NOT NULL,
                `queue` VARCHAR(255) NOT NULL DEFAULT 'default',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `run_at` DATETIME NULL,
                `locked_at` DATETIME NULL,
                `locked_by` VARCHAR(255) NULL,
                `failed_at` DATETIME NULL,
                `error` TEXT NULL,
                `created_at` DATETIME NOT NULL
                ) ENGINE = INNODB;"
            );
        }
    }
}