# PHP 8.4 vs Node.js 22: Benchmark Review

Results, analysis and production recommendations · October 25, 2026

## 1. Summary

Nine identical micro-benchmarks (4 CPU-bound, 5 memory-bound) were run in Node v22.22.3 and PHP 8.4.27. **All nine checksums match exactly**, so the two ports compute the same thing and the timings are comparable.

| Configuration | Total time | vs Node |
| --- | --- | --- |
| Node 22 (V8) | \~1.27 s | 1.0x |
| PHP 8.4, OPcache JIT (tracing) | \~1.06 s | \~0.84x (about 16% faster) |
| PHP 8.4, bare (`-n`, no JIT) | \~3.0–3.2 s | \~2.4x slower |
| PHP 8.4, default ini (Xdebug + pcov loaded) | 26.7 s | \~21x slower |

Key takeaways:

- **Xdebug is the dominant factor.** The default CLI configuration is about 8.5x slower than bare PHP and about 25x slower than PHP with JIT. It also disables JIT entirely.
- **PHP with JIT matches Node** on this suite overall. The JIT gives about a 2.9x speedup over interpreted PHP.
- **Without JIT, Node is 2–4x faster** on compute-heavy work, tied on flat object allocation, and **PHP is about 4x faster on string-keyed hash maps**.

## 2. Per-benchmark results (PHP without JIT vs Node)

Times are wall seconds for a separate process per benchmark, `default` preset.

| Benchmark | Type | Node | PHP (no JIT) | Result |
| --- | --- | --- | --- | --- |
| fib(35) | CPU, calls | 0.08 | 0.29 | Node \~3.7x faster |
| mandelbrot | CPU, float | 0.045 | 0.26 | Node faster (see caveat 1) |
| collatz | CPU, integer | 0.46 | 0.93 | Node \~2.0x faster |
| matrix | CPU, arrays | 0.043 | 0.107 | Node \~2.5x faster (see caveat 1) |
| sieve | memory, array | 0.093 | 0.24 | Node \~2.6x faster |
| objects | memory, alloc | 0.153 | 0.151 | **Tie** |
| mergesort | memory, array | 0.224 | 0.70 | Node \~3.1x faster |
| hashmap | memory, string keys | 0.30 | 0.075 | **PHP \~4x faster** |
| bintrees | memory, GC churn | \~0.12–0.15 | \~0.38 | Node \~2.5–3x faster |

Per-benchmark JIT numbers were not captured (see sections 3 and 6), so the JIT result applies to the whole suite only.

## 3. PHP with OPcache and JIT

OPcache with the tracing JIT cut PHP's suite time from about 3.0–3.2 s to about 1.06 s (2.9x faster), which is about 16% faster than Node's \~1.27 s. This is a whole-suite figure only; no per-benchmark JIT run was captured.

**Configuration tested** (run with `php -n`, so no other extension was loaded; JIT confirmed on via `opcache_get_status()["jit"]["on"]`):

```ini
zend_extension=opcache
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=128M
```

| Run | User (s) | Sys (s) | Wall (s) |
| --- | --- | --- | --- |
| PHP 8.4, JIT, run 1 | 0.96 | 0.11 | 1.064 |
| PHP 8.4, JIT, run 2 | 0.89 | 0.16 | invalid (clock glitch); \~1.05 s CPU |
| PHP 8.4, no JIT (`-n`) | 3.10 / 2.89 | 0.12 / 0.10 | 3.22 / 2.99 |
| Node 22 (4 valid runs) | 1.24–1.31 | 0.10–0.15 | 1.23–1.31 |

**Analysis**

- **The gain is the JIT, not OPcache.** A short CLI script compiles once and the opcode cache is not shared between CLI runs, so OPcache alone adds little here. The 2.9x speedup comes almost entirely from the JIT. In web serving, OPcache matters a great deal on its own, because it skips compilation on every request. `jit=off` was not measured, so this is an inference.
- **PHP+JIT also uses less CPU than Node.** PHP+JIT used about 1.06 s of CPU time against Node's 1.34–1.43 s (about 25% less). Node's CPU time exceeds its wall time because V8 runs GC and compilation on background threads, while PHP runs on one thread.
- **The speedup must be concentrated in the loop benchmarks.** Without JIT, `fib`, `mandelbrot`, `collatz`, `matrix`, `sieve` and `mergesort` took about 2.5 s combined, and `objects`, `hashmap` and `bintrees` about 0.6 s. If the second group did not change, the first group fell to roughly 0.45 s, about a 5x drop. That is an inference from the totals, and the JIT may also speed up some of the other three.
- **Where the JIT should help least.** `hashmap`, `objects` and `bintrees` spend most of their time in the engine's C code (hash tables, allocator, reference counting), which the JIT does not compile. This is a hypothesis to test with per-benchmark JIT runs.
- **Precision.** Two runs (1.05 and 1.07 s CPU) agree closely, but two runs is not a distribution. JIT compile time is included in these totals, so a longer-running worker would likely look better still.
- **Compatibility.** The JIT is disabled when an extension overrides `zend_execute_ex()`. Xdebug did this in the default ini; some APM and profiling agents do the same. A production pod can silently lose its JIT this way.

**Recommendations for PHP with JIT**

1. **Always enable OPcache in production,** and size it to the codebase:

   ```ini
   opcache.enable=1
   opcache.memory_consumption=256
   opcache.interned_strings_buffer=16
   opcache.max_accelerated_files=20000
   opcache.validate_timestamps=0
   ```

   With `validate_timestamps=0`, reload PHP-FPM or restart workers on every deploy so the cache is cleared.
2. **Enable the JIT where the work is CPU-bound:** report generation, batch calculations, queue consumers that compute, data transforms. Set `opcache.enable_cli=1` for these long-running CLI workers.
3. **Treat the JIT as an A/B test for ordinary web and API workloads.** They are dominated by database and network time, so gains are usually small. Compare p50/p95 latency and memory with and without it, and leave it off if the gain is within noise.
4. **Verify the JIT is active in the real SAPI** (FPM, Octane, the queue worker), not only the CLI: call `opcache_get_status()["jit"]["on"]`, and fail the deploy check if it is false. Keep Xdebug, pcov and any `zend_execute_ex` hook out of those processes.
5. **Compare `opcache.jit=tracing` with `opcache.jit=function`** on your own workload. Tracing usually wins on tight loops; function mode can be steadier on branchy framework code.
6. **Persistent workers (Octane, RoadRunner, Swoole, FrankenPHP) benefit most.** Bootstrap and JIT warm-up happen once and are amortized across requests. Watch memory growth, and restart workers after a request or memory limit.
7. **Do not rewrite hot numeric code in another language based on interpreted-PHP numbers.** With the JIT on, PHP is on par with Node on this suite. Offload only sustained heavy compute to a worker or native service.
8. **Add a regression check:** run this suite in CI with the JIT on and no Xdebug, and fail on a large slowdown against a stored baseline.

## 4. Analysis

**CPU-bound work.** V8's optimizing compiler is well ahead of the PHP interpreter on call-heavy and numeric loops (`fib`, `mandelbrot`, `collatz`, `matrix`). The whole-suite JIT result suggests PHP's tracing JIT closes most of this gap, since these tight loops are exactly what it targets. This should be confirmed per benchmark.

**Hash maps.** PHP's ordered hash table is a core, heavily tuned structure, and string-keyed arrays are idiomatic. It beat Node's `Map` by about 4x here. Node pays for string concatenation (`'key' + i`) and for hashing the resulting strings.

**Allocation.** Flat record allocation (`objects`) is a tie. Deep, short-lived allocation churn (`bintrees`) favors V8's generational GC, with Node about 2.5–3x faster. PHP's reference counting plus array copy-on-write is cheaper for flat data than for deep trees.

**Array iteration.** `sieve` and `mergesort` favor Node. PHP arrays use more memory per element, and the interpreter pays per-opcode overhead on indexed access.

**Xdebug.** The 26.7 s run is not a measurement of PHP. It measures PHP with a debugger hooked into every function call, and it explains why many "PHP is slow" impressions come from dev machines.

## 5. Caveats about these numbers

1. **Process startup dominates the short benchmarks.** Node's startup floor is about 40 ms. `mandelbrot` (0.045 s) and `matrix` (0.043 s) are mostly startup in Node, so their ratios are inflated. Use the `heavy` preset or in-process timing for those.
2. **Wall-clock glitches.** Several runs report impossible values (`1:22`, `-1:-21`, `0% cpu`) while user time is normal. This points to a system clock jump, common under VMs and WSL. Those wall values were discarded; user+sys time was used instead.
3. **One JIT run set was invalid.** The `$JIT` variable run was not word-split by zsh (it needs `${=JIT}`), so OPcache never loaded and that run (3.04 s) is effectively non-JIT. The two earlier multi-line JIT runs (1.06 s and \~1.05 s CPU) are valid, and JIT was confirmed on via `opcache_get_status()`.
4. **Memory was not measured.** Only time was captured, so memory conclusions are unsupported.
5. **Single machine, few repetitions.** There was no warmup control, CPU pinning or median-of-N, and Node uses more than one thread (about 108% CPU) for GC and compilation.
6. **Synthetic micro-benchmarks.** They say little about I/O-bound web workloads.

## 6. Recommendations: benchmarking

- Remove `ini_set('zend.assertions', '-1')` from `bench.php`. Under `-n` it prints a warning to stdout that breaks output diffs.
- Use `hyperfine --warmup 2 --runs 10` or a median of at least 5 runs.
- Use the `heavy` preset, or add in-process `hrtime` timing, to remove startup noise.
- Capture peak memory: `/usr/bin/time -f "%e s %M KB"`.
- Run the per-benchmark table for PHP+JIT, and also try `opcache.jit=function`.
- In zsh, expand the options with `${=JIT}`, or use a bash array.
- Record the CPU model and PHP/Node versions, and keep the machine idle.

## 7. Recommendations: production

**Environment hygiene (highest impact).**

- Never run Xdebug or pcov in production, in performance tests, or in CI jobs that are not collecting coverage or debugging. Default to `xdebug.mode=off` and enable it on demand.
- Check `php -m` on queue workers and long-running CLI processes, because they often inherit the dev ini.

**Tune PHP properly before comparing runtimes.**

- Enable OPcache everywhere, with `validate_timestamps=0` in production and preloading where it helps.
- Enable JIT only after measuring your workload. Typical request/response apps spend their time in the database, network and framework code, where JIT gains are small. CPU-heavy CLI workers (reports, calculations, data transforms) are where it can pay off.
- Set `opcache.enable_cli=1` for long-running workers.

**Choosing a runtime by workload.**

| Workload | Guidance |
| --- | --- |
| I/O-bound web apps and APIs (CRUD, DB-backed) | Runtime speed is rarely the bottleneck. Choose on team skills, ecosystem and hosting model. Both are fine. |
| Array and hash-map-heavy processing (ETL, aggregation, report building, templating) | PHP is competitive or faster on string-keyed maps. |
| Tight numeric or CPU loops | Node is clearly faster, and PHP needs JIT to compete. For sustained heavy compute, move to a queue worker or a native service (Go, Rust or a C extension) rather than tuning either runtime. |
| Deep object graphs, allocation churn | Node's GC has the edge. |
| Persistent workers (Node servers, PHP Octane/Swoole/RoadRunner/FrankenPHP) | Eliminate per-request bootstrap in PHP and are the fairest comparison with Node. Watch for memory growth and cross-request state. Classic PHP-FPM trades some speed for isolation and simple scaling. |
| Real-time connections (WebSockets, streaming, many concurrent idle connections) | Node's event loop is the natural fit. PHP needs an async runtime or a separate service. |

**Concurrency note.** A single Node process runs JavaScript on one thread, so a CPU-heavy request blocks other requests in that process; use worker threads or separate processes. PHP-FPM isolates each request in its own process, which is simpler to reason about but costs more memory per concurrent request.

## 8. Conclusion

On this suite, tuned PHP 8.4 (OPcache + JIT, no debugger extensions) is on par with Node 22 overall. Untuned PHP is 2–4x slower on compute and PHP with Xdebug loaded is an order of magnitude slower. Neither runtime is a safe general "winner": Node leads on numeric loops and GC-heavy allocation, and PHP leads on hash-map work. For typical I/O-bound business applications the gap is unlikely to decide anything.
#TO-D
   - Fix the environment first, then
   - re-measure with the improved methodology above before making a platform decision on performance grounds.
