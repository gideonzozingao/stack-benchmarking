<?php

declare(strict_types=1);

// bench.php : CPU- and memory-intensive benchmarks (PHP 8.1+, 64-bit)
// Mirror of bench.js / bench.lua: same algorithms, same data, same checksums.
//
// Usage: php bench.php [quick|default|heavy] [all|cpu|mem|<name>]
// No timing happens inside this script. Time it from outside:
//   time php bench.php default            (everything)
//   time php bench.php default fib        (one benchmark)
// Names: fib mandelbrot collatz matrix sieve objects mergesort hashmap bintrees
//
// For reliable comparisons use the harness (bench.sh), which runs this file
// with `php -n` and sets every ini value explicitly. Equivalent JIT run by hand:
//   php -n -d zend.assertions=-1 -d zend_extension=opcache -d opcache.enable_cli=1 \
//       -d opcache.jit=tracing -d opcache.jit_buffer_size=128M bench.php default

ini_set('memory_limit', '-1');

const PRESETS = [
    'quick'   => ['fib' => 30, 'mandel' => 200, 'collatz' => 100000,  'matrix' => 100, 'sieve' => 1000000,  'objects' => 200000,  'sort' => 200000,  'hash' => 100000,  'trees' => 12],
    'default' => ['fib' => 35, 'mandel' => 500, 'collatz' => 500000,  'matrix' => 200, 'sieve' => 5000000,  'objects' => 1000000, 'sort' => 1000000, 'hash' => 500000,  'trees' => 16],
    'heavy'   => ['fib' => 38, 'mandel' => 800, 'collatz' => 1000000, 'matrix' => 300, 'sieve' => 10000000, 'objects' => 2000000, 'sort' => 2000000, 'hash' => 1000000, 'trees' => 18],
];

$preset = $argv[1] ?? 'default';
if (!isset(PRESETS[$preset])) {
    fwrite(STDERR, "unknown preset '$preset' (use quick, default or heavy)\n");
    exit(1);
}
$cfg = PRESETS[$preset];

// ---------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------

// Deterministic LCG (identical to bench.js). 64-bit ints, so exact.
function makeRng(int $seed): Closure
{
    $x = $seed;
    return function () use (&$x): int {
        $x = ($x * 1664525 + 1013904223) % 4294967296;
        return $x;
    };
}

// ---------------------------------------------------------------------
// CPU-intensive benchmarks
// ---------------------------------------------------------------------

// 1. Recursive Fibonacci: function-call overhead
function fib(int $n): int
{
    if ($n < 2) return $n;
    return fib($n - 1) + fib($n - 2);
}
function benchFib(int $n): int
{
    return fib($n);
}

// 2. Mandelbrot: tight floating-point loops
function benchMandelbrot(int $size): int
{
    $maxIter = 100;
    $count = 0;
    for ($py = 0; $py < $size; $py++) {
        $ci = 2.0 * $py / $size - 1.0;
        for ($px = 0; $px < $size; $px++) {
            $cr = 2.0 * $px / $size - 1.5;
            $zr = 0.0;
            $zi = 0.0;
            $i = 0;
            while ($i < $maxIter && $zr * $zr + $zi * $zi <= 4.0) {
                $t = $zr * $zr - $zi * $zi + $cr;
                $zi = 2.0 * $zr * $zi + $ci;
                $zr = $t;
                $i++;
            }
            if ($i === $maxIter) $count++;
        }
    }
    return $count;
}

// 3. Collatz: longest chain for any start below `limit` (integer math)
function benchCollatz(int $limit): int
{
    $bestN = 1;
    $bestLen = 1;
    for ($start = 1; $start < $limit; $start++) {
        $n = $start;
        $len = 1;
        while ($n !== 1) {
            $n = ($n & 1) === 0 ? $n >> 1 : 3 * $n + 1;
            $len++;
        }
        if ($len > $bestLen) {
            $bestLen = $len;
            $bestN = $start;
        }
    }
    return $bestN * 1000 + $bestLen;
}

// 4. Naive matrix multiplication (nested arrays, indexed access)
function benchMatrix(int $n): int
{
    $A = [];
    $B = [];
    $C = [];
    for ($i = 0; $i < $n; $i++) {
        $a = [];
        $b = [];
        $c = [];
        for ($j = 0; $j < $n; $j++) {
            $a[] = ($i * $j) % 10;
            $b[] = ($i + $j) % 10;
            $c[] = 0;
        }
        $A[] = $a;
        $B[] = $b;
        $C[] = $c;
    }
    for ($i = 0; $i < $n; $i++) {
        $Ai = $A[$i];
        $Ci = $C[$i];
        for ($j = 0; $j < $n; $j++) {
            $s = 0;
            for ($k = 0; $k < $n; $k++) $s += $Ai[$k] * $B[$k][$j];
            $Ci[$j] = $s;
        }
        $C[$i] = $Ci;
    }
    $sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $Ci = $C[$i];
        for ($j = 0; $j < $n; $j++) $sum += $Ci[$j];
    }
    return $sum;
}

// ---------------------------------------------------------------------
// Memory-intensive benchmarks (return a checksum)
// ---------------------------------------------------------------------

// 5. Sieve of Eratosthenes over a big boolean array
function benchSieve(int $n): int
{
    $sieve = array_fill(0, $n + 1, true);
    $sieve[0] = false;
    $sieve[1] = false;
    for ($i = 2; $i * $i <= $n; $i++) {
        if ($sieve[$i]) {
            for ($j = $i * $i; $j <= $n; $j += $i) $sieve[$j] = false;
        }
    }
    $count = 0;
    for ($k = 2; $k <= $n; $k++) {
        if ($sieve[$k]) $count++;
    }
    return $count;
}

// 6. Allocate many small records, then walk them
final class Rec
{
    public function __construct(public int $id, public int $a, public int $b) {}
}
function benchObjects(int $n): int
{
    $list = [];
    for ($i = 1; $i <= $n; $i++) {
        $list[] = new Rec($i, $i % 1000, ($i * 7) % 1000);
    }
    $sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $o = $list[$i];
        $sum += $o->id + $o->a + $o->b;
    }
    return $sum;
}

// 7. Merge sort (own implementation, with auxiliary array)
function msort(array &$a, array &$b, int $lo, int $hi): void
{
    if ($hi - $lo < 1) return;
    $mid = ($lo + $hi) >> 1;
    msort($a, $b, $lo, $mid);
    msort($a, $b, $mid + 1, $hi);
    $i = $lo;
    $j = $mid + 1;
    $k = $lo;
    while ($i <= $mid && $j <= $hi) {
        if ($a[$i] <= $a[$j]) {
            $b[$k] = $a[$i];
            $i++;
        } else {
            $b[$k] = $a[$j];
            $j++;
        }
        $k++;
    }
    while ($i <= $mid) {
        $b[$k] = $a[$i];
        $i++;
        $k++;
    }
    while ($j <= $hi) {
        $b[$k] = $a[$j];
        $j++;
        $k++;
    }
    for ($t = $lo; $t <= $hi; $t++) $a[$t] = $b[$t];
}
function benchMergeSort(int $n): int
{
    $rng = makeRng(12345);
    $a = [];
    $b = [];
    for ($i = 0; $i < $n; $i++) {
        $a[] = $rng();
        $b[] = 0;
    }
    msort($a, $b, 0, $n - 1);

    for ($i = 1; $i < $n; $i++) {
        if ($a[$i - 1] > $a[$i]) throw new RuntimeException('sort failed');
    }
    $sum = 0;
    for ($i = 0; $i < $n; $i += 1000) $sum += $a[$i];
    return $sum;
}

// 8. Hash map with string keys: insert, lookup, delete half
function benchHash(int $n): int
{
    $map = [];
    for ($i = 1; $i <= $n; $i++) $map['key' . $i] = $i % 1000;
    $sum = 0;
    for ($i = 1; $i <= $n; $i++) $sum += $map['key' . $i];
    for ($i = 2; $i <= $n; $i += 2) unset($map['key' . $i]);
    return $sum + count($map);
}

// 9. Binary trees: allocation churn that stresses the garbage collector
function bottomUp(int $d): array
{
    if ($d === 0) return [null, null];
    $d--;
    return [bottomUp($d), bottomUp($d)];
}
function check(array $t): int
{
    if ($t[0] !== null) return 1 + check($t[0]) + check($t[1]);
    return 1;
}
function benchTrees(int $maxDepth): int
{
    $total = check(bottomUp($maxDepth + 1));          // stretch tree
    $longLived = bottomUp($maxDepth);
    for ($d = 4; $d <= $maxDepth; $d += 4) {
        $iterations = 1 << ($maxDepth - $d + 4);
        $c = 0;
        for ($i = 0; $i < $iterations; $i++) $c += check(bottomUp($d));
        $total += $c;
    }
    $total += check($longLived);
    return $total;
}

// ---------------------------------------------------------------------
// Harness (no timing here)
// ---------------------------------------------------------------------
$BENCHES = [
    ['fib',        'cpu', fn() => benchFib($cfg['fib'])],
    ['mandelbrot', 'cpu', fn() => benchMandelbrot($cfg['mandel'])],
    ['collatz',    'cpu', fn() => benchCollatz($cfg['collatz'])],
    ['matrix',     'cpu', fn() => benchMatrix($cfg['matrix'])],
    ['sieve',      'mem', fn() => benchSieve($cfg['sieve'])],
    ['objects',    'mem', fn() => benchObjects($cfg['objects'])],
    ['mergesort',  'mem', fn() => benchMergeSort($cfg['sort'])],
    ['hashmap',    'mem', fn() => benchHash($cfg['hash'])],
    ['bintrees',   'mem', fn() => benchTrees($cfg['trees'])],
];

$which = $argv[2] ?? 'all';
$selected = array_filter($BENCHES, fn($b) => $which === 'all' || $which === $b[0] || $which === $b[1]);
if (count($selected) === 0) {
    fwrite(STDERR, "unknown benchmark '$which' (use all, cpu, mem or a name)\n");
    exit(1);
}

echo "# PHP " . PHP_VERSION . " | preset=$preset | $which\n";
foreach ($selected as [$name,, $fn]) {
    echo str_pad($name, 12) . ' ' . str_pad((string) $fn(), 16, ' ', STR_PAD_LEFT) . "\n";
}
