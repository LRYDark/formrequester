# Options du champ Demandeur (Catalogue de services) — GLPI 11

Dans le **Catalogue de services**, la question « Demandeur » d'un formulaire se comporte comme le champ Demandeur
du formulaire de ticket :

- **liste limitée à l'entité** choisie dans la question « Entité » : mêmes utilisateurs et groupes que dans un
  ticket (y compris ceux qui ont un profil récursif sur une entité parente). Sans entité choisie : toutes les
  entités actives, comme un ticket sans demandeur ;
- **choisir un demandeur sélectionne son entité** dans la question « Entité », avec la règle du ticket : l'entité
  déjà choisie si le demandeur en fait partie, sinon la première de ses entités ;
- **adresse mail acceptée** si le demandeur n'existe pas dans GLPI : tapée dans « Demandeur », elle devient un
  demandeur par mail direct dans le ticket, comme dans le formulaire de ticket.

Le plugin ne remplace rien dans GLPI : les réponses envoyées sont celles des questions natives et c'est GLPI qui
crée le ticket. Désactivé, le formulaire fonctionne comme avant (la question « E-mail du demandeur » s'affiche
alors comme une question Email ordinaire).

## Ce que le plugin touche… et ne touche pas

Tout se règle **question par question**, dans la roue dentée de la question *Demandeur* de l'éditeur. Deux options,
**décochées par défaut** :

- **Liste par entité, comme dans un ticket** (partie *Format*) : la liste ne propose que les personnes de l'entité
  choisie dans la question Entité (mêmes utilisateurs et groupes que le ticket), et choisir un demandeur remplit
  l'entité ;
- **E-mails** (partie *Types autorisés*) : on peut taper l'adresse mail d'une personne absente de GLPI ; elle est
  ajoutée au ticket comme demandeur par mail direct.

**Aucune option cochée : le plugin ne fait rien sur ce formulaire**, comme s'il n'existait pas. Une question Email
posée à la main, même reliée aux demandeurs, reste une question ordinaire : le plugin ne la masque pas, ne la
modifie pas et ne la supprime pas.

En cas d'erreur (recherche des demandeurs en échec…), le plugin s'efface : le champ Demandeur de GLPI reprend la
main et la question E-mail redevient visible ; l'erreur part dans le journal. La question E-mail gérée et la liste
de GLPI sont masquées dès l'affichage de la page (feuille de style chargée dans l'en-tête) : rien n'apparaît puis
disparaît.

## Réglage du formulaire

Le plugin lit la configuration de la destination (onglet *Destinations* du formulaire) pour savoir quelles
questions il pilote :

1. **Demandeur** : une question *Demandeur* reliée aux demandeurs de la destination (« Réponse à des questions
   spécifiques » ou « Réponse à la dernière question »).
2. **Entité** (pour l'option *Liste par entité*) : une question *Élément* de type *Entité*, utilisée par la
   destination pour l'entité (« Réponse à une question spécifique » ou « Réponse à la dernière question
   Entité »). Cocher **Liste par entité, comme dans un ticket** dans la partie *Format* de la roue dentée :
   enregistré aussitôt, sans recharger la page.
3. **Adresse mail** : dans l'éditeur, roue dentée de la question *Demandeur* → *Types autorisés* : cocher
   **E-mails** (à côté d'Utilisateurs et Groupes ; le ⓘ rappelle son rôle). Le formulaire doit être enregistré
   avant : la page se recharge. Le plugin :
   - crée une question **« E-mail du demandeur »** de type Email, facultative, jamais affichée aux utilisateurs
     (repérée dans l'éditeur par « Gérée par la case E-mails ») ;
   - la relie aux **demandeurs** des destinations (« Réponse à des questions spécifiques » : *Demandeur* + cette
     question). Si aucune destination n'utilisait encore la question *Demandeur*, toutes passent à ce réglage.

   **Décocher** supprime la question « E-mail du demandeur » et la retire des demandeurs : le formulaire redevient
   normal. Refusé si une condition du formulaire utilise cette question.

   Une question Email déjà reliée à la main aux demandeurs est reconnue de la même façon (case cochée).

4. **Obligatoire** garde son sens sur la question *Demandeur* : coché, il faut un demandeur **ou** une adresse
   mail ; décoché, rien n'est exigé (champ vide : GLPI met la personne qui remplit le formulaire comme demandeur).
   GLPI refuserait une adresse seule si sa case « Obligatoire » native était cochée : avec les e-mails, le plugin
   mémorise donc ce choix dans sa table (`glpi_plugin_formrequester_requesters`) au moment de l'enregistrement de
   la question, garde la case native décochée et fait lui-même le contrôle sur la page avant l'envoi. En décochant
   « E-mails », en supprimant la question E-mail ou en désinstallant le plugin, GLPI retrouve ce choix.

S'il y a plusieurs questions candidates pour un même rôle, la fonction correspondante est désactivée pour ce
formulaire (signalé dans le journal). Un réglage qui empêcherait l'envoi (question E-mail obligatoire, réglage
fait à la main pas encore réenregistré) est signalé dans l'éditeur, sous « Obligatoire », et dans le journal ; sur
la page du formulaire, une erreur de GLPI sur la question E-mail masquée est réaffichée sous « Demandeur ».

## Journal

- `files/_log/formrequester.log` : erreurs du serveur (avec la requête et la pile d'appels) et erreurs remontées
  par le navigateur (appel AJAX en échec, erreur JavaScript), ainsi que les incohérences de configuration d'un
  formulaire (une fois par session).
- Console du navigateur : messages préfixés `[formrequester]`. Traces détaillées :
  `localStorage.setItem('formrequester_debug', '1')` puis recharger la page.

## Installation

Copier le dossier dans `plugins/formrequester`, puis *Configuration → Plugins* : **Installer** puis **Activer**.

## Compatibilité

GLPI 11.0.x (branche `GLPI_11`). La série 1.1.x, branche `GLPI_12`, est celle de GLPI 12.
