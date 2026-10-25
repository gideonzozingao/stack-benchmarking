'use strict';
// bench.js : CPU- and memory-intensive benchmarks (Node.js 16+)
// Mirror of bench.lua: same algorithms, same data, same checksums.
//
// Usage: node bench.js [quick|default|heavy] [all|cpu|mem|<name>]
// No timing happens inside this script. Time it from outside:
//   time node bench.js default            (everything)
//   time node bench.js default fib        (one benchmark)
// Names: fib mandelbrot collatz matrix sieve objects mergesort hashmap bintrees

const PRESETS = {
  quick:   { fib: 30, mandel: 200, collatz: 100000,  matrix: 100, sieve: 1000000,  objects: 200000,  sort: 200000,  hash: 100000,  trees: 12 },
  default: { fib: 35, mandel: 500, collatz: 500000,  matrix: 200, sieve: 5000000,  objects: 1000000, sort: 1000000, hash: 500000,  trees: 16 },
  heavy:   { fib: 38, mandel: 800, collatz: 1000000, matrix: 300, sieve: 10000000, objects: 2000000, sort: 2000000, hash: 1000000, trees: 18 },
};

const preset = process.argv[2] || 'default';
const cfg = PRESETS[preset];
if (!cfg) {
  console.error(`unknown preset '${preset}' (use quick, default or heavy)`);
  process.exit(1);
}

// ---------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------

// Deterministic LCG (identical to bench.lua). x * 1664525 < 2^53, so exact.
function makeRng(seed) {
  let x = seed;
  return () => {
    x = (x * 1664525 + 1013904223) % 4294967296;
    return x;
  };
}

// ---------------------------------------------------------------------
// CPU-intensive benchmarks
// ---------------------------------------------------------------------

// 1. Recursive Fibonacci: function-call overhead
function fib(n) {
  if (n < 2) return n;
  return fib(n - 1) + fib(n - 2);
}
function benchFib(n) {
  return fib(n);
}

// 2. Mandelbrot: tight floating-point loops
function benchMandelbrot(size) {
  const maxIter = 100;
  let count = 0;
  for (let py = 0; py < size; py++) {
    const ci = 2.0 * py / size - 1.0;
    for (let px = 0; px < size; px++) {
      const cr = 2.0 * px / size - 1.5;
      let zr = 0.0, zi = 0.0, i = 0;
      while (i < maxIter && zr * zr + zi * zi <= 4.0) {
        const t = zr * zr - zi * zi + cr;
        zi = 2.0 * zr * zi + ci;
        zr = t;
        i++;
      }
      if (i === maxIter) count++;
    }
  }
  return count;
}

// 3. Collatz: longest chain for any start below `limit` (integer math)
function benchCollatz(limit) {
  let bestN = 1, bestLen = 1;
  for (let start = 1; start < limit; start++) {
    let n = start, len = 1;
    while (n !== 1) {
      n = n % 2 === 0 ? n / 2 : 3 * n + 1;
      len++;
    }
    if (len > bestLen) { bestLen = len; bestN = start; }
  }
  return bestN * 1000 + bestLen;
}

// 4. Naive matrix multiplication (nested arrays, indexed access)
function benchMatrix(n) {
  const A = [], B = [], C = [];
  for (let i = 0; i < n; i++) {
    const a = [], b = [], c = [];
    for (let j = 0; j < n; j++) {
      a.push((i * j) % 10);
      b.push((i + j) % 10);
      c.push(0);
    }
    A.push(a); B.push(b); C.push(c);
  }
  for (let i = 0; i < n; i++) {
    const Ai = A[i], Ci = C[i];
    for (let j = 0; j < n; j++) {
      let s = 0;
      for (let k = 0; k < n; k++) s += Ai[k] * B[k][j];
      Ci[j] = s;
    }
  }
  let sum = 0;
  for (let i = 0; i < n; i++) {
    const Ci = C[i];
    for (let j = 0; j < n; j++) sum += Ci[j];
  }
  return sum;
}

// ---------------------------------------------------------------------
// Memory-intensive benchmarks (return a checksum)
// ---------------------------------------------------------------------

// 5. Sieve of Eratosthenes over a big boolean array
function benchSieve(n) {
  const sieve = new Array(n + 1).fill(true);
  sieve[0] = false;
  sieve[1] = false;
  for (let i = 2; i * i <= n; i++) {
    if (sieve[i]) {
      for (let j = i * i; j <= n; j += i) sieve[j] = false;
    }
  }
  let count = 0;
  for (let k = 2; k <= n; k++) {
    if (sieve[k]) count++;
  }
  return count;
}

// 6. Allocate many small records, then walk them
function benchObjects(n) {
  const list = [];
  for (let i = 1; i <= n; i++) {
    list.push({ id: i, a: i % 1000, b: (i * 7) % 1000 });
  }
  let sum = 0;
  for (let i = 0; i < n; i++) {
    const o = list[i];
    sum += o.id + o.a + o.b;
  }
  return sum;
}

// 7. Merge sort (own implementation, with auxiliary array)
function benchMergeSort(n) {
  const rng = makeRng(12345);
  const a = [], b = [];
  for (let i = 0; i < n; i++) {
    a.push(rng());
    b.push(0);
  }

  function sort(lo, hi) {
    if (hi - lo < 1) return;
    const mid = Math.floor((lo + hi) / 2);
    sort(lo, mid);
    sort(mid + 1, hi);
    let i = lo, j = mid + 1, k = lo;
    while (i <= mid && j <= hi) {
      if (a[i] <= a[j]) { b[k] = a[i]; i++; }
      else { b[k] = a[j]; j++; }
      k++;
    }
    while (i <= mid) { b[k] = a[i]; i++; k++; }
    while (j <= hi) { b[k] = a[j]; j++; k++; }
    for (let t = lo; t <= hi; t++) a[t] = b[t];
  }
  sort(0, n - 1);

  for (let i = 1; i < n; i++) {
    if (a[i - 1] > a[i]) throw new Error('sort failed');
  }
  let sum = 0;
  for (let i = 0; i < n; i += 1000) sum += a[i];
  return sum;
}

// 8. Hash map with string keys: insert, lookup, delete half
function benchHash(n) {
  const map = new Map();
  for (let i = 1; i <= n; i++) map.set('key' + i, i % 1000);
  let sum = 0;
  for (let i = 1; i <= n; i++) sum += map.get('key' + i);
  for (let i = 2; i <= n; i += 2) map.delete('key' + i);
  return sum + map.size;
}

// 9. Binary trees: allocation churn that stresses the garbage collector
function bottomUp(d) {
  if (d === 0) return { left: null, right: null };
  d--;
  return { left: bottomUp(d), right: bottomUp(d) };
}
function check(t) {
  if (t.left !== null) return 1 + check(t.left) + check(t.right);
  return 1;
}
function benchTrees(maxDepth) {
  let total = check(bottomUp(maxDepth + 1));          // stretch tree
  const longLived = bottomUp(maxDepth);
  for (let d = 4; d <= maxDepth; d += 4) {
    const iterations = 1 << (maxDepth - d + 4);
    let c = 0;
    for (let i = 0; i < iterations; i++) c += check(bottomUp(d));
    total += c;
  }
  total += check(longLived);
  return total;
}

// ---------------------------------------------------------------------
// Harness (no timing here)
// ---------------------------------------------------------------------
const BENCHES = [
  ['fib',        'cpu', () => benchFib(cfg.fib)],
  ['mandelbrot', 'cpu', () => benchMandelbrot(cfg.mandel)],
  ['collatz',    'cpu', () => benchCollatz(cfg.collatz)],
  ['matrix',     'cpu', () => benchMatrix(cfg.matrix)],
  ['sieve',      'mem', () => benchSieve(cfg.sieve)],
  ['objects',    'mem', () => benchObjects(cfg.objects)],
  ['mergesort',  'mem', () => benchMergeSort(cfg.sort)],
  ['hashmap',    'mem', () => benchHash(cfg.hash)],
  ['bintrees',   'mem', () => benchTrees(cfg.trees)],
];

const which = process.argv[3] || 'all';
const selected = BENCHES.filter(([name, kind]) =>
  which === 'all' || which === name || which === kind);
if (selected.length === 0) {
  console.error(`unknown benchmark '${which}' (use all, cpu, mem or a name)`);
  process.exit(1);
}

console.log(`# Node ${process.version} | preset=${preset} | ${which}`);
for (const [name, , fn] of selected) {
  console.log(name.padEnd(12) + ' ' + String(fn()).padStart(16));
}