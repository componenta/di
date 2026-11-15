from pathlib import Path
import argparse
import datetime
import hashlib
import json
import platform
import re
import statistics
import subprocess
import time

parser = argparse.ArgumentParser(description='Serial ABBA public-operation qualification for the CycleGuard prototype.')
parser.add_argument('--baseline-source', type=Path, required=True)
parser.add_argument('--candidate-source', type=Path, required=True)
parser.add_argument('--vendor', type=Path, required=True)
parser.add_argument('--php', action='append', required=True, help='Repeat for each PHP binary, processed sequentially.')
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--rounds', type=int, default=5)
parser.add_argument('--scale', type=int, default=32768)
args = parser.parse_args()
if args.rounds < 1:
    parser.error('Provide at least one round.')
root = Path(__file__).resolve().parent
worker = root / 'cycle-guard-benchmark.php'
loader = root / 'source-loader.php'
output = args.output.resolve()
output.mkdir(parents=True, exist_ok=True)
sha = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()

def source_record(directory):
    directory = directory.resolve()
    files = {p.relative_to(directory).as_posix(): sha(p) for p in sorted((directory / 'src').rglob('*.php'))}
    digest = hashlib.sha256()
    for path in files:
        digest.update(path.encode() + b'\0' + (directory / path).read_bytes() + b'\0')
    return {'path': str(directory), 'file_count': len(files), 'sha256': digest.hexdigest(), 'files': files}

sources = {'baseline': source_record(args.baseline_source), 'candidate': source_record(args.candidate_source)}
assert sources['baseline']['file_count'] == sources['candidate']['file_count'] == 169
changed = [p for p in sources['baseline']['files'] if sources['baseline']['files'][p] != sources['candidate']['files'].get(p)]
assert changed == ['src/Internal/CycleGuard.php'], changed
timed_sections = re.findall(r'// BEGIN TIMED (\w+)\n(.*?)    // END TIMED \1', worker.read_text(), re.S)
assert len(timed_sections) == 2
timed_digest = hashlib.sha256()
for name, body in timed_sections:
    timed_digest.update(name.encode() + b'\0' + body.encode() + b'\0')
evidence = {
    'status': 'running',
    'baseline_commit': '39eca842285ebbe650456a8fe0009102d353aa99',
    'created_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'source_fingerprint_algorithm': 'SHA-256 over sorted repo-relative src/... UTF-8 path + NUL + exact bytes + NUL for all PHP source files.',
    'sources': sources,
    'changed_paths': changed,
    'worker': {'path': str(worker), 'sha256': sha(worker)},
    'loader': {'path': str(loader), 'sha256': sha(loader)},
    'runner': {'path': str(Path(__file__).resolve()), 'sha256': sha(Path(__file__))},
    'timed_body': {'sha256': timed_digest.hexdigest(), 'algorithm': 'Concatenate marked section label + NUL + exact body bytes + NUL in file encounter order.', 'sections': [name for name, _ in timed_sections]},
    'platform': platform.platform(),
    'order': ['baseline', 'candidate', 'candidate', 'baseline'],
    'rounds': args.rounds,
    'scale': args.scale,
    'runs': [],
    'summaries': {},
}

for php in args.php:
    version = subprocess.check_output([php, '-n', '-r', 'echo PHP_VERSION;'], text=True).strip()
    tag = 'php' + ''.join(version.split('.')[:2])
    for round_index in range(1, args.rounds + 1):
        ratios = {}
        for position, variant in enumerate(evidence['order'], 1):
            label = f'{tag}-r{round_index}-{position}-{variant}'
            command = [php, '-n', str(worker), sources[variant]['path'], str(args.vendor.resolve()), str(args.scale)]
            started = datetime.datetime.now(datetime.timezone.utc).isoformat()
            tick = time.monotonic()
            run = subprocess.run(command, text=True, capture_output=True)
            duration = time.monotonic() - tick
            stdout = output / (label + '.stdout.json')
            stderr = output / (label + '.stderr.log')
            stdout.write_text(run.stdout)
            stderr.write_text(run.stderr)
            if run.returncode != 0:
                raise RuntimeError(f'{label} failed: {run.stdout}\n{run.stderr}')
            data = json.loads(run.stdout)
            assert data['worker_sha256'] == evidence['worker']['sha256']
            assert data['loader_sha256'] == evidence['loader']['sha256']
            assert data['source_root'] == sources[variant]['path']
            assert data['provenance']['cycle_guard_sha256'] == sources[variant]['files']['src/Internal/CycleGuard.php']
            assert all(data['checks'].values())
            record = {'php': version, 'variant': variant, 'round': round_index, 'position': position, 'command': command, 'started_at_utc': started, 'process_seconds': duration, 'exit_code': run.returncode, 'stdout': str(stdout), 'stdout_sha256': sha(stdout), 'stderr': str(stderr), 'stderr_sha256': sha(stderr), 'measurements': data['measurements'], 'provenance': {'declarations': len(data['provenance']['classes']), 'functions': len(data['provenance']['functions']), 'cycle_guard_sha256': data['provenance']['cycle_guard_sha256']}}
            evidence['runs'].append(record)
        round_runs = [r for r in evidence['runs'] if r['php'] == version and r['round'] == round_index]
        for scenario in round_runs[0]['measurements']:
            a = statistics.mean(r['measurements'][scenario]['nanoseconds_per_operation'] for r in round_runs if r['variant'] == 'baseline')
            b = statistics.mean(r['measurements'][scenario]['nanoseconds_per_operation'] for r in round_runs if r['variant'] == 'candidate')
            ratios[scenario] = 100 * (b / a - 1)
        print(json.dumps({'php': version, 'round': round_index, 'paired_change_percent': ratios}), flush=True)
        (output / 'evidence.json').write_text(json.dumps(evidence, indent=2) + '\n')
    version_runs = [r for r in evidence['runs'] if r['php'] == version]
    summary = {}
    for scenario in version_runs[0]['measurements']:
        a = [r['measurements'][scenario]['nanoseconds_per_operation'] for r in version_runs if r['variant'] == 'baseline']
        b = [r['measurements'][scenario]['nanoseconds_per_operation'] for r in version_runs if r['variant'] == 'candidate']
        paired = []
        for round_index in range(1, args.rounds + 1):
            ra = statistics.mean(r['measurements'][scenario]['nanoseconds_per_operation'] for r in version_runs if r['round'] == round_index and r['variant'] == 'baseline')
            rb = statistics.mean(r['measurements'][scenario]['nanoseconds_per_operation'] for r in version_runs if r['round'] == round_index and r['variant'] == 'candidate')
            paired.append(100 * (rb / ra - 1))
        summary[scenario] = {'baseline_median_ns': statistics.median(a), 'candidate_median_ns': statistics.median(b), 'change_ns': statistics.median(b) - statistics.median(a), 'change_percent_ratio_of_medians': 100 * (statistics.median(b) / statistics.median(a) - 1), 'paired_round_change_percent': paired, 'paired_round_median_change_percent': statistics.median(paired), 'baseline_worker_ns': a, 'candidate_worker_ns': b}
    evidence['summaries'][version] = summary

assert source_record(args.baseline_source) == sources['baseline']
assert source_record(args.candidate_source) == sources['candidate']
assert sha(worker) == evidence['worker']['sha256'] and sha(loader) == evidence['loader']['sha256']
evidence['status'] = 'passed'
evidence['completed_at_utc'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
(output / 'evidence.json').write_text(json.dumps(evidence, indent=2) + '\n')
(output / 'summary.json').write_text(json.dumps(evidence['summaries'], indent=2) + '\n')
print(json.dumps({'status': 'passed', 'workers': len(evidence['runs']), 'evidence': str(output / 'evidence.json')}), flush=True)
