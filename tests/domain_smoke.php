<?php

require __DIR__.'/../app/Support/Time.php';
require __DIR__.'/../app/Support/Money.php';
require __DIR__.'/../app/Services/PayrollMath.php';
require __DIR__.'/../app/Services/WeekCalculator.php';
require __DIR__.'/../app/Services/PaymentAllocator.php';

use App\Services\PayrollMath;
use App\Services\WeekCalculator;
use App\Services\PaymentAllocator;
use App\Support\Money;
use App\Support\Time;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};

$assert(PayrollMath::totalWorked(315, 60) === 375, '05:15 + 01:00');
$assert(PayrollMath::restMinutes(375) === 1065, 'repos 17:45');
$assert(PayrollMath::restMinutes(0) === 1440, 'repos 24:00');
$assert(PayrollMath::mealAllowanceCents(854, 'auto', null, 855, 1600) === 0, 'panier 14:14');
$assert(PayrollMath::mealAllowanceCents(855, 'auto', null, 855, 1600) === 1600, 'panier 14:15');
$assert(WeekCalculator::calculate('2026-08-03', ['2026-08-03'=>2400], 2100)['overtime'] === 300, '40h => 5h sup');
$assert(WeekCalculator::weekId('2026-08-02') === '2026-08-01', 'reset au changement de mois');
$assert(WeekCalculator::weekId('2027-01-03') === '2027-01-01', 'reset au changement année/mois');
$assert(Time::formatDuration(9345) === '155:45', 'durée >24h');
$assert(Money::numeratorToCents(Money::wageNumerator(45, 1231)) === 923, '45 min à 12,31');
$assert(Money::numeratorToCents(Money::wageNumerator(2400, 1231)) === 49240, '40h payées une seule fois');
$allocation = PaymentAllocator::allocate(['2026-07'=>['generated'=>10000,'overtime_minutes'=>600], '2026-08'=>['generated'=>8000,'overtime_minutes'=>480]], [['id'=>1,'amount_cents'=>6000], ['id'=>2,'amount_cents'=>7000]]);
$assert($allocation['by_month']['2026-07']['remaining'] === 0, 'FIFO juillet soldé');
$assert($allocation['by_month']['2026-08']['remaining'] === 5000, 'FIFO août partiel');
$assert($allocation['by_payment'][2][1]['amount_cents'] === 3000, 'second paiement multi-mois');
$over = PaymentAllocator::allocate(['2026-08'=>['generated'=>5000,'overtime_minutes'=>240]], [['id'=>1,'amount_cents'=>5500]]);
$assert($over['paid'] - $over['generated'] === 500, 'trop-perçu explicite');

echo "Domain smoke tests: OK\n";
