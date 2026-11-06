/**
 * -------------------------------------------------------------------------
 * Options du champ Demandeur (formrequester) — plugin GLPI
 * -------------------------------------------------------------------------
 * Page d'un formulaire du Catalogue de services : la question « Demandeur » se comporte comme le champ Demandeur
 * d'un ticket :
 *  - sa liste est limitée à l'entité choisie dans la question « Entité » (mêmes utilisateurs et groupes que le
 *    ticket, profils récursifs des entités parentes compris) ;
 *  - choisir un demandeur sélectionne son entité dans la question « Entité » (même règle que le ticket) ;
 *  - une adresse mail tapée devient un demandeur par mail direct : elle est rangée dans la question « Email »
 *    reliée aux demandeurs de la destination, que le plugin masque.
 * Les champs natifs restent ceux que GLPI envoie : la liste d'origine est masquée et tenue à jour, la question
 * Email est remplie. Sans le plugin, le formulaire fonctionne comme avant.
 *
 * Éditeur du formulaire : case « E-mails » dans les types autorisés de chaque question Demandeur ; elle crée ou
 * supprime la question Email (côté serveur) puis recharge la page. « Obligatoire » garde son sens : avec les
 * adresses, il exige un demandeur ou une adresse (choix mémorisé par le plugin).
 *
 * Erreurs : console du navigateur (préfixe [formrequester]) et files/_log/formrequester.log.
 * Traces détaillées dans la console : localStorage.setItem('formrequester_debug', '1').
 */
(function () {
    'use strict';

    const PREFIX = '[formrequester]';
    const ACTOR_ID = /^(users_id|groups_id)-\d+$/;
    const HIDDEN_CLASS = 'formrequester-hidden';
    const MAX_LOGS_PER_PAGE = 20;
    const REQUESTER_TYPE = 'Glpi\\Form\\QuestionType\\QuestionTypeRequester';

    let logs_sent = 0;
    /** Questions déjà signalées au journal (une seule fois par page). */
    const warned_cards = new Set();

    function ajaxUrl(script) {
        return `${CFG_GLPI.root_doc}/plugins/formrequester/ajax/${script}`;
    }

    function isDebug() {
        try {
            return window.localStorage.getItem('formrequester_debug') === '1';
        } catch (e) {
            return false;
        }
    }

    function debug(...args) {
        if (isDebug()) {
            console.debug(PREFIX, ...args);
        }
    }

    /**
     * Erreur ou avertissement : console, et journal du serveur (files/_log/formrequester.log).
     */
    function log(level, message, context) {
        try {
            (level === 'error' ? console.error : console.warn)(PREFIX, message, context ?? '');
            if (logs_sent >= MAX_LOGS_PER_PAGE || typeof $ === 'undefined') {
                return;
            }
            logs_sent++;
            $.ajax({
                url: ajaxUrl('log.php'),
                type: 'POST',
                data: {
                    level: level,
                    message: String(message).slice(0, 2000),
                    context: JSON.stringify(context ?? {}).slice(0, 4000),
                    page: window.location.pathname + window.location.search,
                },
            });
        } catch (e) {
            // Le journal ne doit jamais provoquer d'erreur lui-même.
        }
    }

    function logException(where, error) {
        log('error', `${where} : ${error && error.message ? error.message : error}`, {
            stack: error && error.stack ? String(error.stack).slice(0, 1500) : '',
        });
    }

    function ajaxFailure(what) {
        return (xhr, status) => {
            if (status === 'abort') {
                return;
            }
            log('error', `${what} : HTTP ${xhr.status} ${status}`, {
                response: String(xhr.responseText || '').slice(0, 800),
            });
        };
    }

    /** Une fonction appelée par un événement ne doit jamais casser la page : l'erreur part au journal. */
    function guarded(where, fn) {
        return function (...args) {
            try {
                return fn.apply(this, args);
            } catch (e) {
                logException(where, e);
                return undefined;
            }
        };
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function dialogAlert(title, message) {
        if (typeof glpi_alert === 'function') {
            glpi_alert({title: escapeHtml(title), message: escapeHtml(message)});
        } else {
            (window.nativeAlert || window.alert)(`${title}\n\n${message}`);
        }
    }

    function dialogConfirm(title, message, on_confirm) {
        if (typeof glpi_confirm === 'function') {
            glpi_confirm({
                title: escapeHtml(title),
                message: escapeHtml(message),
                confirm_callback: guarded(title, on_confirm),
            });
        } else if ((window.nativeConfirm || window.confirm)(`${title}\n\n${message}`)) {
            guarded(title, on_confirm)();
        }
    }

    function pageParameters() {
        const parameters = {};
        new URLSearchParams(window.location.search).forEach((value, key) => {
            parameters[key] = value;
        });
        return parameters;
    }

    function waitFor(check, timeout_ms) {
        return new Promise((resolve) => {
            const started = Date.now();
            const tick = () => {
                const result = check();
                if (result) {
                    resolve(result);
                } else if (Date.now() - started > timeout_ms) {
                    resolve(null);
                } else {
                    window.setTimeout(tick, 100);
                }
            };
            tick();
        });
    }

    /** Retire la feuille de style du plugin : liste Demandeur et question E-mail de GLPI de nouveau visibles. */
    function releaseStyle() {
        document.querySelectorAll('link[href*="/formrequester/ajax/style.php"]').forEach((link) => link.remove());
    }

    function injectStyle() {
        if (document.getElementById('formrequester-style')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'formrequester-style';
        // « d-none » est géré par GLPI (étapes, conditions) : une classe à part pour ce que le plugin masque.
        style.textContent = `.${HIDDEN_CLASS} { display: none !important; }`;
        document.head.append(style);
    }

    // ============================================================================================================
    // Page du formulaire
    // ============================================================================================================

    function questionBlock(form, question_id) {
        return form.querySelector(
            `[data-glpi-form-renderer-id="${CSS.escape(String(question_id))}"][data-glpi-form-renderer-question]`
        );
    }

    function notifyGlpi(element) {
        // GLPI recalcule les conditions d'affichage du formulaire sur l'événement « input ».
        element.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function startRenderer() {
        const form = document.querySelector('form[data-glpi-form-render-layout]');
        if (!form) {
            return;
        }
        const forms_id = parseInt(form.querySelector('input[name="forms_id"]')?.value ?? '', 10);
        if (!forms_id) {
            return;
        }

        const url_parameters = pageParameters();
        $.ajax({
            url: ajaxUrl('config.php'),
            dataType: 'json',
            data: {forms_id: forms_id, url_parameters: url_parameters},
        })
            .done(guarded('Configuration du formulaire', (config) => {
                debug('configuration', config);
                if (config && config.requester) {
                    setupRenderer(form, forms_id, url_parameters, config)
                        .catch((error) => logException('Mise en place sur la page', error));
                }
            }))
            .fail(ajaxFailure('Lecture de la configuration du formulaire'));
    }

    async function setupRenderer(form, forms_id, url_parameters, config) {
        const block = questionBlock(form, config.requester);
        const original = block?.querySelector(
            `select[name="answers_${config.requester}[]"], select[name="answers_${config.requester}"]`
        );
        if (!block || !original) {
            log('warning', `Question Demandeur ${config.requester} introuvable sur la page`, {forms_id: forms_id});
            releaseStyle();
            return;
        }

        // La liste de GLPI est initialisée par son propre script : on attend qu'elle soit prête.
        const ready = await waitFor(() => $(original).hasClass('select2-hidden-accessible'), 10000);
        if (!ready) {
            log('warning', 'La liste Demandeur de GLPI ne s\'est pas initialisée', {forms_id: forms_id});
            releaseStyle();
            return;
        }

        const entity_block = config.entity ? questionBlock(form, config.entity) : null;
        const entity_select = entity_block?.querySelector(`select[name^="answers_${config.entity}[items_id"]`) ?? null;
        if (config.entity && !entity_select) {
            log('warning', `Question Entité ${config.entity} introuvable sur la page`, {forms_id: forms_id});
        }

        const email_block = config.email ? questionBlock(form, config.email) : null;
        const email_input = email_block?.querySelector(`input[name="answers_${config.email}"]`) ?? null;
        if (config.email && !email_input) {
            log('warning', `Question Email ${config.email} introuvable sur la page`, {forms_id: forms_id});
        }
        const email_enabled = email_input !== null;
        const email_regex = new RegExp(config.email_pattern, 'u');

        injectStyle();

        // --- Liste affichée par le plugin, à la place de celle de GLPI (masquée mais toujours envoyée) ---
        const original_container = $(original).data('select2')?.$container ?? $(original).next('.select2-container');
        original_container.addClass(HIDDEN_CLASS);

        const select = document.createElement('select');
        select.className = 'form-select';
        select.multiple = Boolean(config.multiple);

        // Filet de sécurité : en cas d'erreur, le plugin s'efface et le champ Demandeur de GLPI reprend la main ;
        // la question E-mail redevient une question ordinaire, visible (l'adresse reste possible).
        let native_restored = false;
        let required = false;
        let star_node = null;
        function restoreNative(reason) {
            if (native_restored) {
                return;
            }
            native_restored = true;
            log('error', `Champ Demandeur rendu à GLPI : ${reason}`, {forms_id: forms_id});
            required = false;
            star_node?.remove();
            block.querySelectorAll('.formrequester-message').forEach((node) => node.remove());
            try {
                $(select).select2('close');
                $(select).select2('destroy');
            } catch (e) {
                // Liste déjà détruite.
            }
            select.remove();
            original_container.removeClass(HIDDEN_CLASS);
            releaseStyle();
            email_block?.classList.remove(HIDDEN_CLASS);
        }
        select.setAttribute('aria-label', block.querySelector('h3')?.textContent.trim() ?? '');
        original_container.after(select);

        // Valeurs déjà présentes (valeur par défaut de la question, retour arrière du navigateur…)
        if (!select.multiple) {
            select.append(new Option('', '', false, false));
        }
        for (const option of original.selectedOptions) {
            if (option.value !== '') {
                select.append(new Option(option.text, option.value, true, true));
            }
        }
        if (email_enabled && email_input.value.trim() !== '') {
            select.append(new Option(email_input.value.trim(), email_input.value.trim(), true, true));
        }

        if (email_block) {
            email_block.classList.add(HIDDEN_CLASS);
        }

        const currentEntity = () => {
            if (!entity_select) {
                return -1;
            }
            let value = $(entity_select).val();
            if (Array.isArray(value)) {
                value = value[0];
            }
            const id = parseInt(value ?? '', 10);
            return Number.isInteger(id) && id >= 0 ? id : -1;
        };

        $(select).select2({
            width: '100%',
            dropdownAutoWidth: true,
            minimumInputLength: 0,
            placeholder: select.multiple ? '' : '-----',
            allowClear: !select.multiple,
            tags: email_enabled,
            tokenSeparators: email_enabled ? [',', ';', ' '] : [],
            createTag: (params) => {
                const term = $.trim(params.term);
                // Comme dans le ticket : seule une adresse mail valide peut être ajoutée à la main.
                if (term === '' || !email_regex.test(term)) {
                    return null;
                }
                return {id: term, text: term, newTag: true};
            },
            templateResult: renderActor,
            templateSelection: renderActor,
            ajax: {
                url: ajaxUrl('search.php'),
                dataType: 'json',
                delay: 250,
                data: (params) => ({
                    forms_id: forms_id,
                    question: config.requester,
                    entity: currentEntity(),
                    searchText: params.term || '',
                    page: params.page || 1,
                    url_parameters: url_parameters,
                }),
                processResults: (data) => ({
                    results: Array.isArray(data?.results) ? data.results : [],
                    pagination: {more: Boolean(data?.more)},
                }),
                transport: (params, success, failure) => {
                    const request = $.ajax(params);
                    request.done(success);
                    request.fail((xhr, status) => {
                        ajaxFailure('Recherche des demandeurs')(xhr, status);
                        failure();
                        if (status !== 'abort') {
                            window.setTimeout(() => restoreNative(`recherche des demandeurs en échec (HTTP ${xhr.status})`), 0);
                        }
                    });
                    return request;
                },
            },
        });

        const emailsOf = (data) => data.filter((item) => item.id !== '' && !ACTOR_ID.test(String(item.id)));

        function showMessage(text) {
            clearMessage();
            const container = $(select).data('select2')?.$container;
            container?.addClass('is-invalid');
            const message = document.createElement('div');
            message.className = 'invalid-feedback d-block formrequester-message';
            message.textContent = text;
            (container?.get(0) ?? select).after(message);
        }

        function clearMessage() {
            $(select).data('select2')?.$container?.removeClass('is-invalid');
            block.querySelectorAll('.formrequester-message').forEach((node) => node.remove());
        }

        // Une seule question Email transporte l'adresse : une seule adresse par formulaire.
        $(select).on('select2:selecting', guarded('Ajout d\'un demandeur', (event) => {
            const item = event.params?.args?.data;
            if (!select.multiple || !item || ACTOR_ID.test(String(item.id))) {
                return;
            }
            if (emailsOf($(select).select2('data')).length >= 1) {
                event.preventDefault();
                showMessage('Une seule adresse mail peut être ajoutée comme demandeur.');
            }
        }));

        const sync = () => {
            const data = $(select).select2('data');
            const actors = data.filter((item) => ACTOR_ID.test(String(item.id)));
            const emails = emailsOf(data);

            // Liste native de GLPI : utilisateurs et groupes seulement, au format users_id-12 / groups_id-3.
            $(original).empty();
            for (const actor of actors) {
                original.append(new Option(actor.text, actor.id, true, true));
            }
            $(original).trigger('change');
            notifyGlpi(original);

            if (email_enabled) {
                email_input.value = emails.length > 0 ? String(emails[0].id) : '';
                notifyGlpi(email_input);
            }

            clearMessage();
            debug('synchronisation', {actors: actors.map((a) => a.id), emails: emails.map((e) => e.id)});
        };

        $(select).on('change', () => {
            if (native_restored) {
                return;
            }
            try {
                sync();
            } catch (error) {
                logException('Synchronisation du demandeur', error);
                restoreNative('synchronisation en échec');
            }
        });

        // Choisir un utilisateur sélectionne son entité, comme dans le formulaire de ticket.
        $(select).on('select2:select', guarded('Entité du demandeur', (event) => {
            const item = event.params?.data;
            if (!entity_select || !item || !String(item.id).startsWith('users_id-')) {
                return;
            }
            const users_ids = $(select).select2('data')
                .map((entry) => String(entry.id))
                .filter((id) => id.startsWith('users_id-'))
                .map((id) => id.slice('users_id-'.length));
            $.ajax({
                url: ajaxUrl('entity.php'),
                dataType: 'json',
                data: {forms_id: forms_id, users_ids: users_ids, current: currentEntity(), url_parameters: url_parameters},
            })
                .done(guarded('Entité du demandeur', (response) => {
                    debug('entité du demandeur', response);
                    if (response && response.entities_id !== null && response.entities_id !== currentEntity()) {
                        setEntity(entity_select, response.entities_id, response.name);
                    }
                }))
                .fail(ajaxFailure('Calcul de l\'entité du demandeur'));
        }));

        // --- « Obligatoire » avec les adresses mail : un demandeur ou une adresse ---
        // La case native reste décochée dans GLPI (sinon une adresse seule serait refusée) : le choix de l'éditeur est
        // mémorisé par le plugin, qui affiche l'astérisque et fait le contrôle avant l'envoi.
        required = email_enabled && Boolean(config.required);
        if (required && !config.requester_mandatory) {
            const title = block.querySelector('h3');
            if (title && !title.querySelector('.text-danger')) {
                star_node = document.createElement('span');
                star_node.className = 'text-danger';
                star_node.textContent = ' *';
                title.append(star_node);
            }
        }

        // Phase de capture sur le formulaire : passe avant le gestionnaire de GLPI posé sur le bouton.
        form.addEventListener('click', guarded('Contrôle avant envoi', (event) => {
            if (!required) {
                return;
            }
            const button = event.target.closest(
                '[data-glpi-form-renderer-action="submit"], [data-glpi-form-renderer-action="next-section"]'
            );
            // Question masquée (autre étape, condition d'affichage) : rien à contrôler.
            if (!button || block.offsetParent === null) {
                return;
            }
            const has_requester = $(select).select2('data').some((item) => item.id !== '');
            if (!has_requester) {
                event.preventDefault();
                event.stopPropagation();
                showMessage('Choisissez un demandeur ou saisissez son adresse mail.');
                block.scrollIntoView({block: 'center'});
            }
        }), true);

        // Une erreur de GLPI sur la question Email masquée (adresse refusée, question restée obligatoire…) est
        // réaffichée sous « Demandeur » : sinon personne ne la verrait.
        if (email_block) {
            new MutationObserver(guarded('Erreur de la question Email', () => {
                const tooltip = email_block.querySelector('.invalid-tooltip');
                if (tooltip && tooltip.textContent.trim() !== '') {
                    showMessage(`Adresse mail : ${tooltip.textContent.trim()}`);
                    block.scrollIntoView({block: 'center'});
                }
            })).observe(email_block, {childList: true, subtree: true});
        }

        debug('prêt', {requester: config.requester, entity: config.entity, email: config.email});
    }

    function setEntity(entity_select, entities_id, name) {
        const $entity = $(entity_select);
        const value = String(entities_id);
        if ($entity.find('option').filter((index, option) => option.value === value).length === 0) {
            entity_select.append(new Option(name || value, value, false, false));
        }
        $entity.val(entity_select.multiple ? [value] : value).trigger('change');
        notifyGlpi(entity_select);
    }

    function renderActor(item) {
        if (item.loading || item.id === undefined || item.id === '') {
            return item.text;
        }
        const id = String(item.id);
        let icon = 'ti-mail';
        if (id.startsWith('users_id-')) {
            icon = 'ti-user';
        } else if (id.startsWith('groups_id-')) {
            icon = 'ti-users';
        }
        const entry = $('<span class="actor_entry"></span>');
        entry.append($('<i class="ti mx-1" aria-hidden="true"></i>').addClass(icon).attr('title', item.title || item.text));
        entry.append(document.createTextNode(item.text));
        return entry;
    }

    // ============================================================================================================
    // Éditeur du formulaire : case « E-mails » des types autorisés de la question Demandeur
    // ============================================================================================================

    /**
     * L'éditeur est l'onglet principal de la fiche du formulaire : GLPI le charge en AJAX après la page, et le
     * recharge quand on revient sur l'onglet. Chaque éditeur qui apparaît est donc pris en charge à son arrivée.
     * Surveillance limitée à la fiche d'un formulaire, pour ne rien coûter aux autres pages.
     */
    function watchEditors() {
        if (!window.location.pathname.includes('/front/form/form.form.php')) {
            return;
        }
        const init = guarded('Recherche de l\'éditeur', () => {
            for (const container of document.querySelectorAll('[data-glpi-form-editor-container]:not([data-formrequester-ready])')) {
                container.dataset.formrequesterReady = '1';
                startEditor(container);
            }
        });
        init();
        let timer = null;
        new MutationObserver(() => {
            window.clearTimeout(timer);
            timer = window.setTimeout(init, 200);
        }).observe(document.body, {childList: true, subtree: true});
    }

    function startEditor(container) {
        // Identifiant du formulaire : le champ « id » qui n'appartient ni à une section ni à une question.
        const id_input = Array.from(container.querySelectorAll('input[name="id"], input[data-glpi-form-editor-original-name="id"]'))
            .find((input) => !input.closest('[data-glpi-form-editor-block], [data-glpi-form-editor-section]'));
        const forms_id = parseInt(id_input?.value ?? '', 10);
        if (!forms_id) {
            return;
        }

        const state = {status: null, version: 0};
        let timer = null;
        const observer = new MutationObserver(() => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => render(), 300);
        });

        const render = guarded('Affichage de la case E-mails', () => {
            observer.disconnect();
            try {
                renderEditor(container, forms_id, state);
            } finally {
                observer.observe(container, {childList: true, subtree: true});
            }
        });

        const refresh = () => {
            $.ajax({url: ajaxUrl('editor.php'), dataType: 'json', data: {forms_id: forms_id}})
                .done(guarded('État de l\'éditeur', (response) => {
                    debug('éditeur', response);
                    state.status = response;
                    state.version++;
                    render();
                }))
                .fail(ajaxFailure('Lecture de l\'état de l\'éditeur'));
        };

        state.refresh = refresh;

        // Après chaque enregistrement du formulaire : les nouvelles questions Demandeur ont un état, et le choix
        // « Obligatoire » mémorisé par le plugin est relu.
        $(container).on('glpi-ajax-controller-submit-success', () => refresh());
        refresh();
    }

    function renderEditor(container, forms_id, state) {
        const status = state.status;
        const email_uuids = new Set(status?.email_uuids ?? []);

        for (const card of container.querySelectorAll('[data-glpi-form-editor-question]')) {
            // Champs cachés propres à la question, au premier niveau de ses détails (pas ceux d'autres parties).
            const details = card.querySelector(':scope > [data-glpi-form-editor-question-details]') ?? card;
            const uuid = editorField(details, 'uuid', ':scope > ')?.value ?? '';
            const type = editorField(details, 'type', ':scope > ')?.value ?? '';
            const mandatory_input = editorField(card, 'is_mandatory', '', '[type="checkbox"]');
            const mandatory_label = mandatory_input?.closest('label') ?? null;
            // Types autorisés (fenêtre de la roue dentée) : la ligne de la case « Utilisateurs »
            // (« enabled_types[User] », QuestionTypeActorsExtraDataConfig::ENABLED_TYPES).
            const types_row = editorField(card, 'enabled_types[User]', '', '[type="checkbox"]')
                ?.closest('label')?.parentElement ?? null;
            // Écran de GLPI changé (mise à jour) : signalé une fois au journal plutôt que de rester muet.
            if (type === REQUESTER_TYPE && !types_row && !warned_cards.has(uuid)) {
                warned_cards.add(uuid);
                log('warning', 'Types autorisés introuvables sur une question Demandeur de l\'éditeur', {forms_id: forms_id, uuid: uuid});
            }

            renderEmailBadge(card, email_uuids.has(uuid));

            let email_label = card.querySelector('[data-formrequester-email-type]');
            if (type !== REQUESTER_TYPE || !types_row) {
                email_label?.remove();
                card.querySelector('[data-formrequester-entity-mode]')?.remove();
                removeWithTooltip(card.querySelector('[data-formrequester-problems]'));
                if (email_uuids.has(uuid) && mandatory_input) {
                    // Question E-mail gérée par le plugin : jamais affichée, donc toujours facultative (le serveur la
                    // décoche aussi à l'enregistrement). Encore cochée (réglage fait à la main) : décochée ici, et
                    // l'éditeur signale qu'il reste à enregistrer.
                    if (mandatory_input.checked) {
                        mandatory_input.checked = false;
                        if (typeof setHasUnsavedChanges === 'function') {
                            setHasUnsavedChanges(true);
                        }
                    }
                    lockMandatory(mandatory_input, 'Géré par la case « E-mails » de la question Demandeur : cette question reste toujours facultative.');
                } else {
                    unlockMandatory(mandatory_input);
                }
                continue;
            }

            // Carte dupliquée par l'éditeur : sa case appartient à l'autre question.
            if (email_label && email_label.dataset.uuid !== uuid) {
                email_label.remove();
                email_label = null;
            }
            if (!email_label) {
                email_label = createEmailType(forms_id, uuid);
                types_row.append(email_label);
            }

            const input = email_label.querySelector('input');
            const entry = status?.questions?.[uuid] ?? null;
            const enabled = Boolean(entry?.email);
            const installed = entry === null || entry.installed !== false;

            input.checked = enabled;
            input.disabled = entry === null || !installed;
            email_label.title = entry === null ? 'Enregistrez le formulaire pour pouvoir autoriser les adresses mail.' : '';

            // Option « Liste par entité, comme dans un ticket », dans la partie Format de la roue dentée.
            renderEntityMode(card, forms_id, uuid, entry, installed, state);

            // Avec les adresses, la case « Obligatoire » affiche le choix mémorisé par le plugin (la case native reste
            // décochée dans GLPI) et un clic dessus est enregistré aussitôt par le plugin. Appliqué une fois par lecture
            // de l'état et par case (GLPI peut redessiner la case).
            if (mandatory_input && entry !== null) {
                bindMandatory(mandatory_input, forms_id, uuid, state);
                if (enabled && mandatory_input.dataset.formrequesterVersion !== String(state.version)) {
                    mandatory_input.dataset.formrequesterVersion = String(state.version);
                    mandatory_input.checked = Boolean(entry.mandatory);
                }
            }
            if (mandatory_label) {
                if (enabled) {
                    mandatory_label.title = 'Avec les e-mails : « Obligatoire » exige un demandeur ou une adresse mail.';
                } else {
                    mandatory_label.removeAttribute('title');
                }
            }

            const problems = !installed
                ? ['Plugin à réinstaller : Configuration → Plugins → Désinstaller, puis Installer et Activer. Ses options '
                    + 'restent inactives d\'ici là.']
                : (enabled ? entry.problems ?? [] : []);
            renderProblems(card, problems);
        }
    }

    /** Cases « Obligatoire » déjà écoutées (une case dupliquée par l'éditeur n'hérite pas de l'écoute). */
    const bound_mandatory = new WeakSet();

    /**
     * Clic sur « Obligatoire » d'une question Demandeur dont la case « E-mails » est cochée : enregistré aussitôt par
     * le plugin. Sans les e-mails, la case reste celle de GLPI (enregistrée avec le formulaire).
     */
    function bindMandatory(input, forms_id, uuid, state) {
        if (bound_mandatory.has(input)) {
            return;
        }
        bound_mandatory.add(input);

        input.addEventListener('change', guarded('Obligatoire avec les e-mails', () => {
            if (!state.status?.questions?.[uuid]?.email) {
                return;
            }
            const wanted = input.checked;
            $.ajax({
                url: ajaxUrl('editor.php'),
                type: 'POST',
                dataType: 'json',
                data: {forms_id: forms_id, uuid: uuid, action: wanted ? 'mandatory_on' : 'mandatory_off'},
            })
                .done(() => state.refresh?.())
                .fail((xhr, status) => {
                    input.checked = !wanted;
                    if (xhr.status === 0 || xhr.status >= 500) {
                        ajaxFailure('Obligatoire avec les e-mails')(xhr, status);
                    }
                    dialogAlert(
                        'Réglage non appliqué',
                        xhr.responseJSON?.error ?? 'Erreur inattendue, voir files/_log/formrequester.log.'
                    );
                });
        }));
    }

    /**
     * Option « Liste par entité, comme dans un ticket » : un interrupteur sous « Autoriser plusieurs acteurs », dans
     * la partie Format. Simple réglage du plugin : enregistré aussitôt, sans recharger la page.
     */
    function renderEntityMode(card, forms_id, uuid, entry, installed, state) {
        const multiple_input = editorField(card, 'is_multiple_actors', '', '[type="checkbox"]');
        const format_section = multiple_input?.closest('label')?.parentElement?.parentElement ?? null;
        let row = card.querySelector('[data-formrequester-entity-mode]');
        if (!format_section) {
            row?.remove();
            return;
        }
        if (row && row.dataset.uuid !== uuid) {
            row.remove();
            row = null;
        }
        if (!row) {
            row = createEntityMode(forms_id, uuid, state);
            format_section.append(row);
        }
        const input = row.querySelector('input');
        input.checked = Boolean(entry?.entity_mode);
        input.disabled = entry === null || !installed;
        row.title = entry === null ? 'Enregistrez le formulaire pour pouvoir activer cette option.' : '';
    }

    function createEntityMode(forms_id, uuid, state) {
        const row = document.createElement('div');
        row.className = 'mt-2';
        row.dataset.formrequesterEntityMode = '';
        row.dataset.uuid = uuid;

        const label = document.createElement('label');
        label.className = 'form-check form-switch mb-0';

        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = 'checkbox';
        // Pas un champ de la question : ni enregistré par l'éditeur, ni compté comme modification en cours.
        input.dataset.trackChanges = '';

        const text = document.createElement('span');
        text.className = 'form-check-label';
        text.append(document.createTextNode('Liste par entité, comme dans un ticket'));

        const info = document.createElement('i');
        info.className = 'ti ti-info-circle ms-1 text-muted';
        info.setAttribute('role', 'img');
        info.setAttribute('aria-label', 'Explication');
        glpiTooltip(info, 'Cochée : la liste ne propose que les personnes de l\'entité choisie dans le formulaire, et '
            + 'choisir un demandeur remplit l\'entité. Décochée : la liste habituelle de GLPI.');
        text.append(info);

        label.append(input, text);
        row.append(label);

        input.addEventListener('change', guarded('Option liste par entité', () => {
            const wanted = input.checked;
            input.disabled = true;
            $.ajax({
                url: ajaxUrl('editor.php'),
                type: 'POST',
                dataType: 'json',
                data: {forms_id: forms_id, uuid: uuid, action: wanted ? 'entity_on' : 'entity_off'},
            })
                .done(() => {
                    input.disabled = false;
                    state.refresh?.();
                })
                .fail((xhr, status) => {
                    input.checked = !wanted;
                    input.disabled = false;
                    if (xhr.status === 0 || xhr.status >= 500) {
                        ajaxFailure('Option liste par entité')(xhr, status);
                    }
                    dialogAlert(
                        'Réglage non appliqué',
                        xhr.responseJSON?.error ?? 'Erreur inattendue, voir files/_log/formrequester.log.'
                    );
                });
        }));

        return row;
    }

    /**
     * Champ de l'éditeur par son nom d'origine. L'éditeur de GLPI renomme les champs des questions
     * (« uuid » devient « _questions[1][uuid] ») et garde l'ancien nom dans data-glpi-form-editor-original-name :
     * même recherche que l'éditeur lui-même.
     */
    function editorField(scope, name, prefix = '', extra = '') {
        const escaped = CSS.escape(name);
        return scope.querySelector(`${prefix}input${extra}[data-glpi-form-editor-original-name="${escaped}"]`)
            ?? scope.querySelector(`${prefix}input${extra}[name="${escaped}"]`);
    }

    function createEmailType(forms_id, uuid) {
        const label = document.createElement('label');
        label.className = 'form-check form-check-inline mb-0 text-nowrap';
        label.dataset.formrequesterEmailType = '';
        label.dataset.uuid = uuid;

        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = 'checkbox';
        // Pas un champ de la question : ni enregistré par l'éditeur, ni compté comme modification en cours.
        input.dataset.trackChanges = '';

        // Libellé comme ceux d'Utilisateurs et Groupes (icône, nom), avec le ⓘ sur la même ligne.
        const text = document.createElement('span');
        text.className = 'form-check-label';
        text.innerHTML = '<i class="ti ti-mail" aria-hidden="true"></i> ';
        text.append(document.createTextNode('E-mails'));

        const info = document.createElement('i');
        info.className = 'ti ti-info-circle ms-1 text-muted';
        info.setAttribute('role', 'img');
        info.setAttribute('aria-label', 'Explication');
        glpiTooltip(info, 'Cochée : dans « Demandeur », on peut aussi taper l\'adresse mail d\'une personne qui '
            + 'n\'existe pas dans GLPI ; elle est ajoutée au ticket comme demandeur. Décochée : seulement des '
            + 'utilisateurs et des groupes, comme d\'habitude.');
        text.append(info);

        label.append(input, text);

        input.addEventListener('change', guarded('Case E-mails', () => {
            const wanted = input.checked;
            // L'état ne change qu'après l'action du serveur et le rechargement de la page.
            input.checked = !wanted;

            if (typeof hasUnsavedChanges === 'function' && hasUnsavedChanges()) {
                dialogAlert(
                    'Enregistrez d\'abord le formulaire',
                    'Ce réglage modifie le formulaire puis recharge la page : enregistrez vos modifications en cours '
                        + 'avant de le changer.'
                );
                return;
            }

            const title = wanted ? 'Autoriser les adresses mail' : 'Retirer les adresses mail';
            const message = wanted
                ? 'Une question « E-mail du demandeur » va être créée (ou, s\'il y en a déjà une, la question Email reliée '
                    + 'aux demandeurs du ticket sera réutilisée), jamais affichée aux utilisateurs. Le réglage '
                    + '« Obligatoire » est conservé : il exigera un demandeur ou une adresse mail. La page va se recharger.'
                : 'La question qui reçoit l\'adresse mail va être supprimée ; « Obligatoire » est conservé tel quel. '
                    + 'La page va se recharger.';

            dialogConfirm(title, message, () => {
                input.disabled = true;
                $.ajax({
                    url: ajaxUrl('editor.php'),
                    type: 'POST',
                    dataType: 'json',
                    data: {forms_id: forms_id, uuid: uuid, action: wanted ? 'enable' : 'disable'},
                })
                    .done(() => {
                        if (typeof setHasUnsavedChanges === 'function') {
                            setHasUnsavedChanges(false);
                        }
                        window.location.reload();
                    })
                    .fail((xhr, status) => {
                        input.disabled = false;
                        // Le serveur a déjà écrit les refus (409…) dans le journal ; ici, les pannes seulement.
                        if (xhr.status === 0 || xhr.status >= 500) {
                            ajaxFailure('Case E-mails')(xhr, status);
                        }
                        dialogAlert(
                            'Réglage non appliqué',
                            xhr.responseJSON?.error ?? 'Erreur inattendue, voir files/_log/formrequester.log.'
                        );
                    });
            });
        }));

        return label;
    }

    function lockMandatory(input, title) {
        if (!input) {
            return;
        }
        input.disabled = true;
        input.dataset.formrequesterLocked = '1';
        input.closest('label')?.setAttribute('title', title);
    }

    function unlockMandatory(input) {
        if (!input || input.dataset.formrequesterLocked !== '1') {
            return;
        }
        input.disabled = false;
        delete input.dataset.formrequesterLocked;
        input.closest('label')?.removeAttribute('title');
    }

    /**
     * Réglage à corriger : un panneau ⚠ dans l'en-tête de la question, juste avant son menu ⋮ (visible que la
     * question soit ouverte ou fermée, là où GLPI place ses propres avertissements) ; le message dans la bulle de
     * GLPI.
     */
    function renderProblems(card, problems) {
        let node = card.querySelector('[data-formrequester-problems]');
        const text = problems.join(' ');
        const header = card.querySelector('[data-glpi-form-editor-question-details-name]')?.parentElement ?? null;
        if (problems.length === 0 || !header) {
            removeWithTooltip(node);
            return;
        }
        if (node && node.dataset.text === text && node.parentElement === header) {
            return;
        }
        removeWithTooltip(node);
        node = document.createElement('i');
        node.className = 'ti ti-alert-triangle text-warning fs-2 ms-3';
        node.dataset.formrequesterProblems = '';
        node.dataset.text = text;
        node.setAttribute('role', 'img');
        node.setAttribute('aria-label', text);
        glpiTooltip(node, text);
        const menu = header.querySelector(':scope > .dropdown');
        if (menu) {
            menu.before(node);
        } else {
            header.append(node);
        }
    }

    /**
     * Bulle de GLPI (Bootstrap), avec les réglages de son initTooltips() ; à défaut, la bulle du navigateur.
     */
    function glpiTooltip(element, text) {
        element.setAttribute('data-bs-toggle', 'tooltip');
        element.setAttribute('title', text);
        try {
            if (window.bootstrap?.Tooltip) {
                new window.bootstrap.Tooltip(element, {delay: {show: 50, hide: 50}, placement: 'auto', trigger: 'hover'});
            }
        } catch (e) {
            // La bulle du navigateur (attribut title) reste.
        }
    }

    function removeWithTooltip(element) {
        if (!element) {
            return;
        }
        try {
            window.bootstrap?.Tooltip?.getInstance(element)?.dispose();
        } catch (e) {
            // Rien à fermer.
        }
        element.remove();
    }

    function renderEmailBadge(card, is_email_question) {
        let badge = card.querySelector('[data-formrequester-badge]');
        if (!is_email_question) {
            if (badge) {
                removeWithTooltip(badge.querySelector('[data-bs-toggle="tooltip"]'));
                badge.remove();
            }
            return;
        }
        if (badge) {
            return;
        }
        // En bas de la carte : en haut, il recouvrirait la poignée de déplacement de la question. Texte court,
        // explication complète au survol du ⓘ.
        badge = document.createElement('div');
        badge.className = 'text-muted small mt-2';
        badge.dataset.formrequesterBadge = '';
        badge.innerHTML = '<i class="ti ti-mail me-1" aria-hidden="true"></i>';
        badge.append(document.createTextNode('Ne pas supprimer : gérée par « E-mails » (question Demandeur).'));

        const info = document.createElement('i');
        info.className = 'ti ti-info-circle ms-1';
        info.setAttribute('role', 'img');
        info.setAttribute('aria-label', 'Explication');
        glpiTooltip(info, 'Question cachée aux utilisateurs : le plugin y range l\'adresse mail tapée dans '
            + '« Demandeur ». Ne la supprimez pas ici : pour ne plus accepter les adresses mail, décochez « E-mails » '
            + 'dans la roue dentée de la question Demandeur.');
        badge.append(info);

        card.querySelector('.card-body')?.append(badge);
    }

    // ============================================================================================================

    window.addEventListener('error', (event) => {
        if (String(event.filename || '').includes('/formrequester/')) {
            log('error', `Erreur JavaScript : ${event.message}`, {where: `${event.filename}:${event.lineno}:${event.colno}`});
        }
    });

    if (typeof $ === 'function') {
        $(guarded('Démarrage', () => {
            startRenderer();
            watchEditors();
        }));
    }
})();
