-- bench.lua : CPU- and memory-intensive benchmarks (Lua 5.3 / 5.4)
-- Mirror of bench.js: same algorithms, same data, same checksums.
--
-- Usage: lua bench.lua [quick|default|heavy] [all|cpu|mem|<name>]
-- No timing happens inside this script. Time it from outside:
--   time lua5.4 bench.lua default            (everything)
--   time lua5.4 bench.lua default fib        (one benchmark)
-- Names: fib mandelbrot collatz matrix sieve objects mergesort hashmap bintrees

assert(_VERSION >= "Lua 5.3", "bench.lua needs Lua 5.3+ (integer division, bit shifts)")

local PRESETS = {
    --            fib  mandel collatz   matrix sieve      objects   sort      hash     trees
    quick   = { fib = 30, mandel = 200, collatz = 100000,  matrix = 100, sieve = 1000000,  objects = 200000,  sort = 200000,  hash = 100000,  trees = 12 },
    default = { fib = 35, mandel = 500, collatz = 500000,  matrix = 200, sieve = 5000000,  objects = 1000000, sort = 1000000, hash = 500000,  trees = 16 },
    heavy   = { fib = 38, mandel = 800, collatz = 1000000, matrix = 300, sieve = 10000000, objects = 2000000, sort = 2000000, hash = 1000000, trees = 18 },
}

local preset = (arg and arg[1]) or "default"
local cfg = PRESETS[preset]
if not cfg then
    io.stderr:write("unknown preset '" .. preset .. "' (use quick, default or heavy)\n")
    os.exit(1)
end

---------------------------------------------------------------------
-- Shared helpers
---------------------------------------------------------------------

-- Deterministic LCG so both languages sort/hash identical data.
-- x * 1664525 stays below 2^53, so JS doubles are exact too.
local function make_rng(seed)
    local x = seed
    return function()
        x = (x * 1664525 + 1013904223) % 4294967296
        return x
    end
end

---------------------------------------------------------------------
-- CPU-intensive benchmarks
---------------------------------------------------------------------

-- 1. Recursive Fibonacci: function-call overhead
local function fib(n)
    if n < 2 then return n end
    return fib(n - 1) + fib(n - 2)
end
local function bench_fib(n)
    return fib(n)
end

-- 2. Mandelbrot: tight floating-point loops
local function bench_mandelbrot(size)
    local max_iter, count = 100, 0
    for py = 0, size - 1 do
        local ci = 2.0 * py / size - 1.0
        for px = 0, size - 1 do
            local cr = 2.0 * px / size - 1.5
            local zr, zi, i = 0.0, 0.0, 0
            while i < max_iter and zr * zr + zi * zi <= 4.0 do
                zr, zi = zr * zr - zi * zi + cr, 2.0 * zr * zi + ci
                i = i + 1
            end
            if i == max_iter then count = count + 1 end
        end
    end
    return count
end

-- 3. Collatz: longest chain for any start below `limit` (integer math)
local function bench_collatz(limit)
    local best_n, best_len = 1, 1
    for start = 1, limit - 1 do
        local n, len = start, 1
        while n ~= 1 do
            if n % 2 == 0 then n = n // 2 else n = 3 * n + 1 end
            len = len + 1
        end
        if len > best_len then best_len, best_n = len, start end
    end
    return best_n * 1000 + best_len
end

-- 4. Naive matrix multiplication (nested tables, indexed access)
local function bench_matrix(n)
    local A, B, C = {}, {}, {}
    for i = 1, n do
        local a, b, c = {}, {}, {}
        for j = 1, n do
            a[j] = ((i - 1) * (j - 1)) % 10
            b[j] = ((i - 1) + (j - 1)) % 10
            c[j] = 0
        end
        A[i], B[i], C[i] = a, b, c
    end
    for i = 1, n do
        local Ai, Ci = A[i], C[i]
        for j = 1, n do
            local s = 0
            for k = 1, n do s = s + Ai[k] * B[k][j] end
            Ci[j] = s
        end
    end
    local sum = 0
    for i = 1, n do
        local Ci = C[i]
        for j = 1, n do sum = sum + Ci[j] end
    end
    return sum
end

---------------------------------------------------------------------
-- Memory-intensive benchmarks (return a checksum)
---------------------------------------------------------------------

-- 5. Sieve of Eratosthenes over a big boolean array
local function bench_sieve(n)
    local sieve = {}
    for i = 1, n do sieve[i] = true end
    sieve[1] = false
    local i = 2
    while i * i <= n do
        if sieve[i] then
            for j = i * i, n, i do sieve[j] = false end
        end
        i = i + 1
    end
    local count = 0
    for k = 1, n do
        if sieve[k] then count = count + 1 end
    end
    return count
end

-- 6. Allocate many small records, then walk them
local function bench_objects(n)
    local list = {}
    for i = 1, n do
        list[i] = { id = i, a = i % 1000, b = (i * 7) % 1000 }
    end
    local sum = 0
    for i = 1, n do
        local o = list[i]
        sum = sum + o.id + o.a + o.b
    end
    return sum
end

-- 7. Merge sort (own implementation, with auxiliary array)
local function bench_mergesort(n)
    local rng = make_rng(12345)
    local a, b = {}, {}
    for i = 1, n do
        a[i] = rng()
        b[i] = 0
    end

    local function sort(lo, hi)
        if hi - lo < 1 then return end
        local mid = (lo + hi) // 2
        sort(lo, mid)
        sort(mid + 1, hi)
        local i, j, k = lo, mid + 1, lo
        while i <= mid and j <= hi do
            if a[i] <= a[j] then
                b[k] = a[i]; i = i + 1
            else
                b[k] = a[j]; j = j + 1
            end
            k = k + 1
        end
        while i <= mid do b[k] = a[i]; i = i + 1; k = k + 1 end
        while j <= hi do b[k] = a[j]; j = j + 1; k = k + 1 end
        for t = lo, hi do a[t] = b[t] end
    end
    sort(1, n)

    for i = 2, n do
        assert(a[i - 1] <= a[i], "sort failed")
    end
    local sum = 0
    for i = 1, n, 1000 do sum = sum + a[i] end
    return sum
end

-- 8. Hash map with string keys: insert, lookup, delete half
local function bench_hash(n)
    local map = {}
    for i = 1, n do map["key" .. i] = i % 1000 end
    local sum = 0
    for i = 1, n do sum = sum + map["key" .. i] end
    for i = 2, n, 2 do map["key" .. i] = nil end
    local remaining = 0
    for _ in pairs(map) do remaining = remaining + 1 end
    return sum + remaining
end

-- 9. Binary trees: allocation churn that stresses the garbage collector
local function bottom_up(d)
    if d == 0 then return {} end
    d = d - 1
    return { left = bottom_up(d), right = bottom_up(d) }
end
local function check(t)
    if t.left then return 1 + check(t.left) + check(t.right) end
    return 1
end
local function bench_trees(max_depth)
    local total = check(bottom_up(max_depth + 1))      -- stretch tree
    local long_lived = bottom_up(max_depth)
    for d = 4, max_depth, 4 do
        local iterations = 1 << (max_depth - d + 4)
        local c = 0
        for _ = 1, iterations do c = c + check(bottom_up(d)) end
        total = total + c
    end
    total = total + check(long_lived)
    return total
end

---------------------------------------------------------------------
-- Harness (no timing here)
---------------------------------------------------------------------
local BENCHES = {
    { "fib",        "cpu", function() return bench_fib(cfg.fib) end },
    { "mandelbrot", "cpu", function() return bench_mandelbrot(cfg.mandel) end },
    { "collatz",    "cpu", function() return bench_collatz(cfg.collatz) end },
    { "matrix",     "cpu", function() return bench_matrix(cfg.matrix) end },
    { "sieve",      "mem", function() return bench_sieve(cfg.sieve) end },
    { "objects",    "mem", function() return bench_objects(cfg.objects) end },
    { "mergesort",  "mem", function() return bench_mergesort(cfg.sort) end },
    { "hashmap",    "mem", function() return bench_hash(cfg.hash) end },
    { "bintrees",   "mem", function() return bench_trees(cfg.trees) end },
}

local which = (arg and arg[2]) or "all"
local selected = {}
for _, b in ipairs(BENCHES) do
    if which == "all" or which == b[1] or which == b[2] then
        selected[#selected + 1] = b
    end
end
if #selected == 0 then
    io.stderr:write("unknown benchmark '" .. which .. "' (use all, cpu, mem or a name)\n")
    os.exit(1)
end

print(string.format("# %s | preset=%s | %s", _VERSION, preset, which))
for _, b in ipairs(selected) do
    print(string.format("%-12s %16d", b[1], b[3]()))
end