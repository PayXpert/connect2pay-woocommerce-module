# Changelog

Ce format est une version adaptée de [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Tous les changements notables de ce projet seront disponibles dans ce fichier.


## [v2.3.1] - 2026-06-08

### Ajouté 

- #45677: Le marchand peut désormais désactiver l'abonnement d'un client final via la liste des abonnements en BO.
- #45364: Ajout du mode de paiement par abonnement.
- #45605: Ajout d'un bloc d'informations à propos de l'abonnement du panier dans le checkout.
- #45675: Ajout de la possibilité de rembourser une occurrence d'abonnement avec l'autre API.

### Corrige

- #46351: Exposition explicite de la fonction triggerPayxpertPaymentCall sur window 
- #46350: Correction des règles REACT dans les fichiers JS
- #46349: Suppression du endpoint de raffraichissement de token payxpert
- #46348: Correction de vulnérabilité dans le flux de finalisation des commandes iFrame
- #45609: Correction d'un problème de traduction dans les données d'abonnement sur un produit avec variations.
- #45676: Correction du display des transactions de remboursement dans le détail d'un abonnement. 
- #45729: Correction du statut pour les commandes automatiques (abonnement) lorsque le paiement n'a pas abouti.
- #45610: Ajout du blocage d'ajout au panier pour un abonnement si un autre abonnement existe déjà dans le panier.

## [v2.2.3] - 2026-03-20

# Modifié

- #46025: L'iFrame attend désormais la confirmation de création de commande du BO pour lancer le processus de paiement
- #45980: L'iFrame demande désormais le cardHolderName.
- #46033: Redirige l'utilisateur non connecté vers la page order-pay lorsque le paiement échoue

### Corrige

- #45607: Correction de l'affichage du statut des méthodes de paiement dans la page "Paiements".
- #45981: Clear le buffer de réponse du flux IPN pour éviter les JSON parasites.


## [v2.2.2] - 2026-01-13

### Ajouté

- #45560: Ajout de la possibilité d'envoyer un paiement PayByLink pour le statut de commande "Echoué"

### Modifié

- #45580: Désactivation du mode de paiement ApplePay en mode seamless (non disponible pour le moment).

### Réparé

- #45546 : Réparation du mode de paiement Applepay. Logo + display (Seamless).


## [v2.2.1] - 2025-12-19

### Réparé

- #45477: Correction erreur 500 sur page checkout en mode GUEST.
- #45150: Prépare la variable applePayEnabled côté PHP pour le seamless JS


## [v2.2.0] - 2025-12-15

### Corrigé

- #45456: Correction du flux après 3DS en mode seamless. Ferme désormais la modal et renvoie le client vers la bonne page.

### Modifié

- #45413: Modification de l'affichage des logos des méthodes de paiement en FO. Amex n'apparait plus si le mode de paiement n'est pas activé en BO.
- #45443: Upgrade dynamique de la version du JS pour le seamless via getAccountInfo

### Supprimé

- #45413: Suppression de la zone de champs additionnel dans la page de checkout pour le mode de paiement CC en mode redirection

- #45364: Ajout du mode de paiement par abonnement

## [v2.1.0] - 2025-11-10

### Ajouté

- #45193: Ajout des informations clients si disponible en mode seamless

### Corrigé

- #45002: Correction du statut 'En cours' lorsque la commande est validée avec des produits virtuels

### Modifé

- #45227: Modification des conditions d'envoi de la méthode de paiement PayByLink

## [v2.0.0] - 2025-08-27

### Ajouté

- #44955: Ajout de la version de WooCommerce dans la section informative de la configuration du module
- #44958: Ajout du README.md
- #44962: Ajout du lien vers le guide utilisateur Plugin WooCommerce

### Supprimé

- #44959: Suppression des paramètrages Alipay & WeChat. Prévu au prochain lot.
