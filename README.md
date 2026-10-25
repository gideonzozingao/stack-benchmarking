# Benchmark Reports

Reproducible, language-vs-language benchmark reports. Each report runs the **same algorithms on the same data** in two or more languages, times them **from the outside**, and publishes the raw numbers next to the analysis.

## Reports

| Date | Comparison | Suite | Headline result | Report |
|---|---|---|---|---|
| 2026-10-25 | Lua 5.4 vs Node.js v22.22.3 | 9 benchmarks: 4 CPU-bound, 5 memory-intensive | Node about 3.5x faster overall (mean 1.35 s vs 4.72 s) | [reports/2026-10-25-lua-vs-nodejs](reports/2026-10-25-lua-vs-nodejs/README.md) |

New reports are added as a row at the top of this table, newest first.

## Ground rules

Every report in this repo follows the same rules, so results stay comparable and honest.

1. **Same work, proven.** Each language implements the same algorithm with the same seeded data. Every benchmark prints a checksum, and a report is only valid if the checksums match across languages.
2. **No timers inside the programs.** Programs only compute and print. Timing comes from outside: the shell `time` builtin, `/usr/bin/time -v`, or a runner script. In-script timers, GC calls and memory probes distort the thing being measured.
3. **One process per measurement.** Time the whole suite and, where useful, each benchmark in its own process, so startup cost is visible and not hidden.
4. **Repeat and take the best.** Run each measurement at least 3 times (5 preferred) and report the best or the median, and say which.
5. **Record the environment.** CPU model, core count, RAM, OS (or WSL), runtime versions, and the exact commands.
6. **Report limitations.** Startup cost, JIT warm-up, single-machine results and anything not measured belong in the report.
7. **Ratios are `slower / faster`.** State which side is which in every table.

Results are specific to the machine they were run on. Compare numbers across reports only if the environment is the same.

## Repository layout

```
benchmark-reports/
├── README.md                          this file: index and ground rules
└── reports/
    └── YYYY-MM-DD-<a>-vs-<b>/
        ├── README.md                  the report: setup, results, analysis, limitations
        ├── bench.<ext>                one program per language
        └── compare.py                 runner that times each benchmark externally
```

## Benchmark contract

Each language's program follows the same interface, so one runner can drive any pair.

**Arguments:** `[preset] [selector]`

| Argument | Values | Default |
|---|---|---|
| `preset` | `quick`, `default`, `heavy` | `default` |
| `selector` | `all`, `cpu`, `mem`, or one benchmark name | `all` |

**Output:** one line per benchmark, `<name>` then an integer checksum, plus optional `#` comment lines.

```
# Lua 5.4 | preset=default | all
fib                   9227465
mandelbrot              96625
```

**Standard suite** (parameters per preset):

| Benchmark | Type | quick | default | heavy |
|---|---|---|---|---|
| `fib` (recursive) | CPU | 30 | 35 | 38 |
| `mandelbrot` (size, 100 iterations) | CPU | 200 | 500 | 800 |
| `collatz` (starts below N) | CPU | 100,000 | 500,000 | 1,000,000 |
| `matrix` (N x N multiply) | CPU | 100 | 200 | 300 |
| `sieve` (primes up to N) | Memory | 1,000,000 | 5,000,000 | 10,000,000 |
| `objects` (records allocated) | Memory | 200,000 | 1,000,000 | 2,000,000 |
| `mergesort` (own implementation, N integers) | Memory | 200,000 | 1,000,000 | 2,000,000 |
| `hashmap` (string keys) | Memory | 100,000 | 500,000 | 1,000,000 |
| `bintrees` (max depth) | Memory | 12 | 16 | 18 |

Pseudo-random data uses a shared linear congruential generator (`x = (x * 1664525 + 1013904223) % 2^32`, seed 12345), so every language sorts identical input.

## Adding a new benchmark

1. Create `reports/YYYY-MM-DD-<a>-vs-<b>/`.
2. Implement the standard suite in each language, following the contract above. Use each language's natural idiom (plain arrays and maps, not specialised typed structures), and keep algorithms identical.
3. Confirm the checksums match across languages on the `quick` preset first.
4. Adapt `compare.py`, or copy it from an earlier report, to run the new commands.
5. Run on `default` with at least 3 runs and capture the raw `time` lines.
6. Write the report `README.md` using the same sections as the existing report: Summary, Environment, Test setup, Results, Correctness check, Analysis, Limitations, Conclusions, Appendix (raw timings).
7. Add a row to the **Reports** table above.

## Reproducing the latest report

```bash
cd reports/2026-10-25-lua-vs-nodejs

# time by hand
time lua5.4 bench.lua default
time node bench.js default

# or let the runner time every benchmark in its own process
python compare.py default --runs 5
```

Requirements: Lua 5.3 or newer (the code uses `//` and bit shifts), Node.js 16 or newer, Python 3 for the runner.# stack-benchmarking
# stack-benchmarking
