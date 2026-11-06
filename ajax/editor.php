<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Éditeur du formulaire : options des questions Demandeur (case « E-mails », « Liste par entité »).
 * GET  forms_id                         : état de chaque question Demandeur
 * POST forms_id, uuid, action=enable    : crée et relie la question Email, « Obligatoire » mémorisé par le plugin
 * POST forms_id, uuid, action=disable   : supprime la question Email, Demandeur retrouve son « Obligatoire »
 * POST forms_id, uuid, action=entity_on / entity_off : option « liste par entité, comme dans un ticket »
 */

Session::checkLoginUser();

PluginFormrequesterRequester::respond('editor', static function (): array {
    $form = PluginFormrequesterRequester::getEditableForm((int) ($_REQUEST['forms_id'] ?? 0));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $uuid = (string) ($_POST['uuid'] ?? '');
        match ((string) ($_POST['action'] ?? '')) {
            'enable'     => PluginFormrequesterRequester::enableEmail($form, $uuid),
            'disable'    => PluginFormrequesterRequester::disableEmail($form, $uuid),
            'entity_on'  => PluginFormrequesterRequester::setEntityMode($form, $uuid, true),
            'entity_off' => PluginFormrequesterRequester::setEntityMode($form, $uuid, false),
            default   => throw new RuntimeException('Action inconnue', 400),
        };
    }

    return PluginFormrequesterRequester::getEditorStatus($form);
});
