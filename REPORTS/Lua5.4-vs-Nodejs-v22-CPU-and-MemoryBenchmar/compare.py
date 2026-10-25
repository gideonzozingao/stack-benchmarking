#!/usr/bin/env python3
"""Time bench.lua vs bench.js from the OUTSIDE (one process per benchmark).

The scripts contain no timers. This runner measures, per benchmark:
  * wall-clock time of the whole process (includes interpreter startup)
  * peak resident memory (Linux/macOS/WSL only; "-" on native Windows)

Usage:
    python compare.py [quick|default|heavy] [--runs N] [--lua CMD] [--node CMD]

Examples:
    python compare.py quick
    python compare.py default --runs 3     # best-of-3 per benchmark
    python compare.py heavy --lua lua5.4
"""
import argparse
import os
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
LINE = re.compile(r"^(\w+)\s+(-?\d+)\s*$")
ORDER = ["fib", "mandelbrot", "collatz", "matrix",
         "sieve", "objects", "mergesort", "hashmap", "bintrees"]
CPU = set(ORDER[:4])


def find_lua(explicit):
    if explicit:
        return explicit
    for cand in ("lua5.4", "lua5.3", "lua54", "lua53", "lua"):
        if shutil.which(cand):
            return cand
    sys.exit("No Lua interpreter found on PATH (install lua5.4 or pass --lua).")


def run_proc(cmd):
    """Return (stdout, wall_seconds, peak_rss_mb or None)."""
    t0 = time.perf_counter()
    if hasattr(os, "wait4"):                       # Linux, macOS, WSL
        p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             text=True, cwd=HERE)
        _, status, ru = os.wait4(p.pid, 0)
        wall = time.perf_counter() - t0
        out, err = p.stdout.read(), p.stderr.read()
        code = os.WEXITSTATUS(status) if os.WIFEXITED(status) else -1
        rss = ru.ru_maxrss / (1024 * 1024 if sys.platform == "darwin" else 1024)
    else:                                          # native Windows
        r = subprocess.run(cmd, capture_output=True, text=True, cwd=HERE)
        wall = time.perf_counter() - t0
        out, err, code, rss = r.stdout, r.stderr, r.returncode, None
    if code != 0:
        sys.exit(f"{' '.join(cmd)} failed:\n{out}\n{err}")
    return out, wall, rss


def measure(cmd, runs):
    """Best (minimum) wall time over `runs`; checksum and RSS from that run."""
    best = None
    for _ in range(runs):
        out, wall, rss = run_proc(cmd)
        checksum = None
        for line in out.splitlines():
            m = LINE.match(line.strip())
            if m:
                checksum = m.group(2)
        if best is None or wall < best[0]:
            best = (wall, checksum, rss)
    return best


def fmt_rss(v):
    return "-" if v is None else f"{v:.0f}MB"


def faster(a, b):
    """a = Lua time, b = Node time."""
    return f"Node {a / b:5.1f}x" if a >= b else f"Lua {b / a:5.1f}x"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("preset", nargs="?", default="default",
                    choices=["quick", "default", "heavy"])
    ap.add_argument("--runs", type=int, default=1)
    ap.add_argument("--lua")
    ap.add_argument("--node", default="node")
    args = ap.parse_args()

    lua = find_lua(args.lua)
    if not shutil.which(args.node):
        sys.exit("node not found on PATH.")

    for label, cmd in (("Lua ", [lua, "-v"]), ("Node", [args.node, "-v"])):
        r = subprocess.run(cmd, capture_output=True, text=True)
        print(f"{label}: {(r.stdout or r.stderr).strip()}")
    print(f"Preset: {args.preset} | one process per benchmark | "
          f"best of {args.runs} run(s)\n")

    # Interpreter startup cost, reported so you can judge how much it matters.
    lua_idle = measure([lua, "-e", ""], max(args.runs, 3))[0]
    node_idle = measure([args.node, "-e", ""], max(args.runs, 3))[0]

    head = (f"{'benchmark':<12}{'Lua s':>9}{'Node s':>9}{'faster':>15}"
            f"{'Lua RSS':>10}{'Node RSS':>10}  check")
    print(head)
    print("-" * len(head))

    lua_total = node_total = 0.0
    group, mismatch = None, False
    for name in ORDER:
        g = "CPU" if name in CPU else "MEM"
        if g != group:
            print(f"[{g}]")
            group = g
        lw, lchk, lrss = measure([lua, "bench.lua", args.preset, name], args.runs)
        nw, nchk, nrss = measure([args.node, "bench.js", args.preset, name], args.runs)
        lua_total += lw
        node_total += nw
        ok = "ok" if lchk == nchk else f"MISMATCH ({lchk} vs {nchk})"
        mismatch |= lchk != nchk
        print(f"{name:<12}{lw:>9.3f}{nw:>9.3f}{faster(lw, nw):>15}"
              f"{fmt_rss(lrss):>10}{fmt_rss(nrss):>10}  {ok}", flush=True)

    print("-" * len(head))
    print(f"{'TOTAL':<12}{lua_total:>9.3f}{node_total:>9.3f}"
          f"{faster(lua_total, node_total):>15}")
    print(f"\nEmpty-script startup: Lua {lua_idle * 1000:.0f} ms | "
          f"Node {node_idle * 1000:.0f} ms (already included in the times above)")
    if mismatch:
        print("\nWARNING: checksums differ, so the programs did not compute the "
              "same result. Timings are not comparable.")


if __name__ == "__main__":
    main()