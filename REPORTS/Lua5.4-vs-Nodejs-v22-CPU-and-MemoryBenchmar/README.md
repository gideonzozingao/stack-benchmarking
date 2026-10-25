# Lua 5.4 vs Node.js v22: CPU and Memory Benchmark

| | |
|---|---|
| **Date** | 25 October 2026 |
| **Comparison** | Lua 5.4 (`lua5.4`) vs Node.js v22.22.3 |
| **Preset** | `default` |
| **Method** | External timing with the shell `time` builtin, whole process |
| **Index** | [Back to all reports](../../README.md) |

## 1. Summary

On this machine, **Node.js was about 3.5x faster than Lua 5.4** over the full nine-benchmark suite (mean wall-clock 1.35 s vs 4.72 s). The gap was 3.1x on the four CPU-bound benchmarks and roughly 3.7x to 3.9x on the five memory-intensive ones. The recursive Fibonacci test, the only benchmark timed on its own on both runtimes, showed a 4.7x gap even with Node's startup cost included.

Every benchmark produced identical checksums in both languages, so the two programs did the same work and the timings are comparable.

## 2. Environment

| Item | Value |
|---|---|
| Machine / CPU | _to fill in_ |
| Cores / RAM | _to fill in_ |
| OS / shell | _to fill in_ (shell `time` output is zsh style) |
| Lua | 5.4 (`lua5.4 -v` for the exact patch version) |
| Node.js | v22.22.3 |
| Working directory | `~/projects/lua` |

## 3. Test setup

- **Programs:** [`bench.lua`](bench.lua) and [`bench.js`](bench.js), the same algorithms and the same seeded data in both. Neither contains a timer.
- **Timing:** shell `time` around each process, so figures are whole-process wall-clock and include interpreter startup.
- **Scopes timed:** `all` (nine benchmarks), `cpu` (four) and `fib` (one), on both runtimes. `all` was run twice per runtime.
- **Preset `default`:**
  - CPU: `fib(35)`; Mandelbrot 500x500 with 100 max iterations; Collatz for every start below 500,000; 200x200 matrix multiply.
  - Memory: sieve of Eratosthenes to 5,000,000; 1,000,000 small objects; hand-written merge sort of 1,000,000 integers; hash map with 500,000 string keys; binary trees at depth 16.

Parameters for all presets are in the [repository README](../../README.md#benchmark-contract).

## 4. Results

### 4.1 Wall-clock time

Speedup is Lua time divided by Node time.

| Scope | Lua 5.4 | Node v22.22.3 | Node speedup |
|---|---|---|---|
| All, run 1 | 4.643 s | 1.306 s | 3.56x |
| All, run 2 | 4.801 s | 1.388 s | 3.46x |
| **All, mean** | **4.722 s** | **1.347 s** | **3.51x** |
| CPU group (4 benchmarks) | 1.720 s | 0.555 s | 3.10x |
| `fib` only | 0.370 s | 0.079 s | 4.68x |
| Memory group (all minus cpu, derived) | 2.92 to 3.08 s | 0.75 to 0.83 s | 3.7x to 3.9x |

The memory-group row is not a direct measurement. It subtracts the `cpu` run from each `all` run, which cancels startup but mixes separate invocations, so treat it as indicative.

### 4.2 User, system and CPU usage

| Run | Lua user / sys / CPU | Node user / sys / CPU |
|---|---|---|
| All, run 1 | 4.55 s / 0.12 s / 100% | 1.28 s / 0.14 s / 108% |
| All, run 2 | 4.63 s / 0.14 s / 99% | 1.30 s / 0.15 s / 104% |
| CPU group | 1.72 s / 0.00 s / 100% | 0.55 s / 0.01 s / 101% |
| `fib` only | 0.37 s / 0.00 s / 99% | 0.08 s / 0.00 s / 99% |

### 4.3 Where Lua spends its time

Per-benchmark times come from an earlier in-script timing run of `bench.lua default` (total 4,625 ms). That total is within 0.4% of the first external measurement (4.643 s) and 3.8% below the second (4.801 s), which supports both methods. The in-script timers have since been removed from the programs.

| Benchmark | Lua time | Share of total | Lua heap retained |
|---|---|---|---|
| collatz | 1,013.5 ms | 21.9% | - |
| mergesort | 925.5 ms | 20.0% | 32 MB |
| bintrees | 826.8 ms | 17.9% | - |
| hashmap | 689.9 ms | 14.9% | 24 MB |
| fib | 376.6 ms | 8.1% | - |
| mandelbrot | 249.1 ms | 5.4% | - |
| sieve | 241.9 ms | 5.2% | 128 MB |
| objects | 206.7 ms | 4.5% | 161 MB |
| matrix | 94.9 ms | 2.1% | - |

## 5. Correctness check

All checksums matched between Lua and Node and across repeated runs.

| Benchmark | Checksum | Sanity check |
|---|---|---|
| fib | 9227465 | equals fib(35) |
| mandelbrot | 96625 | points that reach the iteration limit |
| collatz | 410011449 | start 410011, chain length 449 |
| matrix | 131400000 | sum of all result cells |
| sieve | 348513 | equals the prime count up to 5,000,000 |
| objects | 500999500000 | sum over 1,000,000 records |
| mergesort | 2145000386538 | sum of every 1000th sorted value (order also asserted) |
| hashmap | 250000000 | lookup sum plus remaining keys |
| bintrees | 8711918 | total node count visited |

## 6. Analysis

- **Node led in every scope measured** (full suite, CPU group, `fib`), by 3.1x to 4.7x. Per-benchmark Node timings were not captured, so this report cannot say which individual benchmark has the smallest or largest gap beyond `fib`.
- **Startup cost counts against Node.** Node typically takes tens of milliseconds to start, and that is included in all its figures. The `fib` ratio of 4.7x is therefore likely a lower bound on the compute-only gap.
- **Node uses more than one core.** Its CPU reading is 104 to 108% on the full run, and user plus system time (1.42 s and 1.45 s) exceeds wall time (1.31 s and 1.39 s) by about 0.06 to 0.11 s. That is consistent with background JIT compilation and garbage collection threads. Lua is single-threaded, and its user plus system time matches wall time.
- **Memory-heavy work costs both runtimes similar kernel time.** System time shows up only in the full run, at 0.12 to 0.15 s for both runtimes, which is consistent with page-fault and allocation overhead. That is about 3% of Lua's runtime but about 11% of Node's, because Node finishes the rest so much faster.
- **Run-to-run variation is small but real.** The second full run was 3.4% slower for Lua and 6.3% slower for Node.

## 7. Limitations

- Only two full runs per runtime, so the variation figures are rough. Use five or more runs and take the best or the median.
- Per-benchmark Node timings were not captured on this machine, so section 4.3 covers Lua only.
- Peak process memory was not measured in these external runs. The retained-heap column in 4.3 is Lua's own heap after a full collection.
- Whole-process timing includes startup, which matters most for very short runs such as `fib`.
- PUC Lua is an interpreter, while Node uses a JIT compiler. LuaJIT was not tested.
- Hardware details are not yet recorded (see section 2).

## 8. Conclusions and next steps

1. For compute-heavy or allocation-heavy work at this scale, Node was roughly 3 to 4x faster than Lua 5.4 in this suite.
2. Lua may still suit cases where startup time, footprint or embedding matter more than raw throughput. These runs do not measure that.
3. To complete the picture:
   - Fill in the environment table.
   - Capture Node's per-benchmark times and peak memory with `python compare.py default --runs 5`.
   - Capture peak memory by hand with `/usr/bin/time -v lua5.4 bench.lua default sieve` and the Node equivalent, reading "Maximum resident set size".
   - Add a LuaJIT run. It needs `//` replaced by `math.floor(a / b)` because LuaJIT implements Lua 5.1.

## 9. Reproduce

```bash
# whole suite, then one scope
time lua5.4 bench.lua default
time lua5.4 bench.lua default cpu
time lua5.4 bench.lua default fib

time node bench.js default
time node bench.js default cpu
time node bench.js default fib

# runner: one process per benchmark, wall time, peak RSS (Linux, macOS, WSL), checksum check
python compare.py default --runs 5
```

Use `time lua5.4 ...` with no pipe after `time`.

## Appendix: raw timing lines

```
lua5.4 bench.lua default       4.55s user 0.12s system 100% cpu 4.643 total
lua5.4 bench.lua default       4.63s user 0.14s system  99% cpu 4.801 total
lua5.4 bench.lua default cpu   1.72s user 0.00s system 100% cpu 1.720 total
lua5.4 bench.lua default fib   0.37s user 0.00s system  99% cpu 0.370 total

node bench.js default          1.28s user 0.14s system 108% cpu 1.306 total
node bench.js default          1.30s user 0.15s system 104% cpu 1.388 total
node bench.js default cpu      0.55s user 0.01s system 101% cpu 0.555 total
node bench.js default fib      0.08s user 0.00s system  99% cpu 0.079 total
```