<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Demandeurs possibles pour la question Demandeur, limités à l'entité choisie (liste du champ Demandeur d'un ticket).
 * GET forms_id, question, entity (-1 : aucune), searchText, page, url_parameters[]
 */

Session::checkLoginUser();

PluginFormrequesterRequester::respond('search', static function (): array {
    $form = PluginFormrequesterRequester::getAnswerableForm(
        (int) ($_GET['forms_id'] ?? 0),
        (array) ($_GET['url_parameters'] ?? [])
    );

    return PluginFormrequesterRequester::searchRequesters(
        $form,
        (int) ($_GET['question'] ?? 0),
        (int) ($_GET['entity'] ?? -1),
        trim((string) ($_GET['searchText'] ?? '')),
        (int) ($_GET['page'] ?? 1)
    );
});
