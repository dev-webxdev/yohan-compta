# Yohan Compta

Application personnelle de suivi des heures, heures supplémentaires, paniers, salaires et paiements.

## Installation sur Synology

Prérequis : **Container Manager**. Les commandes ci-dessous se lancent en SSH dans le dossier du projet.

### 1. Préparer la configuration

```bash
cp .env.docker.example .env.docker
```

Modifier ensuite `.env.docker` :

```dotenv
APP_URL=http://IP_DU_NAS:8080
AUTH_EMAIL=yohan@example.com
```

### 2. Générer le mot de passe

```bash
docker compose build
docker compose run --rm app php artisan auth:password-hash
```

La commande demande le mot de passe sans l'afficher. Copier la ligne `AUTH_PASSWORD_HASH='...'` obtenue dans `.env.docker`.

### 3. Lancer l'application

```bash
docker compose up -d
```

Ouvrir :

```text
http://IP_DU_NAS:8080
```

Le conteneur redémarre automatiquement avec le NAS. La clé Laravel, la base SQLite et les documents sont conservés dans `docker-data/`.

Une fois installé, le projet peut aussi être démarré, arrêté et consulté depuis **Container Manager > Projet**.

### Mise à jour

Après récupération d'une nouvelle version :

```bash
git pull
docker compose up -d --build
```

Les données dans `docker-data/` sont conservées et les migrations sont exécutées automatiquement.

### Sauvegarde

Utiliser de préférence **Paramètres > Sauvegarde** dans Yohan Compta. L'archive contient la base SQLite et les documents.

Pour sauvegarder directement `docker-data/` avec Hyper Backup, arrêter d'abord l'application :

```bash
docker compose down
```

Puis la relancer après la sauvegarde avec `docker compose up -d`.

### HTTPS

Si l'application passe par le reverse proxy HTTPS de Synology, mettre dans `.env.docker` :

```dotenv
APP_URL=https://votre-domaine.example
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=*
```

Puis reconstruire :

```bash
docker compose up -d --build
```

Dans ce mode, garder le port `8080` privé au NAS/réseau local et exposer uniquement le reverse proxy HTTPS.

### Logs

```bash
docker compose logs -f
```

## Développement local

```bash
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan auth:password-hash
php artisan serve
```

Renseigner `AUTH_EMAIL` et `AUTH_PASSWORD_HASH` dans `.env`, puis ouvrir `http://127.0.0.1:8000`.

## Tests

```bash
php artisan test
npm ci
npx playwright install chromium
npm run test:e2e
```
