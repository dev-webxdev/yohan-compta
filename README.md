# Suivi Heures & Salaire

Application personnelle locale pour suivre les heures travaillées, heures supplémentaires, paniers et paiements différés.

## Stack

- PHP 8.4+
- Laravel 13
- SQLite (un seul fichier `database/database.sqlite`)
- Blade + CSS + JavaScript natif, sans Node/npm

## Installation

```bash
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan serve
```

Puis ouvrir `http://127.0.0.1:8000`.

## Principes métier

- semaine : lundi → dimanche, mais coupée au dernier jour du mois ;
- le compteur repart à zéro au 1er de chaque mois, même au milieu d’une semaine ;
- seuil par défaut : 35:00 par segment de semaine ainsi obtenu ;
- journée : début prérempli à 07:45 par défaut et configurable par date d’effet, fin calculée automatiquement depuis début + conduite + entrepôt ;
- aucun double comptage des heures supplémentaires : les montants brut et net sont calculés sur les mêmes minutes ;
- taux par défaut : 12,31 € brut/h et 9,74 € net/h ;
- panier par défaut : 16 € si l'heure de fin calculée est au moins 14:15, avec forçage manuel possible ;
- paramètres historisés par date d'effet ;
- paiements indépendants des journées et répartis FIFO sur les plus anciennes dettes mensuelles nettes ;
- la dette globale et le détail par mois sont suivis en net ;
- les données calculées (semaines, totaux, soldes) sont recalculées depuis les données sources ;
- une sauvegarde automatique SQLite courante est rafraîchie après chaque modification, indépendamment des sauvegardes de sécurité créées avant les opérations sensibles.

## Tests

```bash
php artisan test
```

Le workflow GitHub Actions exécute également les tests avec PHP 8.4 et SQLite.
