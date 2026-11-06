<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Entité à sélectionner pour les demandeurs choisis (règle du formulaire de ticket).
 * GET forms_id, users_ids[], current (-1 : aucune), url_parameters[]
 */

Session::checkLoginUser();

PluginFormrequesterRequester::respond('entity', static function (): array {
    // Le contrôle d'accès au formulaire évite que ce point d'accès serve à interroger les entités de n'importe qui.
    PluginFormrequesterRequester::getAnswerableForm(
        (int) ($_GET['forms_id'] ?? 0),
        (array) ($_GET['url_parameters'] ?? [])
    );

    return PluginFormrequesterRequester::getEntityForRequesters(
        (array) ($_GET['users_ids'] ?? []),
        (int) ($_GET['current'] ?? -1)
    );
});
