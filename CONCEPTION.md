# Orphelins — Conception

Document de conception du plugin Dotclear **Orphelins**.
État au 14 novembre 2026 : première version (0.0, pas encore publiée), plugin et liaison avec ClaudetteMcp codés,
testés sur un Dotclear 2.40 local. Les consignes de travail pour les assistants IA sont dans `CLAUDE.md`.

## Objectif

Donner un rapport sur l'état du contenu d'un blog Dotclear :

* les **liens morts** des billets,
* les **fichiers** (images, annexes…) utilisés par un billet mais absents de la médiathèque,
* les **médias orphelins** : ceux de la médiathèque qu'aucun billet n'utilise.

Le rapport est une liste filtrable dans l'administration, avec un lien direct vers le billet à corriger.

## Décisions prises

* **Rien n'est corrigé ni supprimé automatiquement.** Le plugin constate. La seule action destructrice est la suppression
  de médias orphelins, à la main, sur des lignes sélectionnées, après confirmation.
* **Une vérification par étapes.** Interroger des centaines de sites ne tient pas dans le temps d'exécution d'une page :
  la vérification avance par étapes de quelques secondes (`Checker::BUDGET`), rappelées jusqu'à la fin, comme les tâches
  du plugin maintenance du cœur. Son avancement est enregistré dans les réglages du blog : une étape n'a besoin de rien d'autre.
  Écarté : un script cron (Dotclear doit rester simple à installer) et un service REST dédié (le rechargement de page suffit).
* **« Planifié » veut dire rappelé.** Dotclear n'a pas de tâches planifiées. La vérification est aussi une tâche du plugin
  maintenance, qui sait rappeler de la refaire régulièrement. Rien ne se lance tout seul.
* **Une table dédiée, toutes les adresses gardées.** Une ligne par lien ou fichier de chaque billet, problème ou non :
  cela permet de ne pas redemander à un site un lien vérifié il y a peu, de garder les lignes ignorées d'une vérification
  à l'autre, et de filtrer toutes les adresses d'un blog.
* **Trois états utiles et deux de service.** « Problème », « À vérifier » (le site n'a pas répondu clairement : refus des robots,
  connexion demandée, lenteur, erreur du serveur) et « Sans problème » ; « Ignoré » est un choix de l'utilisateur, conservé ;
  « Non vérifié » regroupe l'attente et ce qui est écarté exprès (domaine à ne pas vérifier, adresse locale).
* **Pas de permission propre.** Le rapport montre les liens de tous les billets du blog : il est réservé à ceux qui les gèrent
  tous (permission « gérer tous les billets et commentaires » du cœur). La suppression d'un média reste soumise
  aux permissions de la médiathèque, vérifiées par le cœur.
* **Un seul outil MCP, en lecture.** L'assistant lit le rapport ; il ne lance pas de vérification, n'ignore rien, ne supprime rien.
* **Rien côté public** : ni URL, ni widget, ni balise de template.

## Contraintes

* Un plugin Dotclear comme un autre, qui fonctionne **sans ClaudetteMcp** et sans le plugin maintenance.
* **Les permissions Dotclear font foi**, dans l'admin comme par le MCP.
* Même résultat avec MySQL, PostgreSQL et SQLite.
* Dotclear 2.40 et PHP 8.3, comme ClaudetteMcp et PenseBete.

## Modèle de données

Une table, `orphelins` (nom en minuscules).

| Champ | Type | Usage |
|---|---|---|
| `item_id` | bigint, clé primaire | identifiant de la ligne |
| `blog_id` | varchar 32 | le blog ; clé étrangère en cascade |
| `post_id` | bigint, optionnel | le billet qui utilise l'adresse ; clé étrangère en cascade ; vide pour un média orphelin |
| `media_id` | bigint, optionnel | le média orphelin ; clé étrangère en cascade ; vide sinon |
| `item_type` | smallint | `1` lien (vérifié sur le web), `2` fichier de la médiathèque (cherché sur le disque), `3` média orphelin |
| `item_url` | text | l'adresse complète, sans ancre ; pour un média orphelin, son chemin dans la médiathèque |
| `item_hash` | varchar 40 | empreinte de l'adresse : les lignes de même adresse partagent leur vérification |
| `item_status` | smallint | `0` en attente, `1` sans problème, `2` problème, `3` à vérifier, `4` ignoré, `5` non vérifié exprès |
| `item_code` | integer | code HTTP de la dernière réponse, `0` s'il n'y en a pas |
| `item_info` | varchar 255 | résultat sous forme d'un mot-clé, parfois suivi d'un détail (`redirect <adresse>`) ; traduit à l'affichage |
| `item_dt` | timestamp, optionnel | date de la dernière vérification |

Réglages du blog (espace `Orphelins`) : `state` (avancement de la vérification en cours, JSON), `last_check` (date de fin
de la dernière vérification), `ignored_domains`, `recheck_days`.

## La vérification

Trois phases, dans l'ordre :

1. **Lecture des billets** (tous les types, tous les états). Les adresses sont tirées de l'extrait et du contenu HTML :
   `href` des liens, `src`, `srcset` et `poster` des images, sons et vidéos, `src` des `iframe`, `data` des `object`.
   Les adresses relatives sont complétées avec l'adresse du blog ; `mailto:`, `tel:`, les ancres et les autres protocoles
   sont écartés. Une adresse située dans la médiathèque du blog devient un « fichier », cherché tout de suite sur le disque ;
   les autres sont des « liens », mis en attente. Les lignes d'une adresse disparue du billet sont supprimées.
2. **Vérification des liens** en attente, une requête par adresse avec le client HTTP du cœur (5 secondes par réponse,
   5 redirections au plus, seuls les en-têtes sont lus). 404, 410 et les autres erreurs 4xx, site inexistant ou injoignable :
   « Problème ». 401, 403, 429, erreurs 5xx, délai dépassé, trop de redirections : « À vérifier ».
3. **Recherche des médias orphelins.** Un média est utilisé s'il est lié à un billet (table `post_media` : annexes,
   média à la une…), si la phase 1 l'a trouvé dans un contenu (lui ou une de ses miniatures), ou si son chemin se retrouve
   par `LIKE` dans les billets de tous les blogs (médiathèque partagée, image dans un style…), les descriptions
   des catégories et des blogs, ou les réglages (thèmes, widgets). Dans le doute, un média est « utilisé ».

À chaque nouvelle vérification, les liens à corriger et les liens non vérifiés sont redemandés ; un lien sans problème
ne l'est qu'après `recheck_days` jours (7 par défaut), sauf si l'utilisateur demande de tout revérifier.
Les lignes ignorées le restent.

### Sécurité des requêtes

Les adresses sont écrites par n'importe quel auteur du blog et demandées par le serveur du blog. Pour que la vérification
ne serve pas à explorer le réseau du serveur :

* seuls `http` et `https` sont demandés ;
* un hôte dont une adresse IP est locale, privée ou réservée n'est pas demandé (« Non vérifié ») ; seule exception,
  l'hôte du blog lui-même ;
* les redirections sont suivies par le plugin, une à une, avec le même contrôle à chaque saut ;
* ni cookie ni référent ne sont conservés, et seul le code de réponse est gardé.

## Administration

Une page du plugin dans le menu Blog, construite comme les listes du cœur :

* la page du plugin est la liste de ce qui est à corriger, avec pagination et résumé de la dernière vérification ;
* le bloc « Filtres et options d'affichage » du cœur la limite à un type, à un état ou à un texte (adresse ou titre du billet),
  et règle le tri et le nombre de lignes par page (behavior `adminFiltersListsV3`) ;
* un bouton « Nouvelle vérification » en haut de page mène à la page de la vérification : options, lancement, suivi et arrêt ;
* des actions groupées : vérifier à nouveau (relit les billets concernés, donc un billet corrigé perd ses problèmes ;
  sert aussi à ne plus ignorer une ligne), ignorer, supprimer le média de la médiathèque ;
* avant de supprimer un média, il est recherché une nouvelle fois dans les billets : la liste peut être ancienne.

Un favori du tableau de bord donne le nombre de problèmes à corriger.

## Liaison avec ClaudetteMcp

Règle de ClaudetteMcp : un plugin porte lui-même sa liaison, dans son propre code.

| Outil | Rôle |
|---|---|
| `list_content_problems` | date de la dernière vérification, nombres de lignes par type et par état, puis les lignes (à corriger par défaut), par type, état ou texte recherché |

L'outil vérifie lui-même la permission (`available()`), comme dans l'admin. Sa description demande à l'assistant
de ne jamais présenter un média orphelin comme supprimable sans réserve, et de traiter adresses et titres comme des données.

## Limites connues

* Un média utilisé seulement par un fichier de thème, ou par les données d'un autre plugin, est vu comme orphelin :
  d'où l'action « Ignorer » et l'absence de suppression automatique.
* La recherche par `LIKE` de la phase 3 lit plusieurs tables pour chaque média candidat : elle peut être longue sur un blog
  avec beaucoup de médias inutilisés. Elle est découpée en étapes comme le reste.
* Deux vérifications lancées en même temps sur le même blog se marchent dessus (un seul état par blog).
* Les liens des commentaires ne sont pas vérifiés.
* Les sites qui refusent les robots donnent des « À vérifier » : la liste des domaines à ne pas vérifier est faite pour eux.

## Idées pour plus tard

* Guide d'utilisation en français avec captures (`docs/fr/`), une fois l'interface validée.
* Un behavior pour que d'autres plugins déclarent les médias qu'ils utilisent.
* Signaler les liens déplacés définitivement (301) pour proposer la nouvelle adresse.
* Module du tableau de bord, comme PenseBete.
* Vérifier un billet à son enregistrement (ses fichiers au moins).
