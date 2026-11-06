<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Erreurs remontées par le navigateur, écrites dans files/_log/formrequester.log.
 * POST level, message, context, page
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

PluginFormrequesterRequester::respond('log', static function (): array {
    PluginFormrequesterRequester::logFromBrowser(
        (string) ($_POST['level'] ?? 'error'),
        (string) ($_POST['message'] ?? ''),
        (string) ($_POST['context'] ?? ''),
        (string) ($_POST['page'] ?? '')
    );

    return ['logged' => true];
});
