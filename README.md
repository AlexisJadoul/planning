# Planning de collecte — PHP

Application PHP avec import Excel protégé, affichage hebdomadaire plein écran et actualisation sous 5 secondes. Aucun Python, aucune base de données et aucun Composer requis.

## Prérequis

PHP 8.2 ou supérieur, extensions **zip**, **SimpleXML**, **libxml** et **session**. Les fichiers Excel .xlsx sont lus directement ; les formules utilisent leurs résultats enregistrés dans Excel.

## Mot de passe administrateur

Copiez `config.example.php` vers `config.php` à la racine du projet et renseignez `admin_password` avec votre propre mot de passe. Ce fichier est ignoré par Git et doit rester **hors du dossier public**.

```php
<?php
return [
    'admin_password' => 'VotreMotDePassePersonnel',
    'data_dir' => __DIR__ . '/data',
    'cookie_secure' => true, // pour un site HTTPS
];
```

Vous pouvez aussi définir la variable d’environnement `ADMIN_PASSWORD` (prioritaire). Sans configuration, le premier essai de connexion crée un mot de passe aléatoire dans `data/admin-password.txt` ; consultez ce fichier localement. Un mot de passe généré précédemment est conservé.

## Lancer en développement

Depuis la racine du projet :

```sh
php -d upload_max_filesize=8M -d post_max_size=10M -S 0.0.0.0:8000 -t public public/router.php
```

- Écran : `/index.php` (ou `/`), bouton plein écran.
- Administration : `/admin.php`.
- Données : `/api.php`.

Le serveur intégré PHP sert au développement. Pour un site permanent, utilisez Apache ou Nginx avec PHP-FPM et HTTPS. Configurez impérativement la racine web sur **public/**. Sur un hébergement mutualisé, placez le contenu de `public/` dans le dossier web et les dossiers `src/`, `views/`, `data/` et `config.php` un niveau au-dessus. Conservez les chemins relatifs indiqués. Ne publiez jamais tout le dépôt comme dossier web.

Donnez au processus PHP un accès en écriture au dossier `data/`, sans ouvrir ses permissions à tout le monde. Conservez ce dossier sur un stockage persistant et sauvegardez-le. Activez `cookie_secure` en HTTPS. Réglez `upload_max_filesize=8M` et `post_max_size=10M` dans PHP ; `public/.user.ini` fournit ces valeurs pour les hébergements PHP-FPM compatibles.

## Import et diffusion

Les feuilles SI-xx et SP-xx sont importées. Colonnes A à G : numéro, tournée, collecte, véhicule, chauffeur, deux équipiers. Le jour est lu en H ou I, et l’année dans A1. Les jours décalés et samedis sont conservés. Les feuilles de roulement et colonnes annexes ne sont pas diffusées.

La semaine courante suit le calendrier ISO et l’heure de Paris. L’administration peut imposer une semaine. Un import invalide conserve le planning précédent. Une semaine absente affiche un message ; le fichier fourni ne comporte pas la semaine 50.

Les écrans interrogent le serveur toutes les 5 secondes (flux de données HTTP, pas vidéo). Lors d’une coupure, le dernier affichage reste dans la page tant qu’elle n’est pas rechargée. Le planning est enregistré dans `data/planning.json`. Le format est compatible avec l’ancienne version Python : aucun réimport nécessaire si ce fichier est conservé.

## Vérification

```sh
php tests/run.php
php tests/run.php '/chemin/Planning 2026.xlsx'
```

Les tests couvrent les dates ISO, les jours décalés, les tournées, les feuilles incompatibles et le stockage. Le second argument teste également le classeur réel.
