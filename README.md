# Planning de collecte

Application d’affichage hebdomadaire avec import Excel protégé. Python 3.12+, Flask et openpyxl.

## Démarrage

```sh
cd /workspace/planning
python -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python app.py
```

Le serveur écoute sur le port 8000 (`PORT` et `HOST` sont configurables). La page `/` affiche le planning sur le navigateur de l’écran ; `/admin` permet l’import et la sélection d’une semaine.

Définissez `ADMIN_PASSWORD` avant le démarrage pour choisir le mot de passe. Sinon, un mot de passe aléatoire est créé dans `data/admin-password.txt`. Consultez ce fichier localement pour vous connecter ; ne le commitez pas.

## Format et affichage

Feuilles SI-xx et SP-xx ; colonnes A à G : numéro, tournée, collecte, véhicule, chauffeur, deux équipiers. Le jour est lu en H ou I, y compris les samedis et décalages fériés. L’année est extraite du titre A1. Les valeurs de formules enregistrées par Excel sont utilisées, sans recalcul.

L’import est atomique et conserve le planning précédent en cas d’erreur. La semaine courante suit le calendrier ISO et l’heure de Paris. Une semaine absente affiche un message ; le classeur fourni ne contient pas la semaine 50. Une sélection manuelle permet d’imposer une semaine à tous les écrans.

Les écrans vérifient les mises à jour toutes les 5 secondes : synchronisation HTTP, pas un flux vidéo. Une coupure conserve l’affichage déjà chargé ; un rechargement hors connexion ne récupère pas les données. Le planning est enregistré dans data/planning.json et survit au redémarrage. Sauvegardez le dossier data, qui contient les données nominatives et les clés locales.

Les colonnes annexes agents, remplaçants et commentaires ne sont pas diffusées, car elles comprennent plusieurs tableaux distincts. Les équipes affectées aux tournées sont affichées.

## Tests

```sh
.venv/bin/python -m unittest discover -s tests -v
```

Pour une installation permanente, utilisez un serveur WSGI de production derrière HTTPS, un stockage persistant et COOKIE_SECURE=1. Le serveur intégré sert au développement et à la validation. L’URL publique dépend de l’hébergement ; l’application ne configure pas l’écran physique.
