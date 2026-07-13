=== Passerelle de paiement PayXpert ===
Contributors: payxpert
Tags: woocommerce, paiement, passerelle, payxpert, carte bancaire, paiement fractionné
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.6.0
WC tested up to: 10.1.4

Acceptez les paiements par carte et en plusieurs fois via PayXpert (Connect2Pay) dans WooCommerce.

== Description ==

= Note importante =

Ce module n'est pas une mise à jour de la version précédente.
Il s'agit d'une nouvelle version indépendante (v2).
Avant d'installer ce module, l'ancienne version (v1) doit être désinstallée et supprimée.

= Fonctionnalités =

* Choix du mode de capture (automatique ou manuelle)
* Notification email avant expiration de l'autorisation
* Modes d'affichage du paiement : redirection ou iFrame (seamless)
* Option PayByLink
* Paiement en plusieurs fois (instalment)
* Personnalisation des méthodes de paiement
* Système de notifications personnalisables :
  * Activation/désactivation
  * Adresse email de réception
  * Langue des notifications

== Installation ==

= Méthode 1 : Depuis le back-office WordPress =

* Allez dans Extensions > Ajouter
* Cliquez sur Téléverser une extension
* Sélectionnez le fichier ZIP du module ou glissez-le dans la zone prévue
* Cliquez sur Installer maintenant puis Activer

= Méthode 2 : Manuellement =

* Décompressez l'archive ZIP du module
* Copiez le dossier du module dans le répertoire `/wp-content/plugins/`
* Allez ensuite dans Extensions > Extensions installées, recherchez "PayXpert" puis cliquez sur Activer

== Mise à jour du module ==

1. (Optionnel) Désactivez et supprimez l'ancienne version via Extensions > Extensions installées
   Si vous souhaitez conserver la configuration, ne supprimez pas les données stockées par le plugin
2. Installez la nouvelle version :
   * Soit en téléversant le nouveau fichier ZIP via le gestionnaire d'extensions
   * Soit en remplaçant manuellement le dossier dans `/wp-content/plugins/`, puis activez le module

== Configuration ==

Après activation, allez dans :

WooCommerce > Réglages > Paiements > PayXpert, puis renseignez :

* Clé publique API
* Clé privée API

Si vos clés sont valides, vous pourrez accéder aux configurations avancées du module.

== Dépendances ==

* WooCommerce >= 7.6.0
* PHP >= 7.4
* Un compte PayXpert valide

== Qualité / QA ==

Le commit est protégé par un hook git obligatoire (QA + `vendor/` en mode prod).
Tout est invoqué avec PHP 7.4 (`php7.4`) pour coller au minimum runtime du plugin (PHP 7.4+).

= Prérequis (une fois par clone) =

`php7.4 /usr/bin/composer install`
`php7.4 /usr/bin/composer qa:hooks`

Cela installe l'outillage de dev (PHPCS, PHPStan, Slevomat nullable PHP 8.4, etc.) et active le pre-commit.

= Développement (deps dev) =

Travaillez avec les dépendances de dev installées. Pour itérer sur la qualité sans toucher à Composer :

`bash .weplus/qa-loop.sh`
ou : `php7.4 composer qa:run`
ou : `php7.4 composer qa`

Commandes utiles :

`php7.4 /usr/bin/composer lint`          # syntaxe PHP (php7.4, php8.2, php8.4)
`php7.4 /usr/bin/composer phpcs`         # coding standards
`php7.4 /usr/bin/composer phpcs:fix`     # correctifs auto PHPCS
`php7.4 /usr/bin/composer phpstan`       # analyse statique

Voir `.weplus/quality-loop.md` pour la boucle corriger → relancer → vert.

= Commit (gate obligatoire) =

À chaque `git commit`, le hook exécute automatiquement :

1. `composer update` (deps dev)
2. lint + PHPCS + PHPStan
3. `composer update --no-dev` si la QA est au vert (autoload prod uniquement dans `vendor/composer/`)

Le commit est bloqué si la QA échoue. Contournement ponctuel : `SKIP_QA=1 git commit` ou `git commit --no-verify`.

Après chaque commit réussi, réinstaller les outils de dev :

`php7.4 /usr/bin/composer install`

= Revue IA (recommandée) =

Avant un commit important ou une MR : revue Bugbot sur le diff (`review-bugbot` dans Cursor).

= Marketplace WooCommerce =

Les tests QIT sont lancés par le client partenaire lors de la soumission. La release (zip) est gérée par un outil externe, pas par ce dépôt.

Workflow : dev (`composer install`) → itération QA (`qa-loop`) → revue IA (optionnel) → `git commit` (hook) → `composer install` → release externe → QIT client.

== Licence ==

Ce module est distribué sous la licence GNU General Public License v2 ou ultérieure. Voir le fichier LICENSE pour plus d'informations.

== Support ==

Un formulaire de contact est disponible dans la page de configuration du module.
En cas de problème d'installation, contactez assistance@payxpert.com.
