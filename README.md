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

- semaine réelle : lundi → dimanche ;
- seuil par défaut : 35:00 ;
- les heures supplémentaires sont attribuées aux dates réelles où le cumul hebdomadaire dépasse le seuil, afin que chaque portion appartienne à son vrai mois ;
- aucun double comptage des heures supplémentaires dans le salaire : le salaire du travail est calculé sur toutes les heures travaillées une seule fois ;
- panier par défaut : 16 € si l'heure de fin est au moins 14:15, avec forçage manuel possible ;
- paramètres historisés par date d'effet ;
- paiements indépendants des journées et répartis FIFO sur les plus anciennes dettes mensuelles ;
- la dette globale et le détail par mois sont conservés ;
- les données calculées (semaines, totaux, soldes) sont recalculées depuis les données sources.

## Tests

```bash
php artisan test
```

Le workflow GitHub Actions exécute également les tests avec PHP 8.4 et SQLite.
