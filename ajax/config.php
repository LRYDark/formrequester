<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Questions pilotées sur la page d'un formulaire : Demandeur, Entité, Email reliée aux demandeurs.
 * GET forms_id, url_parameters[]
 */

Session::checkLoginUser();

PluginFormrequesterRequester::respond('config', static function (): array {
    $form = PluginFormrequesterRequester::getAnswerableForm(
        (int) ($_GET['forms_id'] ?? 0),
        (array) ($_GET['url_parameters'] ?? [])
    );

    return PluginFormrequesterRequester::getMapping($form);
});
