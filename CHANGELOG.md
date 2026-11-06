# Journal des changements

## 1.1.0 — 2026-11-06

Première version pour GLPI 12 (12.0.x). La série 1.0.x reste celle de GLPI 11 (branche `GLPI_11`). Version bêta. Nom affiché : « Options du champ Demandeur (Catalogue de services) » (clé `formrequester`).


- Deux options par question Demandeur, décochées par défaut : « Liste par entité, comme dans un ticket » (partie Format)
  et « E-mails » (types autorisés). Aucune option : le plugin ne fait rien sur le formulaire.
- Question « Demandeur » des formulaires du Catalogue de services : liste limitée à l'entité choisie (même liste
  que le champ Demandeur d'un ticket), entité sélectionnée d'après le demandeur (même règle que le ticket),
  adresse mail acceptée pour un demandeur absent de GLPI (rangée dans la question Email reliée aux demandeurs).
- Éditeur du formulaire : case « E-mails » dans les types autorisés de la question Demandeur. Cochée : création
  de la question Email cachée, reliée aux demandeurs des destinations. Décochée : question Email supprimée.
- « Obligatoire » garde son sens avec les e-mails (un demandeur ou une adresse) : choix mémorisé par le plugin
  (table `glpi_plugin_formrequester_requesters`), case native de GLPI gardée décochée, contrôle fait sur la page.
  GLPI retrouve ce choix en décochant « E-mails », en supprimant la question E-mail ou en désinstallant le plugin.
- Réglage qui empêcherait l'envoi signalé dans l'éditeur et dans le journal ; erreur de GLPI sur la question Email
  masquée réaffichée sous « Demandeur ».
- Sans effet hors des questions Demandeur ; adresses mail seulement avec la case « E-mails » ; en cas d'erreur, retour au champ
  natif de GLPI ; question E-mail masquée dès l'affichage (feuille de style dans l'en-tête).
- Journal `files/_log/formrequester.log` (erreurs du serveur et du navigateur, actions de la case E-mails), traces
  détaillées dans la console à la demande.
- Une table : `glpi_plugin_formrequester_requesters` (options cochées, choix « Obligatoire »).
