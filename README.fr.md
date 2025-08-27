# Module WooCommerce PayXpert

### ⚠️ Note Importante

Ce module n’est **pas une mise à jour** de la version précédente.  
Il s’agit d’une **nouvelle version indépendante (v2)**.  
Avant d’installer ce module, l’ancienne version (**v1**) doit être **désinstallée et supprimée**.  

### 📦 Fonctionnalités

- Choix du mode de capture (automatique ou manuelle)  
- Notification email avant expiration de l’autorisation  
- Modes d’affichage du paiement : redirection ou iFrame (seamless)  
- Option PayByLink  
- Paiement en plusieurs fois (instalment)  
- Personnalisation des méthodes de paiement  
- Système de notifications personnalisables :
  - Activation/désactivation
  - Adresse email de réception
  - Langue des notifications

### 🔧 Installation

#### Méthode 1 : Depuis le back-office WordPress

- Allez dans **Extensions > Ajouter**  
- Cliquez sur **Téléverser une extension**  
- Sélectionnez le fichier ZIP du module ou glissez-le dans la zone prévue  
- Cliquez sur **Installer maintenant** puis **Activer**

#### Méthode 2 : Manuellement

- Décompressez l’archive ZIP du module  
- Copiez le dossier du module dans le répertoire `/wp-content/plugins/`  
- Allez ensuite dans **Extensions > Extensions installées**, recherchez "PayXpert" puis cliquez sur **Activer**

### 🔁 Mise à jour du module

1. (Optionnel) Désactivez et supprimez l’ancienne version via **Extensions > Extensions installées**  
   > 🛈 Si vous souhaitez conserver la configuration, ne supprimez pas les données stockées par le plugin
2. Installez la nouvelle version :
   - Soit en téléversant le nouveau fichier ZIP via le gestionnaire d’extensions  
   - Soit en remplaçant manuellement le dossier dans `/wp-content/plugins/`, puis activez le module

### 🔧 Configuration

Après activation, allez dans :  

**WooCommerce > Réglages > Paiements > PayXpert**, puis renseignez :  

- Clé publique API  
- Clé privée API  

Si vos clés sont valides, vous pourrez accéder aux configurations avancées du module.

### 🛠 Dépendances

- WooCommerce >= 7.6.0  
- PHP >= 7.2  
- Un compte PayXpert valide

### 📜 Licence

Ce module est distribué sous la licence MIT. Voir le fichier [LICENSE](./LICENSE) pour plus d’informations.

### ✉️ Support

Un formulaire de contact est disponible dans la page de configuration du module.  
En cas de problème d'installation, contactez **assistance@payxpert.com**.
