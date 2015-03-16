<?php

namespace HeVinci\CompetencyBundle\Migrations\ibm_db2;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated migration based on mapping information: modify it with caution
 *
 * Generation date: 2015/03/11 03:20:00
 */
class Version20150311151958 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        $this->addSql("
            CREATE TABLE hevinci_ability_activity (
                ability_id INTEGER NOT NULL, 
                activity_id INTEGER NOT NULL, 
                PRIMARY KEY(ability_id, activity_id)
            )
        ");
        $this->addSql("
            CREATE INDEX IDX_46D92D328016D8B2 ON hevinci_ability_activity (ability_id)
        ");
        $this->addSql("
            CREATE INDEX IDX_46D92D3281C06096 ON hevinci_ability_activity (activity_id)
        ");
        $this->addSql("
            ALTER TABLE hevinci_ability_activity 
            ADD CONSTRAINT FK_46D92D328016D8B2 FOREIGN KEY (ability_id) 
            REFERENCES hevinci_ability (id) 
            ON DELETE CASCADE
        ");
        $this->addSql("
            ALTER TABLE hevinci_ability_activity 
            ADD CONSTRAINT FK_46D92D3281C06096 FOREIGN KEY (activity_id) 
            REFERENCES claro_activity (id) 
            ON DELETE CASCADE
        ");
    }

    public function down(Schema $schema)
    {
        $this->addSql("
            DROP TABLE hevinci_ability_activity
        ");
    }
}