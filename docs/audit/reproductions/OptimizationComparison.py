"""Run serial ABBA comparisons of immutable DI source trees; retain every sample."""

import argparse
import datetime
import hashlib
import json
from pathlib import Path
import statistics
import subprocess


def timestamp():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def identity(path):
    data = path.read_bytes()
    return {"path": str(path), "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}


def sources(path):
    files = sorted((path / "src").rglob("*.php"))
    digest = hashlib.sha256()
    hashes = {}
    for file in files:
        relative = file.relative_to(path).as_posix()
        data = file.read_bytes()
        digest.update(relative.encode() + b"\0" + data + b"\0")
        hashes[relative] = hashlib.sha256(data).hexdigest()
    return {"path": str(path), "count": len(files), "fingerprint": digest.hexdigest(), "files": hashes}


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--baseline", required=True, type=Path)
parser.add_argument("--candidate", required=True, type=Path)
parser.add_argument("--autoload", required=True, type=Path)
parser.add_argument("--php84", required=True, type=Path)
parser.add_argument("--php85", required=True, type=Path)
parser.add_argument("--output", required=True, type=Path)
parser.add_argument("--worker", type=Path, default=Path(__file__).with_name("OptimizationWorkloads.php"))
parser.add_argument("--rounds", type=int, default=5)
parser.add_argument("--iterations", type=int, default=10000)
parser.add_argument("--builds", type=int, default=100)
args = parser.parse_args()
if min(args.rounds, args.iterations, args.builds) < 1:
    parser.error("Rounds and operation counts must be positive.")
trees = {"baseline": args.baseline.resolve(strict=True), "candidate": args.candidate.resolve(strict=True)}
worker = args.worker.resolve(strict=True)
autoload = args.autoload.resolve(strict=True)
runtimes = {"php84": args.php84.resolve(strict=True), "php85": args.php85.resolve(strict=True)}
output = args.output.resolve()
output.mkdir(parents=True, exist_ok=False)
before = {key: sources(path) for key, path in trees.items()}
vendor = autoload.parent / "composer"
records = []
evidence = {
    "status": "running",
    "started_at_utc": timestamp(),
    "sources_before": before,
    "worker": identity(worker),
    "driver": identity(Path(__file__).resolve()),
    "runtimes": {key: identity(path) for key, path in runtimes.items()},
    "dependencies": {name: identity(vendor / name) for name in ["installed.json", "autoload_classmap.php", "autoload_psr4.php", "autoload_files.php"]},
    "loaders": {key: identity(path / "benchmarks/autoload.php") for key, path in trees.items()},
    "protocol": {
        "rounds_per_runtime": args.rounds,
        "order": ["baseline", "candidate", "candidate", "baseline"],
        "normal_iterations": args.iterations,
        "normal_warmup": min(1000, args.iterations),
        "build_iterations": args.builds,
        "build_warmup": min(10, args.builds),
        "aggregation": "Median of process samples per variant, ratio of those medians; each paired round compares its two baseline and two candidate means.",
        "timed_region": "Only the fixed loop calling the public operation and assigning its last result; setup, verification, metadata warmup and explicit GC are excluded.",
        "fiber": "One Fiber executes a complete warmed measurement; creating and starting the Fiber are outside its inner timer.",
        "build": "Fresh containers inside an already running and warmed PHP process; not process cold start or application startup.",
        "gc": "Collected before and disabled during each measured loop.",
        "controls": "Warm shared get is unchanged by the proposed optimizations. Results are local workload measurements, not application latency promises.",
    },
    "records": records,
}
if len({record["sha256"] for record in evidence["loaders"].values()}) != 1:
    raise RuntimeError("This comparison requires identical source loaders")


def save():
    (output / "evidence.json").write_text(json.dumps(evidence, indent=2) + "\n")


save()
try:
    for runtime, binary in runtimes.items():
        for round_number in range(1, args.rounds + 1):
            for position, variant in enumerate(evidence["protocol"]["order"], 1):
                name = f"{runtime}-r{round_number}-{position}-{variant}"
                command = [str(binary), "-n", "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0", str(worker), str(trees[variant]), str(autoload), str(args.iterations), str(args.builds)]
                started = timestamp()
                result = subprocess.run(command, cwd=trees[variant], capture_output=True)
                ended = timestamp()
                stdout = output / (name + ".stdout.json")
                stderr = output / (name + ".stderr.log")
                stdout.write_bytes(result.stdout)
                stderr.write_bytes(result.stderr)
                record = {"name": name, "runtime": runtime, "round": round_number, "position": position, "variant": variant, "command": command, "cwd": str(trees[variant]), "started_at_utc": started, "completed_at_utc": ended, "exit_code": result.returncode, "stdout": identity(stdout), "stderr": identity(stderr)}
                records.append(record)
                if result.returncode != 0:
                    raise RuntimeError(f"{name} failed with exit {result.returncode}")
                data = json.loads(result.stdout)
                checks = [json.loads(line)["benchmark_source_check"] for line in result.stderr.decode().splitlines() if line]
                if len(checks) != 1 or checks[0]["source"] != str(trees[variant] / "src") or checks[0]["checked_functions"] != 6 or checks[0]["checked_declarations"] < 100:
                    raise RuntimeError(f"{name} source origin verification failed")
                if data["source"] != str(trees[variant]) or data["opcache_enable_cli"] != "0" or data["ini"] is not False or len(data["samples"]) != 12:
                    raise RuntimeError(f"{name} runtime metadata mismatch")
                if Path(data["binary"]).resolve() != binary or Path(data["vendor_autoload"]).resolve() != autoload or data["jit"] not in ["0", "disable", "off", ""]:
                    raise RuntimeError(f"{name} binary, dependency or JIT mismatch")
                if not data["php"].startswith("8.4." if runtime == "php84" else "8.5."):
                    raise RuntimeError(f"{name} selected a different PHP series")
                for case, sample in data["samples"].items():
                    expected_iterations = args.builds if case == "build/default" else args.iterations
                    if sample["iterations"] != expected_iterations or sample["elapsed_ns"] <= 0 or sample["ns_per_operation"] != sample["elapsed_ns"] / expected_iterations:
                        raise RuntimeError(f"{name}: invalid operation count or timing arithmetic for {case}")
                record["result"] = data
                record["source_checks"] = checks[0]
                save()
                print(f"{name}: passed; all 12 results and source origins checked", flush=True)

    after = {key: sources(path) for key, path in trees.items()}
    evidence["sources_after"] = after
    evidence["identities_after"] = {
        "worker": identity(worker),
        "driver": identity(Path(__file__).resolve()),
        "runtimes": {key: identity(path) for key, path in runtimes.items()},
        "dependencies": {name: identity(vendor / name) for name in evidence["dependencies"]},
        "loaders": {key: identity(path / "benchmarks/autoload.php") for key, path in trees.items()},
    }
    save()
    if before != after or any(evidence[key] != value for key, value in evidence["identities_after"].items()):
        raise RuntimeError("Source, loader, dependency metadata, runtime or benchmark changed during comparison")
    summary = []
    for runtime in runtimes:
        group = [record for record in records if record["runtime"] == runtime]
        for case in group[0]["result"]["samples"]:
            values = {variant: [record["result"]["samples"][case]["ns_per_operation"] for record in group if record["variant"] == variant] for variant in trees}
            medians = {variant: statistics.median(samples) for variant, samples in values.items()}
            paired = []
            for round_number in range(1, args.rounds + 1):
                part = [record for record in group if record["round"] == round_number]
                means = {variant: statistics.mean(record["result"]["samples"][case]["ns_per_operation"] for record in part if record["variant"] == variant) for variant in trees}
                paired.append({"round": round_number, "baseline_mean_ns": means["baseline"], "candidate_mean_ns": means["candidate"], "delta_ns": means["candidate"] - means["baseline"], "change_percent": 100 * (means["candidate"] / means["baseline"] - 1)})
            summary.append({"runtime": runtime, "case": case, "baseline_median_ns": medians["baseline"], "candidate_median_ns": medians["candidate"], "ratio_of_medians_change_percent": 100 * (medians["candidate"] / medians["baseline"] - 1), "paired_rounds": paired, "faster_paired_rounds": sum(pair["delta_ns"] < 0 for pair in paired)})
    evidence["summary"] = summary
    evidence["status"] = "completed"
    evidence["completed_at_utc"] = timestamp()
    save()
    print(json.dumps({"status": "completed", "processes": len(records), "summary": summary}, indent=2), flush=True)
except BaseException:
    evidence["status"] = "failed"
    evidence["completed_at_utc"] = timestamp()
    save()
    raise
