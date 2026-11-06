<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Feuille de style de la page d'un formulaire, chargée dans le <head> : masque dès l'affichage la question E-mail
 * gérée par le plugin. Rien à masquer, accès refusé ou erreur : feuille vide, la page reste celle de GLPI.
 * GET forms_id
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: text/css; charset=UTF-8');
Html::header_nocache();

try {
    $form = PluginFormrequesterRequester::getAnswerableForm((int) ($_GET['forms_id'] ?? 0));
    echo PluginFormrequesterRequester::getRendererCss($form);
} catch (Throwable $e) {
    if (!in_array($e->getCode(), [403, 404], true)) {
        PluginFormrequesterRequester::log('error', 'style : ' . $e->getMessage(), [
            'exception' => get_class($e),
            'where'     => basename($e->getFile()) . ':' . $e->getLine(),
            'forms_id'  => $_GET['forms_id'] ?? null,
        ]);
    }
    echo "/* formrequester : rien à masquer */\n";
}
