<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Une seule table : les options cochées sur les questions Demandeur (liste par entité, e-mails) et, avec les
 * e-mails, leur choix « Obligatoire ». Aucune option : la question est ignorée. Le reste est lu dans la
 * configuration native des formulaires.
 */

/**
 * Charge la classe du plugin, que l'installation appelle avant l'activation de son chargement automatique.
 */
function plugin_formrequester_load_classes(): void
{
    foreach (glob(__DIR__ . '/inc/*.class.php') ?: [] as $file) {
        include_once $file;
    }
}

/**
 * Plugin install process.
 *
 * @return bool
 */
function plugin_formrequester_install()
{
    global $DB;

    plugin_formrequester_load_classes();

    $table = PluginFormrequesterRequester::TABLE;
    if (!$DB->tableExists($table)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $DB->doQuery(<<<SQL
            CREATE TABLE `$table` (
                `id` int $sign NOT NULL AUTO_INCREMENT,
                `forms_questions_id` int $sign NOT NULL DEFAULT '0',
                `use_entity` tinyint NOT NULL DEFAULT '0',
                `use_email` tinyint NOT NULL DEFAULT '0',
                `is_mandatory` tinyint NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `forms_questions_id` (`forms_questions_id`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
SQL);
    } elseif (!$DB->fieldExists($table, 'use_email')) {
        // Table de la première version (choix « Obligatoire » seulement) : chacune de ses lignes venait de la case
        // « E-mails ». Les colonnes d'options sont ajoutées et ces lignes gardent l'option e-mails cochée.
        $migration = new Migration(PLUGIN_FORMREQUESTER_VERSION);
        $migration->addField($table, 'use_entity', 'bool', ['value' => 0, 'after' => 'forms_questions_id']);
        $migration->addField($table, 'use_email', 'bool', ['value' => 0, 'after' => 'use_entity']);
        $migration->executeMigration();
        $DB->update($table, ['use_email' => 1], ['id' => ['>', 0]]);
    }

    return true;
}

/**
 * Plugin uninstall process : chaque question Demandeur retrouve dans GLPI son choix « Obligatoire ». Les questions
 * « E-mail du demandeur » restent dans les formulaires : sans le plugin, elles s'affichent comme une question Email
 * ordinaire, toujours reliée aux demandeurs.
 *
 * @return bool
 */
function plugin_formrequester_uninstall()
{
    global $DB;

    plugin_formrequester_load_classes();

    PluginFormrequesterRequester::restoreAllMandatory();
    $DB->doQuery('DROP TABLE IF EXISTS `' . PluginFormrequesterRequester::TABLE . '`');

    return true;
}

/**
 * Enregistrement d'une question (éditeur) : choix « Obligatoire » des questions Demandeur qui acceptent les adresses.
 *
 * @param CommonDBTM $item
 *
 * @return void
 */
function plugin_formrequester_question_pre_update(CommonDBTM $item): void
{
    if ($item instanceof Glpi\Form\Question) {
        try {
            PluginFormrequesterRequester::onQuestionPreUpdate($item);
        } catch (Throwable $e) {
            // L'enregistrement du formulaire ne doit jamais échouer à cause du plugin.
            PluginFormrequesterRequester::log('error', 'enregistrement de la question : ' . $e->getMessage(), [
                'question' => $item->getID(),
                'where'    => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
        }
    }
}

/**
 * Suppression d'une question : choix « Obligatoire » rendu à GLPI si la question Email disparaît.
 *
 * @param CommonDBTM $item
 *
 * @return void
 */
function plugin_formrequester_question_purge(CommonDBTM $item): void
{
    if ($item instanceof Glpi\Form\Question) {
        try {
            PluginFormrequesterRequester::onQuestionPurge($item);
        } catch (Throwable $e) {
            PluginFormrequesterRequester::log('error', 'suppression de la question : ' . $e->getMessage(), [
                'question' => $item->fields['id'] ?? null,
                'where'    => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
        }
    }
}
