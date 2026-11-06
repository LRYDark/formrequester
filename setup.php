<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * La question « Demandeur » du Catalogue de services se comporte comme le champ Demandeur d'un ticket :
 * liste limitée à l'entité choisie, entité remplie d'après le demandeur, adresse mail acceptée.
 * -------------------------------------------------------------------------
 * @author    REINERT Joris
 * @license   GPLv3
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_FORMREQUESTER_VERSION', '1.0.0');
define('PLUGIN_FORMREQUESTER_MIN_GLPI', '11.0.0');
define('PLUGIN_FORMREQUESTER_MAX_GLPI', '11.0.99');

/**
 * Init hooks of the plugin.
 *
 * @return void
 */
function plugin_init_formrequester()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['formrequester'] = true;

    // Script chargé sur toutes les pages ; il ne fait quelque chose que sur la page d'un formulaire et dans son
    // éditeur (public/js/formrequester.js, servi en /plugins/formrequester/js/formrequester.js).
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['formrequester'] = ['js/formrequester.js'];

    // Page d'un formulaire du Catalogue de services : feuille de style calculée, chargée dans le <head>, qui masque
    // la question E-mail gérée par le plugin dès l'affichage (sans attendre le script).
    if (preg_match('#/Form/Render/(\d+)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $matches) === 1) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['formrequester'] = ['ajax/style.php?forms_id=' . (int) $matches[1]];
    }

    // Choix « Obligatoire » des questions Demandeur qui acceptent les adresses mail (voir hook.php).
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['formrequester'] = [
        'Glpi\Form\Question' => 'plugin_formrequester_question_pre_update',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['formrequester'] = [
        'Glpi\Form\Question' => 'plugin_formrequester_question_purge',
    ];
}

/**
 * Plugin metadata.
 *
 * @return array
 */
function plugin_version_formrequester()
{
    return [
        'name'         => __('Options du champ Demandeur (Catalogue de services)', 'formrequester'),
        'version'      => PLUGIN_FORMREQUESTER_VERSION,
        'author'       => 'REINERT Joris',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_FORMREQUESTER_MIN_GLPI,
                'max' => PLUGIN_FORMREQUESTER_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Check prerequisites at installation time.
 *
 * @return bool
 */
function plugin_formrequester_check_prerequisites()
{
    return version_compare(GLPI_VERSION, PLUGIN_FORMREQUESTER_MIN_GLPI, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_FORMREQUESTER_MAX_GLPI, '<=');
}

/**
 * Configuration check callback.
 *
 * @param boolean $verbose
 *
 * @return boolean
 */
function plugin_formrequester_check_config($verbose = false)
{
    return true;
}
