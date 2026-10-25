#!/usr/bin/env python3
"""Summarise a bench.sh results folder into markdown tables."""
import csv, json, statistics as st, sys
from collections import defaultdict
from pathlib import Path

out = Path(sys.argv[1])
names = [n for n in (out / "names.txt").read_text().splitlines() if n]
benches = (out / "benches.txt").read_text().split()
base = names[0]
NOISY = 0.05    # coefficient of variation above this is flagged with '!'
SHORT = 0.100   # node net time below this (s): ratio is only indicative, flagged with '*'

def results(path):
    return json.loads(Path(path).read_text())["results"]

def stats(r):
    t = r["times"]
    mean = st.mean(t)
    return {"median": st.median(t), "min": min(t), "cv": (st.stdev(t) / mean) if len(t) > 1 and mean else 0.0}

startup = {n: st.median(r["times"]) for n, r in zip(names, results(out / "startup.json"))}
data = {b: {n: stats(r) for n, r in zip(names, results(out / f"time-{b}.json"))} for b in benches}

mem = defaultdict(list)
with open(out / "mem.csv") as f:
    for row in csv.DictReader(f):
        mem[(row["bench"], row["name"])].append(int(row["kb"]))

def net(b, n):
    return max(data[b][n]["median"] - startup[n], 0.001)

lines = []
def emit(s=""):
    lines.append(s)

emit("# Benchmark results\n")
emit((out / "env.txt").read_text().replace("\n", "  \n"))
emit(f"\nStartup floor (median, empty program): " + ", ".join(f"{n} {startup[n]*1000:.0f} ms" for n in names) + "\n")

emit("## 1. Wall time, median ms (CV%)  [! = CV above 5%, rerun on an idle machine]\n")
emit("| benchmark | " + " | ".join(names) + " |")
emit("|---|" + "---|" * len(names))
tot = {n: 0.0 for n in names}
for b in benches:
    cells = []
    for n in names:
        s = data[b][n]; tot[n] += s["median"]
        cells.append(f"{s['median']*1000:,.0f} ({s['cv']*100:.1f}%){' !' if s['cv'] > NOISY else ''}")
    emit(f"| {b} | " + " | ".join(cells) + " |")
emit("| **suite total** | " + " | ".join(f"**{tot[n]*1000:,.0f}**" for n in names) + " |\n")

emit(f"## 2. Time relative to {base}, startup subtracted (below 1.00 = faster than {base})  [* = {base} net under 100 ms, indicative only]\n")
emit("| benchmark | " + " | ".join(names[1:]) + " |")
emit("|---|" + "---|" * (len(names) - 1))
nettot = {n: 0.0 for n in names}
for b in benches:
    short = net(b, base) < SHORT
    for n in names: nettot[n] += net(b, n)
    emit(f"| {b}{' *' if short else ''} | " + " | ".join(f"{net(b, n)/net(b, base):.2f}x" for n in names[1:]) + " |")
emit("| **suite total** | " + " | ".join(f"**{nettot[n]/nettot[base]:.2f}x**" for n in names[1:]) + " |\n")

emit("## 3. Best-of-N (min) wall time, ms, as a second opinion on the median\n")
emit("| benchmark | " + " | ".join(names) + " |")
emit("|---|" + "---|" * len(names))
for b in benches:
    emit(f"| {b} | " + " | ".join(f"{data[b][n]['min']*1000:,.0f}" for n in names) + " |")
emit()

emit("## 4. Peak memory (max RSS, MB, median of runs)\n")
emit("| benchmark | " + " | ".join(names) + " |")
emit("|---|" + "---|" * len(names))
for b in benches:
    emit(f"| {b} | " + " | ".join(f"{st.median(mem[(b, n)])/1024:,.1f}" for n in names) + " |")
emit()

text = "\n".join(lines)
(out / "results.md").write_text(text)
print(text)