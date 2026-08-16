# Suivi Heures & Salaire

Application personnelle pour suivre les heures travaillées, heures supplémentaires, paniers et paiements différés.

## Stack

- PHP 8.4+
- Laravel 13
- SQLite (un seul fichier `database/database.sqlite`)
- Blade + CSS + JavaScript natif, sans Node/npm au runtime
- Playwright/Node uniquement pour les tests navigateur

## Installation

```bash
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan auth:password-hash
```

La dernière commande demande le mot de passe sans l'afficher et fournit uniquement son hash. Renseigner ensuite dans `.env` :

```dotenv
AUTH_EMAIL=yohan@example.com
AUTH_PASSWORD_HASH='le_hash_genere'
```

Le hash du mot de passe est le seul secret d'authentification conservé par l'application : aucun mot de passe en clair n'est stocké dans SQLite ou dans le dépôt.

Démarrer ensuite l'application :

```bash
php artisan serve
```

Puis ouvrir `http://127.0.0.1:8000` en développement.

## Production Internet

Le fichier `.env.example` est volontairement durci pour un déploiement HTTPS. En production :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine.example
SESSION_SECURE_COOKIE=true
```

Terminer TLS au niveau du serveur web/reverse proxy, conserver `storage/` accessible en écriture par PHP, puis exécuter `php artisan migrate --force` à chaque déploiement. L'application ajoute automatiquement CSP, HSTS sur une URL HTTPS, anti-clickjacking, `nosniff`, une politique de référent stricte et une limitation renforcée des tentatives de connexion.

## Principes métier

- semaine : lundi → dimanche, mais coupée au dernier jour du mois ;
- le compteur repart à zéro au 1er de chaque mois, même au milieu d’une semaine ;
- seuil par défaut : 35:00 par segment de semaine ainsi obtenu ;
- journée : début prérempli à 07:45 par défaut et configurable par date d’effet, fin calculée automatiquement depuis début + conduite + entrepôt ;
- tous les montants de salaire et d’heures supplémentaires sont suivis en net ;
- taux par défaut : 9,74 € net/h ;
- panier par défaut : 16 € si l'heure de fin calculée est au moins 14:15, avec forçage manuel possible ;
- paramètres historisés par date d'effet ;
- paiements indépendants des journées, modifiables et répartis FIFO sur les plus anciennes dettes mensuelles nettes ;
- le nombre d’heures indiqué sur un paiement reste informatif et n’est pas plafonné par le solde calculé ;
- la dette globale et le détail par mois sont suivis en net ;
- les données calculées (semaines, totaux, soldes) sont recalculées depuis les données sources ;
- export CSV disponible pour le détail mensuel et le rapport annuel ;
- sauvegarde manuelle à télécharger et restauration SQLite disponibles dans les paramètres ;
- aucune suppression complète d’une journée : une journée peut être modifiée, mise en repos ou recopiée depuis la veille.

## Tests

```bash
php artisan test
npm ci
npx playwright install chromium
npm run test:e2e
```

Les tests Playwright couvrent Chromium en 390 px, 768 px, 1440 px et 2560 px. Le workflow GitHub Actions exécute la suite PHP, l'audit Composer et ces tests navigateur.
