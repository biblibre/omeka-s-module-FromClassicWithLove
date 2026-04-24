<?php

namespace FromClassicWithLove;

use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $sql = <<<'SQL'
CREATE TABLE from_classic_with_love_import (
    id INT AUTO_INCREMENT NOT NULL,
    job_id INT NOT NULL,
    undo_job_id INT DEFAULT NULL,
    has_err TINYINT(1) NOT NULL,
    stats LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)',
    UNIQUE INDEX UNIQ_2D78ED53BE04EA9 (job_id),
    UNIQUE INDEX UNIQ_2D78ED534C276F75 (undo_job_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE from_classic_with_love_resource_map (
    id INT AUTO_INCREMENT NOT NULL,
    job_id INT NOT NULL,
    resource_id INT NOT NULL,
    classic_resource_id INT NOT NULL,
    mapped_resource_name VARCHAR(255) NOT NULL,
    INDEX IDX_10D94357BE04EA9 (job_id),
    INDEX IDX_10D9435789329D25 (resource_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE from_classic_with_love_import
    ADD CONSTRAINT FK_2D78ED53BE04EA9
    FOREIGN KEY (job_id) REFERENCES job (id);
ALTER TABLE from_classic_with_love_import
    ADD CONSTRAINT FK_2D78ED534C276F75
    FOREIGN KEY (undo_job_id) REFERENCES job (id);
ALTER TABLE from_classic_with_love_resource_map
    ADD CONSTRAINT FK_10D94357BE04EA9
    FOREIGN KEY (job_id) REFERENCES job (id);
ALTER TABLE from_classic_with_love_resource_map
    ADD CONSTRAINT FK_10D9435789329D25
    FOREIGN KEY (resource_id) REFERENCES
    resource (id) ON DELETE CASCADE;
SQL;
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');

        $sql = <<<'SQL'
ALTER TABLE from_classic_with_love_resource_map DROP FOREIGN KEY FK_10D9435789329D25;
ALTER TABLE from_classic_with_love_resource_map DROP FOREIGN KEY FK_10D94357BE04EA9;
ALTER TABLE from_classic_with_love_import DROP FOREIGN KEY FK_2D78ED53BE04EA9;
ALTER TABLE from_classic_with_love_import DROP FOREIGN KEY FK_2D78ED534C276F75;
DROP TABLE IF EXISTS from_classic_with_love_resource_map;
DROP TABLE IF EXISTS from_classic_with_love_import;
SQL;

        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }
}
