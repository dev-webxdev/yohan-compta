<?php

require __DIR__.'/../app/Support/Time.php';
require __DIR__.'/../app/Support/Money.php';
require __DIR__.'/../app/Services/PayrollMath.php';
require __DIR__.'/../app/Services/WeekCalculator.php';
require __DIR__.'/../app/Services/PaymentAllocator.php';

use App\Services\PaymentAllocator;
use App\Services\PayrollMath;
use App\Services\WeekCalculator;
use App\Support\Money;
use App\Support\Time;

$tests = 0;
$assert = static function (bool $condition, string $name) use (&$tests): void {
    $tests++;
    if (!$condition) { fwrite(STDERR, "FAIL #$tests: $name\n"); exit(1); }
};

$worked = PayrollMath::totalWorked(Time::parseDuration('05:15'), Time::parseDuration('01:00'));
$assert($worked === 375, '05:15 + 01:00 = 06:15');
$assert(PayrollMath::restMinutes($worked) === 1065, '24:00 - 06:15 = 17:45');
$assert(PayrollMath::restMinutes(0) === 1440, 'day off rest = 24:00');
$assert(PayrollMath::mealAllowanceCents(Time::parseClock('14:14'), 'auto', null, 855, 1600) === 0, '14:14 no meal');
$assert(PayrollMath::mealAllowanceCents(Time::parseClock('14:15'), 'auto', null, 855, 1600) === 1600, '14:15 meal');
$assert(PayrollMath::mealAllowanceCents(Time::parseClock('14:30'), 'auto', null, 855, 1600) === 1600, '14:30 meal');
$assert(PayrollMath::mealAllowanceCents(null, 'auto', null, 855, 1600) === 0, 'empty end no meal');
$assert(PayrollMath::mealAllowanceCents(Time::parseClock('10:00'), 'forced', 850, 855, 1600) === 850, 'forced meal');
foreach ([[1920,0],[2100,0],[2400,300]] as [$total,$expected]) {
    $daily = [];
    for ($i=0; $i<5; $i++) $daily[(new DateTimeImmutable('2026-08-03'))->modify("+$i days")->format('Y-m-d')] = intdiv($total,5)+($i<$total%5?1:0);
    $assert(WeekCalculator::calculate('2026-08-03',$daily)['overtime'] === $expected, "$total weekly overtime");
}
$cross = ['2026-07-27'=>420,'2026-07-28'=>420,'2026-07-29'=>420,'2026-07-30'=>420,'2026-07-31'=>540,'2026-08-01'=>180,'2026-08-02'=>240];
$july = WeekCalculator::calculate('2026-07-27',$cross);
$august = WeekCalculator::calculate('2026-08-01',$cross);
$assert(WeekCalculator::weekId('2026-08-02') === '2026-08-01', 'month boundary starts new segment');
$assert($july['overtime'] === 120, 'July segment overtime');
$assert($august['overtime'] === 0, 'August segment resets threshold');
$assert(WeekCalculator::periodEnd('2026-07-27')->format('Y-m-d') === '2026-07-31', 'July segment ends at month end');
$assert(WeekCalculator::weekId('2027-01-03') === '2027-01-01', 'year boundary starts new segment');
foreach ([['2026-02',28],['2028-02',29],['2026-04',30],['2026-08',31]] as [$month,$expectedDays]) {
    $start = new DateTimeImmutable($month.'-01'); $end = $start->modify('last day of this month');
    $assert((int)$end->format('j') === $expectedDays, "$month exact length");
}
$assert((new DateTimeImmutable('2026-04-01'))->modify('last day of this month')->format('Y-m-d') === '2026-04-30', 'April stops at April 30');
$assert(Money::numeratorToCents(Money::wageNumerator(45,1231)) === 923, '45 minutes = 9.23 EUR display');
$assert(Money::numeratorToCents(Money::wageNumerator(2400,1231)) === 49240, '40h salary paid once');
$assert(Time::formatDuration(9345) === '155:45', 'totals over 24 hours');
$debts = ['2026-07'=>['generated'=>10000,'overtime_minutes'=>600], '2026-08'=>['generated'=>8000,'overtime_minutes'=>480]];
$one = PaymentAllocator::allocate($debts,[['id'=>1,'amount_cents'=>6000]]);
$assert($one['by_month']['2026-07']['remaining'] === 4000, 'partial payment');
$two = PaymentAllocator::allocate($debts,[['id'=>1,'amount_cents'=>6000],['id'=>2,'amount_cents'=>7000]]);
$assert($two['by_month']['2026-07']['remaining'] === 0 && $two['by_month']['2026-08']['remaining'] === 5000, 'multiple payments FIFO');
$assert($two['paid'] === 13000, 'euro amount is accounting reference');
$assert($two['by_month']['2026-07']['generated'] === 10000 && $two['by_month']['2026-08']['generated'] === 8000, 'payments never modify generated history');
$over = PaymentAllocator::allocate(['2026-08'=>['generated'=>5000,'overtime_minutes'=>240]],[['id'=>1,'amount_cents'=>5500]]);
$assert($over['by_month']['2026-08']['remaining'] === 0 && $over['paid']-$over['generated'] === 500, 'credit instead of negative debt');
$changed = PaymentAllocator::allocate(['2026-07'=>['generated'=>2000,'overtime_minutes'=>96],'2026-08'=>['generated'=>5000,'overtime_minutes'=>240]],[['id'=>1,'amount_cents'=>6000]]);
$assert($changed['by_month']['2026-08']['remaining'] === 1000, 'retroactive work change reallocates dynamically');
foreach (['14:75','abc'] as $invalid) {
    try { Time::parseDuration($invalid); $assert(false, "$invalid rejected"); } catch (InvalidArgumentException) { $assert(true, "$invalid rejected"); }
}
try { PayrollMath::totalWorked(1200,300); $assert(false,'over 24h rejected'); } catch (InvalidArgumentException) { $assert(true,'over 24h rejected'); }
echo "Full domain scenarios: $tests assertions OK\n";
