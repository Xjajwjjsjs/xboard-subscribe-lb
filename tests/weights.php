<?php
// Pure allocation tests: no application bootstrap, database or network access.
$root = getenv('WEIGHT_TEST_ROOT') ?: dirname(__DIR__) . '/Services';
$namespace = getenv('WEIGHT_TEST_NS') ?: 'Plugin\\SubscribeLb\\Services';
spl_autoload_register(function ($class) use ($root, $namespace) {
    $prefix = $namespace . '\\';
    if (str_starts_with($class, $prefix)) {
        require $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$choice = $namespace . '\\WeightedChoice';
$poolClass = $namespace . '\\MemberPool';
$lbClass = $namespace . '\\LoadBalancer';
$randomClass = $namespace . '\\Strategies\\RandomStrategy';
$contextClass = $namespace . '\\Strategies\\AllocationContext';
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } $checks++; }
function candidate($id, $weight = 1) { return ['server_id' => $id, 'member' => ['weight' => $weight]]; }
check($choice::weight([]) === 1, 'missing weight defaults to one');
foreach ([null, 0, -1, 10001, 1.5, 'bad', [], false] as $bad) {
    check($choice::weight(['weight' => $bad]) === 1, 'invalid legacy weight is safe');
}
check($choice::weight(['weight' => '2']) === 2, 'database integer string');
$two = [candidate(10, 1), candidate(20, 2)];
check(array_map(fn($n) => $choice::choose($two, $n), [0, 1, 2, 3, 4, 5]) === [10, 20, 20, 10, 20, 20], '1:2 boundaries and wrap');
$four = [candidate(10), candidate(20), candidate(30), candidate(40)];
check(array_map(fn($n) => $choice::choose($four, $n), [0, 1, 2, 3]) === [10, 20, 30, 40], 'four default weights equal');
check($choice::choose([candidate(99, 10000)], 4294967295) === 99, 'single max-weight candidate');
try { $choice::choose([]); check(false, 'empty candidate must reject'); } catch (InvalidArgumentException $e) { $checks++; }
$r = new $randomClass();
$context = new $contextClass(1, ['id' => 7], null, 'initial', 'active', 1700000000);
$counts = [10 => 0, 20 => 0];
for ($i = 0; $i < 12000; $i++) { $counts[$r->choose($two, $context)]++; }
check($counts[20] > 7500 && $counts[20] < 8500, 'weighted random empirical distribution');
$reflection = new ReflectionClass($lbClass); $lb = $reflection->newInstanceWithoutConstructor();
$stable = $reflection->getMethod('stableChoice'); $stable->setAccessible(true);
$salt = str_starts_with($namespace, 'Plugin') ? 'sub-lb' : 'niva-lb';
$weightedCount = [10 => 0, 20 => 0];
for ($uid = 1; $uid <= 1000; $uid++) {
    $hash = crc32($salt . ":{$uid}:7:" . intdiv(1700000000, 3600));
    check($stable->invoke($lb, [20, 10], $uid, 7, 3600, 1700000000) === [10, 20][$hash % 2], 'equal-weight fallback remains identical');
    $entries = [10 => candidate(10, 1), 20 => candidate(20, 2)];
    $a = $stable->invoke($lb, [20, 10], $uid, 7, 3600, 1700000000, $entries);
    check($a === $stable->invoke($lb, [10, 20], $uid, 7, 3600, 1700000000, $entries), 'fallback order-independent and stable');
    $weightedCount[$a]++;
}
check($weightedCount[20] > 600 && $weightedCount[20] < 740, 'weighted fallback distribution');
$group = ['health_mode' => 'none'];
$members = [
    ['server_id' => 10, 'status' => 'active', 'weight' => 1],
    ['server_id' => 20, 'status' => 'disabled', 'weight' => 10000],
    ['server_id' => 30, 'status' => 'active', 'health_override' => 'down', 'weight' => 10000],
    ['server_id' => 40, 'status' => 'active', 'weight' => 10000],
];
$servers = [];
foreach ([10, 20, 30] as $id) { $servers[$id] = ['id' => $id, 'sort' => $id, 'enabled' => true, 'last_check_at' => time()]; }
$pool = $poolClass::build($group, $members, $servers, time());
check($pool->candidates === [10], 'high weights cannot override disabled/down/missing permission');
check($choice::choose(array_map(fn($id) => $pool->entries[$id], $pool->candidates)) === 10, 'selection stays inside eligible pool');
$group['health_mode'] = 'heartbeat'; $servers[10]['last_check_at'] = 0;
$pool = $poolClass::build($group, $members, $servers, time());
check($pool->tier === 'failopen' && $pool->candidates === [10], 'fail-open retains hard exclusions');
$members[0]['status'] = 'draining'; $group['health_mode'] = 'none';
$pool = $poolClass::build($group, $members, $servers, time());
check($pool->tier === 'draining' && $pool->candidates === [10], 'maintenance fallback remains available');
echo json_encode(['passed' => $checks, 'random_1_to_2' => $counts, 'fallback_1_to_2' => $weightedCount], JSON_UNESCAPED_UNICODE) . "\n";
