<?php

/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * La question « Demandeur » du Catalogue de services se comporte comme le champ Demandeur d'un ticket.
 * -------------------------------------------------------------------------
 * @author    REINERT Joris
 * @license   GPLv3
 * -------------------------------------------------------------------------
 */

use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Comment;
use Glpi\Form\Destination\AbstractCommonITILFormDestination;
use Glpi\Form\Destination\CommonITILField\EntityField;
use Glpi\Form\Destination\CommonITILField\EntityFieldConfig;
use Glpi\Form\Destination\CommonITILField\EntityFieldStrategy;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\RequesterField;
use Glpi\Form\Destination\CommonITILField\RequesterFieldConfig;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeRequester;
use Glpi\Form\Section;

/**
 * Tout le côté serveur du plugin. Il ne remplace rien dans GLPI : il lit la configuration native du formulaire et
 * réutilise les fonctions du formulaire de ticket (liste des acteurs, entité du demandeur). Les réponses envoyées
 * restent celles des questions natives, et c'est GLPI qui crée le ticket.
 *
 * L'adresse mail d'un demandeur absent de GLPI passe par une question native de type Email, reliée aux demandeurs
 * de la destination : GLPI en fait lui-même un demandeur « mail direct ». L'interrupteur « Autoriser les adresses
 * mail » de l'éditeur crée ou supprime cette question et la relie ou la détache.
 */
class PluginFormrequesterRequester
{
    /** Journal du plugin : files/_log/formrequester.log */
    public const LOG_FILE = 'formrequester';

    /** Question Email créée par l'interrupteur de l'éditeur. */
    public const EMAIL_QUESTION_NAME = 'E-mail du demandeur';
    public const EMAIL_QUESTION_DESCRIPTION = '<p>Ne pas supprimer : question gérée par le plugin « Options du champ '
        . 'Demandeur ». Elle reçoit l’adresse mail tapée dans la question Demandeur et n’est jamais affichée. Pour la '
        . 'retirer, décocher « E-mails » dans les types autorisés de la question Demandeur.</p>';

    /**
     * Choix « Obligatoire » des questions Demandeur qui acceptent les adresses mail. GLPI refuserait un demandeur
     * saisi seulement par son adresse si sa case « Obligatoire » était cochée : le plugin la garde décochée et
     * mémorise ici le choix de l'éditeur, qu'il applique lui-même sur la page (« un demandeur ou une adresse »).
     */
    public const TABLE = 'glpi_plugin_formrequester_requesters';

    /** Nombre maximal de lignes que le navigateur peut écrire dans le journal par session (anti-inondation). */
    private const MAX_BROWSER_LOGS = 50;

    /** Modifications faites par le plugin lui-même : ses crochets sur les questions ne s'en mêlent pas. */
    private static bool $internal_change = false;

    // ------------------------------------------------------------------------------------------------------------
    // Points d'accès AJAX
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Exécute un point d'accès AJAX et renvoie son résultat en JSON. Une erreur est écrite dans le journal du plugin
     * et renvoyée avec son code HTTP, jamais comme une page d'erreur HTML.
     *
     * @param callable(): array $handler
     */
    public static function respond(string $endpoint, callable $handler): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        Html::header_nocache();

        try {
            echo json_encode($handler(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [400, 403, 404, 409], true) ? (int) $e->getCode() : 500;
            self::log($status === 500 ? 'error' : 'warning', "$endpoint : " . $e->getMessage(), [
                'http'      => $status,
                'exception' => get_class($e),
                'where'     => basename($e->getFile()) . ':' . $e->getLine(),
                'request'   => self::requestSummary(),
                'trace'     => $status === 500 ? self::shortTrace($e) : null,
            ]);
            http_response_code($status);
            echo json_encode([
                'error' => $status === 500 ? 'Erreur interne, voir files/_log/formrequester.log' : $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Formulaire que l'utilisateur connecté a le droit de remplir (même règle que la page du formulaire).
     *
     * @param array<string, mixed> $url_parameters paramètres de l'adresse de la page (jeton d'accès direct…)
     */
    public static function getAnswerableForm(int $forms_id, array $url_parameters = []): Form
    {
        $form = self::loadForm($forms_id);

        // Un administrateur des formulaires y accède toujours (aperçu), comme dans GLPI.
        if (Session::haveRight(Form::$rightname, READ)) {
            return $form;
        }

        $parameters = new FormAccessParameters(
            session_info: Session::getCurrentSessionInfo(),
            url_parameters: array_filter($url_parameters, 'is_string'),
        );
        if (!FormAccessControlManager::getInstance()->canAnswerForm($form, $parameters)) {
            throw new RuntimeException("Accès refusé au formulaire $forms_id", 403);
        }

        return $form;
    }

    /**
     * Formulaire que l'utilisateur connecté a le droit de modifier (éditeur).
     */
    public static function getEditableForm(int $forms_id): Form
    {
        $form = self::loadForm($forms_id);
        if (!$form->can($form->getID(), UPDATE)) {
            throw new RuntimeException("Modification du formulaire $forms_id refusée", 403);
        }

        return $form;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Page du formulaire
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Questions du formulaire que le plugin pilote sur la page :
     *  - requester : la question « Demandeur » qui alimente les demandeurs de la destination ;
     *  - entity    : la question « Entité » qui donne l'entité du ticket ;
     *  - email     : la question « Email » reliée aux demandeurs ; le plugin la masque et y range l'adresse tapée
     *                dans « Demandeur ».
     * Une fonction est désactivée si la question est ambiguë (plusieurs candidates) ; c'est signalé dans le journal.
     *
     * @return array<string, mixed>
     */
    public static function getMapping(Form $form): array
    {
        $analysis = self::analyse($form);
        $warnings = $analysis['warnings'];

        $requesters = [];
        $entities   = [];
        foreach ($analysis['destinations'] as $destination) {
            $requesters += $destination['requesters'];
            $entities   += $destination['entities'];
        }

        $requester = self::single($requesters, 'Demandeur', $warnings);
        $options   = $requester !== null ? self::getOptions((int) $requester->getID()) : null;
        if ($options === null) {
            // Aucune option cochée sur la question Demandeur : le plugin ne fait rien sur ce formulaire.
            $requester = null;
        }
        $email  = null;
        $entity = null;
        if ($requester !== null) {
            $emails = self::activeEmails($analysis, $requester);
            $email  = self::single($emails, 'Email reliées aux demandeurs', $warnings);
            $entity = $options['use_entity'] ? self::single($entities, 'Entité', $warnings) : null;
        }

        $mapping = [
            'requester'           => null,
            'multiple'            => false,
            'requester_mandatory' => false,
            'required'            => false,
            'entity_mode'         => false,
            'entity'              => null,
            'email'               => null,
            'email_pattern'       => substr(Toolbox::ACTOR_EMAIL_VALIDATION_REGEX, 1, -2),
        ];

        if ($requester !== null) {
            $type = $requester->getQuestionType();
            $mapping['requester']           = (int) $requester->getID();
            $mapping['multiple']            = $type instanceof QuestionTypeRequester && $type->isMultipleActors($requester);
            $mapping['requester_mandatory'] = (bool) ($requester->fields['is_mandatory'] ?? false);
            // Avec les adresses mail, l'obligation (« un demandeur ou une adresse ») est appliquée par le plugin.
            $mapping['required']            = $email !== null
                ? self::intendedMandatory($requester)
                : $mapping['requester_mandatory'];
            $mapping['entity_mode']         = (bool) $options['use_entity'];
            $mapping['entity']              = $entity !== null ? (int) $entity->getID() : null;
            $mapping['email']               = $email !== null ? (int) $email->getID() : null;

            if ($email !== null) {
                array_push($warnings, ...self::emailProblems($requester, $email));
            }
        }

        self::logWarningsOnce($form, $warnings);

        return $mapping;
    }

    /**
     * Feuille de style de la page du formulaire : la question E-mail gérée par le plugin est masquée dès l'affichage
     * (sans attendre le script, qui sinon la ferait apparaître puis disparaître).
     */
    public static function getRendererCss(Form $form): string
    {
        $mapping = self::getMapping($form);
        if ($mapping['email'] === null) {
            return "/* formrequester : rien à masquer */\n";
        }

        return sprintf(
            "/* formrequester : question E-mail gérée par la case « E-mails » */\n"
            . "[data-glpi-form-renderer-id=\"%d\"][data-glpi-form-renderer-question] { display: none !important; }\n",
            $mapping['email']
        );
    }

    /**
     * Demandeurs possibles, exactement ceux du champ Demandeur d'un ticket : même fonction de GLPI
     * (Dropdown::getDropdownActors), donc mêmes droits, groupes « demandeur » et filtre d'entité — les utilisateurs
     * ayant un profil récursif sur une entité parente en font partie, comme dans le ticket. Sans entité choisie : toutes
     * les entités actives, comme un ticket encore sans demandeur.
     *
     * @return array{results: array<int, mixed>, more: bool}
     */
    public static function searchRequesters(Form $form, int $question_id, int $entities_id, string $text, int $page): array
    {
        global $CFG_GLPI;

        $question = self::findQuestion($form, static fn(Question $q): bool => (int) $q->getID() === $question_id);
        $type = $question?->getQuestionType();
        if (!$type instanceof QuestionTypeRequester) {
            throw new RuntimeException("La question $question_id n'est pas une question Demandeur de ce formulaire", 400);
        }

        $returned_itemtypes = [];
        if ($type->isTypeEnabled($question, User::class)) {
            $returned_itemtypes[] = 'User';
        }
        if ($type->isTypeEnabled($question, Group::class)) {
            $returned_itemtypes[] = 'Group';
        }
        if ($returned_itemtypes === []) {
            return ['results' => [], 'more' => false];
        }

        $page_limit = max(1, (int) ($CFG_GLPI['dropdown_max'] ?? 100));
        $post = [
            'actortype'          => 'requester',
            'users_right'        => 'all',
            'itemtype'           => Ticket::class,
            'items_id'           => -1,
            'entity_restrict'    => json_encode($entities_id >= 0 ? $entities_id : -1),
            'searchText'         => $text,
            'returned_itemtypes' => $returned_itemtypes,
            'page'               => max(1, $page),
            'page_limit'         => $page_limit,
        ];
        // Jeton exigé par la fonction du ticket ; il est créé ici, après le contrôle d'accès au formulaire, comme le
        // gabarit du ticket le crée à l'affichage.
        $post['_idor_token'] = Session::getNewIDORToken(Ticket::class, ['users_right' => 'all']);

        $actors = Dropdown::getDropdownActors($post, false);
        if (!is_array($actors)) {
            throw new RuntimeException('Recherche des acteurs refusée par GLPI (jeton IDOR)');
        }

        // Identifiants au format de la question Demandeur : users_id-12, groups_id-3.
        $results = [];
        foreach ($actors['results'] ?? [] as $entry) {
            if (isset($entry['children']) && is_array($entry['children'])) {
                $children = [];
                foreach ($entry['children'] as $child) {
                    if (($child['itemtype'] ?? '') === 'Group' && isset($child['items_id'])) {
                        $children[] = [
                            'id'    => 'groups_id-' . (int) $child['items_id'],
                            'text'  => (string) ($child['text'] ?? ''),
                            'title' => (string) ($child['title'] ?? $child['text'] ?? ''),
                        ];
                    }
                }
                if ($children !== []) {
                    $results[] = ['text' => (string) ($entry['text'] ?? ''), 'children' => $children];
                }
            } elseif (($entry['itemtype'] ?? '') === 'User' && isset($entry['items_id'])) {
                $results[] = [
                    'id'    => 'users_id-' . (int) $entry['items_id'],
                    'text'  => (string) ($entry['text'] ?? ''),
                    'title' => (string) ($entry['title'] ?? $entry['text'] ?? ''),
                ];
            }
        }

        return [
            'results' => $results,
            'more'    => count($actors['results'] ?? []) >= $page_limit,
        ];
    }

    /**
     * Entité à donner au ticket pour ces demandeurs : la règle du formulaire de ticket (Ticket::showForm). Les entités
     * communes aux demandeurs parmi les entités actives ; l'entité déjà choisie si elle en fait partie, sinon la
     * première.
     *
     * @param array<int|string> $users_ids
     *
     * @return array{entities_id: int|null, name: string}
     */
    public static function getEntityForRequesters(array $users_ids, int $current): array
    {
        $users_ids = array_values(array_filter(array_map('intval', $users_ids), static fn(int $id): bool => $id > 0));
        if ($users_ids === []) {
            return ['entities_id' => null, 'name' => ''];
        }

        $entities = array_map('intval', (new Ticket())->getEntitiesForRequesters(['_users_id_requester' => $users_ids]));
        if ($entities === []) {
            return ['entities_id' => null, 'name' => ''];
        }

        $entities_id = in_array($current, $entities, true) ? $current : (int) current($entities);

        return [
            'entities_id' => $entities_id,
            'name'        => (string) Dropdown::getDropdownName('glpi_entities', $entities_id),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Éditeur du formulaire : interrupteur « Autoriser les adresses mail »
    // ------------------------------------------------------------------------------------------------------------

    /**
     * État de chaque question Demandeur enregistrée, pour l'interrupteur de l'éditeur.
     *
     * @return array{questions: array<string, mixed>, email_uuids: array<int, string>}
     */
    public static function getEditorStatus(Form $form): array
    {
        $analysis    = self::analyse($form);
        $questions   = [];
        $email_uuids = [];

        foreach ($analysis['questions'] as $question) {
            if (!$question->getQuestionType() instanceof QuestionTypeRequester) {
                continue;
            }

            $emails = self::activeEmails($analysis, $question);
            $email  = $emails === [] ? null : reset($emails);
            $used   = false;
            foreach ($analysis['destinations'] as $destination) {
                $used = $used || isset($destination['requesters'][(int) $question->getID()]);
            }

            $problems = $email !== null ? self::emailProblems($question, $email, true) : [];
            if ($email !== null && !self::hasTable()) {
                // Plugin mis à jour sans réinstallation : « Obligatoire » ne peut pas être mémorisé.
                $problems[] = 'Plugin à réinstaller : Configuration → Plugins → Désinstaller, puis Installer et Activer. '
                    . 'Sans cela, votre choix « Obligatoire » ne peut pas être enregistré.';
                self::logWarningsOnce($form, ['table ' . self::TABLE . ' absente : désinstaller puis réinstaller le plugin']);
            }
            if (count($emails) > 1) {
                $problems[] = 'Plusieurs questions E-mail sont reliées aux demandeurs : supprimez celles en trop, une '
                    . 'seule suffit.';
            }

            foreach ($emails as $linked) {
                $email_uuids[] = (string) $linked->fields['uuid'];
            }

            $options = self::getOptions((int) $question->getID());
            $questions[(string) $question->fields['uuid']] = [
                'id'                  => (int) $question->getID(),
                'entity_mode'         => (bool) ($options['use_entity'] ?? false),
                'installed'           => self::hasTable(),
                'requester_mandatory' => (bool) $question->fields['is_mandatory'],
                // Case « Obligatoire » à afficher : le choix mémorisé par le plugin quand les adresses sont autorisées.
                'mandatory'           => $email !== null ? self::intendedMandatory($question) : (bool) $question->fields['is_mandatory'],
                'used'                => $used,
                'email'               => $email === null ? null : [
                    'id'        => (int) $email->getID(),
                    'uuid'      => (string) $email->fields['uuid'],
                    'name'      => (string) $email->fields['name'],
                    'mandatory' => (bool) $email->fields['is_mandatory'],
                ],
                'problems'            => $problems,
            ];
        }

        return ['questions' => $questions, 'email_uuids' => array_values(array_unique($email_uuids))];
    }

    /**
     * Option « Liste par entité, comme dans un ticket » : la liste Demandeur est limitée à l'entité choisie et choisir
     * un demandeur remplit la question Entité. Simple réglage du plugin : le formulaire n'est pas modifié.
     */
    public static function setEntityMode(Form $form, string $requester_uuid, bool $enabled): void
    {
        $requester = self::findRequesterByUuid($form, $requester_uuid);
        $options   = self::getOptions((int) $requester->getID());
        self::saveOptions(
            (int) $requester->getID(),
            $enabled,
            $options['use_email'] ?? false,
            $options['is_mandatory'] ?? false
        );

        self::log('info', sprintf(
            'formulaire %d : liste par entité %s sur la question Demandeur %d',
            $form->getID(),
            $enabled ? 'activée' : 'désactivée',
            $requester->getID()
        ));
    }

    /**
     * Case « E-mails » cochée : la question Email est créée (ou réutilisée si elle existe déjà), facultative, reliée
     * aux demandeurs de chaque destination. Le choix « Obligatoire » de la question Demandeur est mémorisé par le
     * plugin et sa case native décochée (sinon GLPI refuserait une adresse seule). Tout ou rien (transaction).
     */
    public static function enableEmail(Form $form, string $requester_uuid): void
    {
        global $DB;

        $requester = self::findRequesterByUuid($form, $requester_uuid);
        $analysis  = self::analyse($form);
        $emails    = self::linkedEmails($analysis, $requester);
        $email     = $emails === [] ? null : reset($emails);
        $created   = false;
        $mandatory = self::intendedMandatory($requester);

        $DB->beginTransaction();
        try {
            if ($email === null) {
                $email   = self::createEmailQuestion($requester);
                $created = true;
            } elseif ($email->fields['is_mandatory']) {
                self::updateQuestion($email, ['is_mandatory' => 0]);
            }

            self::storeMandatory((int) $requester->getID(), $mandatory);
            if ($requester->fields['is_mandatory']) {
                self::updateQuestion($requester, ['is_mandatory' => 0]);
            }

            // Destinations qui utilisent déjà cette question Demandeur ; si aucune ne l'utilise (réglage par défaut :
            // la personne qui remplit le formulaire), toutes les destinations passent à Demandeur + Email.
            $using = array_filter(
                $analysis['destinations'],
                static fn(array $destination): bool => isset($destination['requesters'][(int) $requester->getID()])
            );
            foreach ($using !== [] ? $using : $analysis['destinations'] as $destination) {
                self::linkEmailToRequesters(
                    $form,
                    $destination['item'],
                    (int) $requester->getID(),
                    (int) $email->getID(),
                    isset($destination['requesters'][(int) $requester->getID()])
                );
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        self::log('info', sprintf(
            'formulaire %d : adresses mail autorisées sur la question Demandeur %d (question Email %d %s, obligatoire : %s)',
            $form->getID(),
            $requester->getID(),
            $email->getID(),
            $created ? 'créée' : 'existante',
            $mandatory ? 'oui' : 'non'
        ));
    }

    /**
     * Case « E-mails » décochée : la question Email est détachée des demandeurs et supprimée, et la question
     * Demandeur retrouve dans GLPI le choix « Obligatoire » mémorisé par le plugin (fonctionnement normal d'un
     * formulaire). Refusé si une condition d'affichage ou de validation utilise la question Email. Tout ou rien.
     */
    public static function disableEmail(Form $form, string $requester_uuid): void
    {
        global $DB;

        $requester = self::findRequesterByUuid($form, $requester_uuid);
        $analysis  = self::analyse($form);
        $mandatory = self::intendedMandatory($requester);
        $emails    = self::activeEmails($analysis, $requester);

        foreach ($emails as $email) {
            if (self::isUsedInConditions($form, (string) $email->fields['uuid'])) {
                throw new RuntimeException(sprintf(
                    'La question « %s » est utilisée dans une condition du formulaire : retirez d\'abord la condition.',
                    $email->fields['name']
                ), 409);
            }
        }

        $DB->beginTransaction();
        try {
            foreach ($analysis['destinations'] as $destination) {
                self::unlinkFromRequesters($form, $destination['item'], array_keys($emails));
            }

            foreach ($emails as $email) {
                $deleted = self::internally(static fn() => (new Question())->delete(['id' => $email->getID()], true));
                if (!$deleted) {
                    throw new RuntimeException('Suppression de la question Email ' . $email->getID() . ' refusée par GLPI');
                }
            }

            if ((bool) $requester->fields['is_mandatory'] !== $mandatory) {
                self::updateQuestion($requester, ['is_mandatory' => (int) $mandatory]);
            }
            self::forgetMandatory((int) $requester->getID());

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        self::log('info', sprintf(
            'formulaire %d : adresses mail retirées de la question Demandeur %d (question(s) Email %s supprimée(s), '
                . 'obligatoire rendu à GLPI : %s)',
            $form->getID(),
            $requester->getID(),
            implode(', ', array_keys($emails)) ?: 'aucune',
            $mandatory ? 'oui' : 'non'
        ));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Choix « Obligatoire » des questions Demandeur qui acceptent les adresses mail
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Crochet « pre_item_update » des questions (enregistrement de l'éditeur) : sur une question Demandeur qui
     * accepte les adresses mail, la case « Obligatoire » envoyée par l'éditeur est mémorisée par le plugin et la case
     * native reste décochée. Un réglage fait à la main (question Email reliée sans passer par la case « E-mails ») est
     * pris en charge de la même façon à son premier enregistrement.
     */
    public static function onQuestionPreUpdate(Question $question): void
    {
        if (self::$internal_change || !array_key_exists('is_mandatory', $question->input)) {
            return;
        }

        // Question E-mail gérée par le plugin (case « E-mails » cochée) : jamais affichée, donc toujours facultative
        // (obligatoire, elle exigerait une adresse même quand un demandeur est choisi dans la liste).
        if ($question->getQuestionType() instanceof QuestionTypeEmail) {
            if ((bool) $question->input['is_mandatory'] && self::isManagedEmail($question)) {
                $question->input['is_mandatory'] = 0;
            }
            return;
        }

        if (!$question->getQuestionType() instanceof QuestionTypeRequester) {
            return;
        }

        // Case « E-mails » pas cochée : formulaire ordinaire, le plugin ne touche à rien.
        $question_id = (int) $question->getID();
        if (self::getStoredMandatory($question_id) === null) {
            return;
        }

        $wanted   = (bool) $question->input['is_mandatory'];
        $previous = self::getStoredMandatory($question_id);
        self::storeMandatory($question_id, $wanted);
        $question->input['is_mandatory'] = 0;

        // Trace seulement quand quelque chose change (choix modifié, ou case native encore cochée et corrigée).
        if ($previous !== $wanted || $question->fields['is_mandatory']) {
            self::log('info', sprintf(
                'formulaire %d : question Demandeur %d, « Obligatoire » mémorisé par le plugin : %s',
                $question->fields['forms_sections_id'] ? $question->getForm()->getID() : 0,
                $question_id,
                $wanted ? 'oui' : 'non'
            ));
        }
    }

    /**
     * Crochet « item_purge » des questions : une question Demandeur supprimée oublie son choix ; une question Email
     * supprimée à la main rend à GLPI le choix « Obligatoire » des questions Demandeur qui n'ont plus d'adresse.
     */
    public static function onQuestionPurge(Question $question): void
    {
        if (self::$internal_change || !self::hasTable()) {
            return;
        }

        $type = (string) ($question->fields['type'] ?? '');
        if ($type === QuestionTypeRequester::class) {
            global $DB;
            $DB->delete(self::TABLE, ['forms_questions_id' => (int) $question->fields['id']]);
            return;
        }
        if ($type !== QuestionTypeEmail::class) {
            return;
        }

        $section = Section::getById((int) ($question->fields['forms_sections_id'] ?? 0));
        if (!$section instanceof Section) {
            return;
        }
        $form     = $section->getForm();
        $analysis = self::analyse($form);
        foreach ($analysis['questions'] as $candidate) {
            if (!$candidate->getQuestionType() instanceof QuestionTypeRequester) {
                continue;
            }
            $stored = self::getStoredMandatory((int) $candidate->getID());
            if ($stored === null || self::linkedEmails($analysis, $candidate) !== []) {
                continue;
            }
            self::restoreMandatory($candidate, $stored, 'question Email ' . $question->fields['id'] . ' supprimée');
        }
    }

    /**
     * Désinstallation : chaque question Demandeur retrouve dans GLPI le choix « Obligatoire » mémorisé.
     */
    public static function restoreAllMandatory(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }
        // Ancienne table (sans colonnes d'options) : chacune de ses lignes venait de la case « E-mails ».
        $query = ['FROM' => self::TABLE];
        if ($DB->fieldExists(self::TABLE, 'use_email')) {
            $query['WHERE'] = ['use_email' => 1];
        }
        foreach ($DB->request($query) as $row) {
            $question = Question::getById((int) $row['forms_questions_id']);
            if ($question instanceof Question && (bool) $question->fields['is_mandatory'] !== (bool) $row['is_mandatory']) {
                self::updateQuestion($question, ['is_mandatory' => (int) $row['is_mandatory']]);
            }
        }
    }

    /** Question Email gérée par le plugin : reliée à une question Demandeur dont la case « E-mails » est cochée ? */
    private static function isManagedEmail(Question $email): bool
    {
        if (!self::hasTable()) {
            return false;
        }
        $analysis = self::analyse($email->getForm());
        foreach ($analysis['questions'] as $question) {
            if (
                $question->getQuestionType() instanceof QuestionTypeRequester
                && self::getStoredMandatory((int) $question->getID()) !== null
                && isset(self::linkedEmails($analysis, $question)[(int) $email->getID()])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Questions Email gérées par le plugin pour cette question Demandeur : seulement si sa case « E-mails » a été
     * cochée (choix « Obligatoire » mémorisé dans la table du plugin). Une question Email reliée à la main, sans la
     * case, reste une question ordinaire : le plugin n'y touche pas. Case cochée mais question E-mail disparue des
     * demandeurs : la question Demandeur retrouve son « Obligatoire » dans GLPI.
     *
     * @return array<int, Question>
     */
    private static function activeEmails(array $analysis, Question $requester): array
    {
        $stored = self::getStoredMandatory((int) $requester->getID());
        if ($stored === null) {
            return [];
        }

        $emails = self::linkedEmails($analysis, $requester);
        if ($emails === []) {
            self::restoreMandatory($requester, $stored, 'question E-mail plus reliée aux demandeurs');
        }

        return $emails;
    }

    /** La question Demandeur retrouve dans GLPI son choix « Obligatoire » ; le plugin l'oublie. */
    private static function restoreMandatory(Question $requester, bool $stored, string $reason): void
    {
        if ((bool) $requester->fields['is_mandatory'] !== $stored) {
            self::updateQuestion($requester, ['is_mandatory' => (int) $stored]);
        }
        self::forgetMandatory((int) $requester->getID());
        self::log('info', sprintf(
            'formulaire %d : %s, la question Demandeur %d retrouve « Obligatoire » : %s',
            $requester->getForm()->getID(),
            $reason,
            $requester->getID(),
            $stored ? 'oui' : 'non'
        ));
    }

    /** Choix « Obligatoire » de la question Demandeur : celui mémorisé par le plugin, sinon celui de GLPI. */
    private static function intendedMandatory(Question $requester): bool
    {
        return self::getStoredMandatory((int) $requester->getID()) ?? (bool) $requester->fields['is_mandatory'];
    }

    /** Table du plugin présente, avec ses colonnes d'options (sinon : plugin à réinstaller, options inactives). */
    private static function hasTable(): bool
    {
        global $DB;

        return $DB->tableExists(self::TABLE)
            && $DB->fieldExists(self::TABLE, 'use_entity')
            && $DB->fieldExists(self::TABLE, 'use_email');
    }

    /**
     * Options de la question Demandeur, ou null : aucune option cochée, le plugin ne touche pas à cette question.
     *
     * @return array{use_entity: bool, use_email: bool, is_mandatory: bool}|null
     */
    private static function getOptions(int $question_id): ?array
    {
        global $DB;

        if (!self::hasTable()) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['use_entity', 'use_email', 'is_mandatory'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['forms_questions_id' => $question_id],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row) || (!$row['use_entity'] && !$row['use_email'])) {
            return null;
        }

        return [
            'use_entity'   => (bool) $row['use_entity'],
            'use_email'    => (bool) $row['use_email'],
            'is_mandatory' => (bool) $row['is_mandatory'],
        ];
    }

    /** Enregistre les options ; aucune option cochée : la ligne est supprimée. */
    private static function saveOptions(int $question_id, bool $use_entity, bool $use_email, bool $is_mandatory): void
    {
        global $DB;

        if (!self::hasTable()) {
            throw new RuntimeException(
                'Plugin à réinstaller (Configuration → Plugins → Désinstaller, puis Installer et Activer) : sa table '
                . 'est absente ou ancienne.',
                409
            );
        }
        if (!$use_entity && !$use_email) {
            $DB->delete(self::TABLE, ['forms_questions_id' => $question_id]);
            return;
        }

        $values = [
            'use_entity'   => (int) $use_entity,
            'use_email'    => (int) $use_email,
            'is_mandatory' => (int) $is_mandatory,
            'date_mod'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
        $exists = count($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['forms_questions_id' => $question_id],
            'LIMIT'  => 1,
        ])) > 0;
        if ($exists) {
            $DB->update(self::TABLE, $values, ['forms_questions_id' => $question_id]);
        } else {
            $DB->insert(self::TABLE, ['forms_questions_id' => $question_id] + $values);
        }
    }

    /** Choix « Obligatoire » mémorisé par l'option e-mails, ou null si l'option e-mails n'est pas cochée. */
    private static function getStoredMandatory(int $question_id): ?bool
    {
        $options = self::getOptions($question_id);

        return $options !== null && $options['use_email'] ? $options['is_mandatory'] : null;
    }

    /** Option e-mails cochée avec ce choix « Obligatoire » (l'option liste par entité est conservée). */
    private static function storeMandatory(int $question_id, bool $mandatory): void
    {
        $options = self::getOptions($question_id);
        self::saveOptions($question_id, $options['use_entity'] ?? false, true, $mandatory);
    }

    /** Option e-mails décochée (l'option liste par entité est conservée). */
    private static function forgetMandatory(int $question_id): void
    {
        $options = self::getOptions($question_id);
        if ($options !== null) {
            self::saveOptions($question_id, $options['use_entity'], false, false);
        }
    }

    /**
     * @template T
     * @param callable(): T $change
     * @return T
     */
    private static function internally(callable $change): mixed
    {
        $previous = self::$internal_change;
        self::$internal_change = true;
        try {
            return $change();
        } finally {
            self::$internal_change = $previous;
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Journal
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Ligne de journal envoyée par le navigateur (erreur JavaScript, appel AJAX en échec…).
     */
    public static function logFromBrowser(string $level, string $message, string $context, string $page): void
    {
        $count = (int) ($_SESSION['plugin_formrequester_browser_logs'] ?? 0);
        if ($count >= self::MAX_BROWSER_LOGS) {
            return;
        }
        $_SESSION['plugin_formrequester_browser_logs'] = $count + 1;

        $level = in_array($level, ['error', 'warning', 'info'], true) ? $level : 'error';
        self::log($level, 'navigateur : ' . mb_substr($message, 0, 2000), [
            'page'    => mb_substr($page, 0, 300),
            'context' => mb_substr($context, 0, 4000),
        ]);
    }

    /**
     * Écrit une ligne dans files/_log/formrequester.log (date et utilisateur ajoutés par GLPI), même si la
     * journalisation générale de GLPI est désactivée.
     *
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $line = '[' . strtoupper($level) . '] ' . str_replace(["\r", "\n"], ' ', $message);
        $context = array_filter($context, static fn($value): bool => $value !== null && $value !== '' && $value !== []);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        Toolbox::logInFile(self::LOG_FILE, $line . "\n", true);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------------------------------------------------

    private static function loadForm(int $forms_id): Form
    {
        $form = Form::getById($forms_id);
        if (!$form instanceof Form) {
            throw new RuntimeException("Formulaire $forms_id introuvable", 404);
        }

        return $form;
    }

    /**
     * Lecture de la configuration native du formulaire : ses questions et, pour chaque destination ticket,
     * changement ou problème, les questions Demandeur, Email (demandeurs) et Entité qu'elle utilise.
     *
     * @return array{
     *     questions: array<int, Question>,
     *     destinations: array<int, array{item: FormDestination, requesters: array<int, Question>, emails: array<int, Question>, entities: array<int, Question>}>,
     *     warnings: array<int, string>
     * }
     */
    private static function analyse(Form $form): array
    {
        $questions = [];
        foreach ($form->getQuestions() as $question) {
            $questions[(int) $question->getID()] = $question;
        }

        $destinations = [];
        $warnings     = [];
        foreach ($form->getDestinations() as $destination) {
            if (!$destination->getConcreteDestinationItem() instanceof AbstractCommonITILFormDestination) {
                continue;
            }

            $entry = ['item' => $destination, 'requesters' => [], 'emails' => [], 'entities' => []];
            try {
                $config = $destination->getConfig();

                $requester_config = (new RequesterField())->getConfig($form, $config);
                if ($requester_config instanceof ITILActorFieldConfig) {
                    $strategies = $requester_config->getStrategies();
                    if (in_array(ITILActorFieldStrategy::SPECIFIC_ANSWERS, $strategies, true)) {
                        foreach ($requester_config->getSpecificQuestionIds() ?? [] as $question_id) {
                            $question = $questions[(int) $question_id] ?? null;
                            if ($question?->getQuestionType() instanceof QuestionTypeRequester) {
                                $entry['requesters'][(int) $question_id] = $question;
                            } elseif ($question?->getQuestionType() instanceof QuestionTypeEmail) {
                                $entry['emails'][(int) $question_id] = $question;
                            }
                        }
                    }
                    if (in_array(ITILActorFieldStrategy::LAST_VALID_ANSWER, $strategies, true)) {
                        foreach ($questions as $question_id => $question) {
                            if ($question->getQuestionType() instanceof QuestionTypeRequester) {
                                $entry['requesters'][$question_id] = $question;
                            }
                        }
                    }
                }

                $entity_config = (new EntityField())->getConfig($form, $config);
                if ($entity_config instanceof EntityFieldConfig) {
                    $strategy = $entity_config->getStrategy();
                    if ($strategy === EntityFieldStrategy::SPECIFIC_ANSWER) {
                        $question = $questions[(int) $entity_config->getSpecificQuestionId()] ?? null;
                        if ($question !== null && self::isEntityQuestion($question)) {
                            $entry['entities'][(int) $question->getID()] = $question;
                        }
                    } elseif ($strategy === EntityFieldStrategy::LAST_VALID_ANSWER) {
                        foreach ($questions as $question_id => $question) {
                            if (self::isEntityQuestion($question)) {
                                $entry['entities'][$question_id] = $question;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $warnings[] = sprintf(
                    'destination %d illisible (%s : %s)',
                    $destination->getID(),
                    get_class($e),
                    $e->getMessage()
                );
            }

            $destinations[] = $entry;
        }

        return ['questions' => $questions, 'destinations' => $destinations, 'warnings' => $warnings];
    }

    /**
     * Questions Email reliées aux demandeurs d'une destination qui utilise aussi cette question Demandeur.
     *
     * @return array<int, Question>
     */
    private static function linkedEmails(array $analysis, Question $requester): array
    {
        $emails = [];
        foreach ($analysis['destinations'] as $destination) {
            if (isset($destination['requesters'][(int) $requester->getID()])) {
                $emails += $destination['emails'];
            }
        }

        return $emails;
    }

    /**
     * Réglages qui empêcheraient l'envoi du formulaire. Deux rédactions : une pour l'éditeur (à lire par
     * l'administrateur, avec le nom des questions), une pour le journal (avec leurs numéros).
     *
     * @return array<int, string>
     */
    private static function emailProblems(Question $requester, Question $email, bool $for_editor = false): array
    {
        $problems = [];
        if ($requester->fields['is_mandatory']) {
            // Réglage fait à la main : le plugin reprend « Obligatoire » au prochain enregistrement du formulaire.
            $problems[] = $for_editor
                ? 'Cliquez sur « Enregistrer ». Tant que ce n\'est pas fait, un demandeur saisi seulement par son '
                    . 'adresse mail serait refusé. Votre choix « Obligatoire » est conservé.'
                : sprintf(
                    'la question Demandeur %d est obligatoire dans GLPI : une adresse mail seule sera refusée tant que '
                    . 'le formulaire n\'aura pas été enregistré (le plugin reprend alors « Obligatoire » à son compte)',
                    $requester->getID()
                );
        }
        if ($email->fields['is_mandatory']) {
            $problems[] = $for_editor
                ? sprintf(
                    'Cliquez sur « Enregistrer ». Tant que ce n\'est pas fait, la question « %s » reste obligatoire : '
                    . 'une adresse mail serait exigée même avec un demandeur choisi dans la liste.',
                    $email->fields['name']
                )
                : sprintf(
                    'la question Email %d est obligatoire : une adresse mail sera exigée même quand un demandeur est '
                    . 'choisi dans la liste ; enregistrer le formulaire la rend facultative',
                    $email->getID()
                );
        }

        return $problems;
    }

    private static function createEmailQuestion(Question $requester): Question
    {
        global $DB;

        // Placée en fin de section : elle n'est jamais affichée sur la page du formulaire.
        $section_id = (int) $requester->fields['forms_sections_id'];
        $rank = -1;
        foreach ([Question::getTable(), Comment::getTable()] as $table) {
            foreach ($DB->request(['SELECT' => ['vertical_rank'], 'FROM' => $table, 'WHERE' => ['forms_sections_id' => $section_id]]) as $row) {
                $rank = max($rank, (int) $row['vertical_rank']);
            }
        }

        $question = new Question();
        $id = $question->add([
            'forms_sections_id' => $section_id,
            'name'              => self::EMAIL_QUESTION_NAME,
            'description'       => self::EMAIL_QUESTION_DESCRIPTION,
            'type'              => QuestionTypeEmail::class,
            'is_mandatory'      => 0,
            'vertical_rank'     => $rank + 1,
        ]);
        if (!$id || !$question->getFromDB($id)) {
            throw new RuntimeException('Création de la question Email refusée par GLPI');
        }

        return $question;
    }

    /** @param array<string, mixed> $fields */
    private static function updateQuestion(Question $question, array $fields): void
    {
        $updated = self::internally(static fn() => (new Question())->update(['id' => $question->getID()] + $fields));
        if (!$updated) {
            throw new RuntimeException('Modification de la question ' . $question->getID() . ' refusée par GLPI');
        }
    }

    /**
     * Ajoute la question Email aux demandeurs de la destination (« Réponse à des questions spécifiques »), à côté de
     * la question Demandeur. Si la destination n'utilisait pas encore la question Demandeur (réglage par défaut : la
     * personne qui remplit le formulaire), ses demandeurs deviennent les réponses à Demandeur et Email.
     */
    private static function linkEmailToRequesters(
        Form $form,
        FormDestination $destination,
        int $requester_id,
        int $email_id,
        bool $requester_already_used
    ): void {
        $config  = $destination->getConfig();
        $current = (new RequesterField())->getConfig($form, $config);
        if (!$current instanceof ITILActorFieldConfig) {
            throw new RuntimeException('Configuration des demandeurs illisible pour la destination ' . $destination->getID());
        }

        $strategies = $requester_already_used ? $current->getStrategies() : [];
        // « Dernière réponse » ne garde qu'une réponse : il faut les questions spécifiques pour avoir les deux.
        $strategies = array_values(array_filter(
            $strategies,
            static fn(ITILActorFieldStrategy $strategy): bool => $strategy !== ITILActorFieldStrategy::LAST_VALID_ANSWER
        ));
        if (!in_array(ITILActorFieldStrategy::SPECIFIC_ANSWERS, $strategies, true)) {
            $strategies[] = ITILActorFieldStrategy::SPECIFIC_ANSWERS;
        }

        $question_ids = $requester_already_used ? array_map('intval', $current->getSpecificQuestionIds() ?? []) : [];
        $question_ids = array_values(array_unique(array_merge($question_ids, [$requester_id, $email_id])));

        self::saveRequesterConfig($destination, $config, new RequesterFieldConfig(
            strategies: $strategies,
            specific_itilactors_ids: $current->getSpecificITILActorsIds() ?? [],
            specific_question_ids: $question_ids,
        ));
    }

    /** @param array<int, int> $email_ids */
    private static function unlinkFromRequesters(Form $form, FormDestination $destination, array $email_ids): void
    {
        $config  = $destination->getConfig();
        $current = (new RequesterField())->getConfig($form, $config);
        if (!$current instanceof ITILActorFieldConfig) {
            return;
        }

        $question_ids = array_map('intval', $current->getSpecificQuestionIds() ?? []);
        $remaining    = array_values(array_diff($question_ids, array_map('intval', $email_ids)));
        if ($remaining === $question_ids) {
            return;
        }

        self::saveRequesterConfig($destination, $config, new RequesterFieldConfig(
            strategies: $current->getStrategies(),
            specific_itilactors_ids: $current->getSpecificITILActorsIds() ?? [],
            specific_question_ids: $remaining,
        ));
    }

    /**
     * Enregistre la configuration des demandeurs d'une destination. La configuration est déjà au format stocké :
     * elle passe par le chemin natif de l'import (« _from_import »), qui n'y applique pas la conversion des
     * formulaires HTML de l'éditeur.
     *
     * @param array<string, mixed> $config configuration complète de la destination
     */
    private static function saveRequesterConfig(FormDestination $destination, array $config, RequesterFieldConfig $requester_config): void
    {
        $config[RequesterField::getKey()] = $requester_config->jsonSerialize();

        $updated = (new FormDestination())->update([
            'id'           => $destination->getID(),
            'config'       => $config,
            '_from_import' => true,
        ]);
        if (!$updated) {
            throw new RuntimeException('Enregistrement de la destination ' . $destination->getID() . ' refusé par GLPI');
        }
    }

    /**
     * La question (par son uuid) sert-elle dans une condition d'affichage ou de validation du formulaire ?
     */
    private static function isUsedInConditions(Form $form, string $uuid): bool
    {
        global $DB;

        if ($uuid === '') {
            return false;
        }
        $like = ['LIKE', '%' . $uuid . '%'];

        $sections_ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Section::getTable(), 'WHERE' => ['forms_forms_id' => $form->getID()]]) as $row) {
            $sections_ids[] = (int) $row['id'];
        }

        $checks = [
            [Form::getTable(), ['id' => $form->getID(), 'submit_button_conditions' => $like]],
            [Section::getTable(), ['forms_forms_id' => $form->getID(), 'conditions' => $like]],
            [FormDestination::getTable(), ['forms_forms_id' => $form->getID(), 'conditions' => $like]],
        ];
        if ($sections_ids !== []) {
            $checks[] = [Question::getTable(), ['forms_sections_id' => $sections_ids, 'conditions' => $like]];
            $checks[] = [Question::getTable(), ['forms_sections_id' => $sections_ids, 'validation_conditions' => $like]];
            $checks[] = [Comment::getTable(), ['forms_sections_id' => $sections_ids, 'conditions' => $like]];
        }

        foreach ($checks as [$table, $where]) {
            if (count($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function findRequesterByUuid(Form $form, string $uuid): Question
    {
        $question = self::findQuestion(
            $form,
            static fn(Question $q): bool => $uuid !== '' && (string) $q->fields['uuid'] === $uuid
        );
        if (!$question?->getQuestionType() instanceof QuestionTypeRequester) {
            throw new RuntimeException('Question Demandeur introuvable : enregistrez d\'abord le formulaire.', 404);
        }

        return $question;
    }

    /** @param callable(Question): bool $match */
    private static function findQuestion(Form $form, callable $match): ?Question
    {
        foreach ($form->getQuestions() as $question) {
            if ($match($question)) {
                return $question;
            }
        }

        return null;
    }

    private static function isEntityQuestion(Question $question): bool
    {
        if (!$question->getQuestionType() instanceof QuestionTypeItem) {
            return false;
        }
        $config = $question->getExtraDataConfig();

        return $config instanceof QuestionTypeItemExtraDataConfig && $config->getItemtype() === Entity::class;
    }

    /**
     * La seule question candidate, ou null s'il n'y en a aucune ou plusieurs (signalé dans $warnings).
     *
     * @param array<int, Question> $candidates
     * @param array<int, string>   $warnings
     */
    private static function single(array $candidates, string $label, array &$warnings): ?Question
    {
        if (count($candidates) > 1) {
            $warnings[] = sprintf(
                'plusieurs questions %s (%s) : fonction désactivée pour ce formulaire',
                $label,
                implode(', ', array_keys($candidates))
            );
            return null;
        }

        return $candidates === [] ? null : reset($candidates);
    }

    /**
     * Incohérences de réglage d'un formulaire : écrites une seule fois par session, pas à chaque affichage.
     *
     * @param array<int, string> $warnings
     */
    private static function logWarningsOnce(Form $form, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }
        $key = 'plugin_formrequester_warned_' . (int) $form->getID() . '_' . md5(implode('|', $warnings));
        if (!empty($_SESSION[$key])) {
            return;
        }
        $_SESSION[$key] = true;
        foreach ($warnings as $warning) {
            self::log('warning', sprintf('formulaire %d : %s', $form->getID(), $warning));
        }
    }

    /** @return array<string, mixed> */
    private static function requestSummary(): array
    {
        $summary = [];
        foreach (array_merge($_GET, $_POST) as $key => $value) {
            if (in_array($key, ['_glpi_csrf_token', '_idor_token', 'url_parameters'], true)) {
                continue;
            }
            $summary[$key] = is_scalar($value) ? mb_substr((string) $value, 0, 200) : '[' . gettype($value) . ']';
        }

        return $summary;
    }

    /** @return array<int, string> */
    private static function shortTrace(Throwable $e): array
    {
        $lines = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $lines[] = sprintf(
                '%s%s%s() %s:%s',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '?',
                isset($frame['file']) ? basename($frame['file']) : '',
                $frame['line'] ?? ''
            );
        }

        return $lines;
    }
}
