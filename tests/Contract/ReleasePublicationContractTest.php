<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ReleasePublication;

use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('release publication validates the tested tag and published release state', function (
    string $scenario,
    bool $prerelease,
    bool $published,
): void {
    $git = new ExecutableFinder()->find('git');
    if (!is_executable('/bin/bash') || $git === null) {
        Assert::markTestSkipped('The release workflow runs with Bash and Git on Linux.');
    }

    $directory = sys_get_temp_dir() . '/di-publish-' . bin2hex(random_bytes(8));
    $bare = $directory . '/origin.git';
    $checkout = $directory . '/checkout';
    $bin = $directory . '/bin';
    $release = $directory . '/release';
    $draft = $directory . '/draft';
    $arguments = $directory . '/release-arguments';
    $tag = $prerelease ? 'v6.0.0.RC1' : 'v6.0.0';
    $existingDraft = str_starts_with($scenario, 'draft-present-');
    $wrongPrerelease = str_ends_with($scenario, '-wrong-prerelease');
    $filesystem = new Filesystem();
    $filesystem->mkdir([$checkout, $bin]);
    $gitEnvironment = ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'];
    $runGit = static fn(string ...$args): string => trim(
        new Process([$git, '-c', 'user.name=Release Test', '-c', 'user.email=release@example.invalid', ...$args], $checkout, $gitEnvironment)
            ->mustRun()->getOutput(),
    );

    try {
        $runGit('init', '--quiet', '--bare', $bare);
        $runGit('init', '--quiet');
        $runGit('commit', '--quiet', '--allow-empty', '-m', 'Tested commit');
        $tested = $runGit('rev-parse', 'HEAD');
        $runGit('commit', '--quiet', '--allow-empty', '-m', 'Other commit');
        $other = $runGit('rev-parse', 'HEAD');
        $runGit('remote', 'add', 'origin', $bare);
        $runGit('push', '--quiet', 'origin', 'HEAD:refs/heads/main');
        if ($scenario === 'existing-other') {
            $runGit('--git-dir', $bare, 'tag', $tag, $other);
        }
        if ($existingDraft) {
            $filesystem->dumpFile($release, $tested);
            $filesystem->dumpFile($draft, '');
        }
        if ($scenario === 'existing-wrong-prerelease') {
            $runGit('--git-dir', $bare, 'tag', $tag, $tested);
            $filesystem->dumpFile($release, $tested);
        }
        if ($scenario === 'draft-present-with-tag') {
            $runGit('--git-dir', $bare, 'tag', $tag, $tested);
        }

        $filesystem->dumpFile($bin . '/git', <<<'BASH'
            #!/bin/bash
            set -euo pipefail
            if [[ "$1" == push ]]; then
              case "$SCENARIO" in
                concurrent-other)
                  "$REAL_GIT" --git-dir "$ORIGIN" tag "$TAG" "$OTHER_COMMIT"
                  ;;
                concurrent-annotated)
                  "$REAL_GIT" --git-dir "$ORIGIN" -c user.name=Test -c user.email=test@example.invalid \
                    tag -am 'Concurrent annotation' "$TAG" "$GITHUB_SHA"
                  ;;
                push-failure)
                  printf 'Tag creation rejected.\n' >&2
                  exit 1
                  ;;
              esac
            fi
            exec "$REAL_GIT" "$@"
            BASH);
        $filesystem->dumpFile($bin . '/gh', <<<'BASH'
            #!/bin/bash
            set -euo pipefail
            [[ "$1" == release && "$3" == "$TAG" ]]
            if [[ "$2" == view ]]; then
              if [[ "$SCENARIO" == changed-before-create ]]; then
                "$REAL_GIT" --git-dir "$ORIGIN" tag --force "$TAG" "$OTHER_COMMIT"
              fi
              [[ -f "$RELEASE_RESULT" ]]
              draft=false
              [[ ! -f "$RELEASE_DRAFT" ]] || draft=true
              [[ "$#" == 7 && "$4" == --json && "$6" == --jq ]]
              case "$5" in
                isDraft) printf '%s\n' "$draft" ;;
                isDraft,isPrerelease) printf '%s %s\n' "$draft" "$RELEASE_PRERELEASE" ;;
                *) exit 1 ;;
              esac
              exit
            fi
            [[ "$2" == create ]]
            printf '%s\n' "$@" > "$RELEASE_ARGUMENTS"
            "$REAL_GIT" --git-dir "$ORIGIN" rev-parse --verify "refs/tags/$TAG^{commit}" > "$RELEASE_RESULT"
            if [[ "$SCENARIO" == draft-during-create ]]; then
              touch "$RELEASE_DRAFT"
              exit 1
            fi
            [[ "$SCENARIO" != concurrent-release && "$SCENARIO" != concurrent-wrong-prerelease ]]
            BASH);
        $filesystem->chmod([$bin . '/git', $bin . '/gh'], 0700);

        $environment = $gitEnvironment + [
            'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
            'GITHUB_SHA' => $tested,
            'COMMIT_MESSAGE' => "Release $tag",
            'REAL_GIT' => $git,
            'ORIGIN' => $bare,
            'OTHER_COMMIT' => $other,
            'TAG' => $tag,
            'SCENARIO' => $scenario,
            'RELEASE_RESULT' => $release,
            'RELEASE_DRAFT' => $draft,
            'RELEASE_ARGUMENTS' => $arguments,
            'RELEASE_PRERELEASE' => $prerelease !== $wrongPrerelease ? 'true' : 'false',
        ];
        $publish = ['/bin/bash', dirname(__DIR__, 2) . '/.github/scripts/publish-release.sh'];
        $process = new Process($publish, $checkout, $environment);
        $process->run();

        if ($published) {
            expect($process->getExitCode())->toBe(0)
                ->and(trim($filesystem->readFile($release)))->toBe($tested);
            $flags = explode("\n", trim($filesystem->readFile($arguments)));
            expect($flags)->toContain('--verify-tag', '--target', $tested)
                ->and(in_array('--prerelease', $flags, true))->toBe($prerelease);
            if ($scenario === 'concurrent-annotated') {
                expect($runGit('--git-dir', $bare, 'cat-file', '-t', "refs/tags/$tag"))->toBe('tag');
            }
            $rerun = new Process($publish, $checkout, $environment);
            $rerun->mustRun();
            expect($rerun->getOutput())->toContain('already exists');
        } elseif ($existingDraft || $scenario === 'draft-during-create') {
            expect($process->getExitCode())->toBe(1)
                ->and($process->getErrorOutput())->toContain('is still a draft')
                ->and($process->getOutput())->not->toContain('already exists')
                ->and(file_exists($arguments))->toBe(!$existingDraft)
                ->and(file_exists($draft))->toBeTrue();
            $ref = "refs/tags/$tag";
            expect($runGit('--git-dir', $bare, 'for-each-ref', '--format=%(objectname)', $ref))
                ->toBe($scenario === 'draft-present-no-tag' ? '' : $tested);
        } elseif ($wrongPrerelease) {
            $expected = $prerelease ? 'true' : 'false';
            $actual = $prerelease ? 'false' : 'true';
            expect($process->getExitCode())->toBe(1)
                ->and($process->getErrorOutput())->toContain("has prerelease status $actual, expected $expected")
                ->and($process->getOutput())->not->toContain('already exists')
                ->and(file_exists($arguments))->toBe($scenario === 'concurrent-wrong-prerelease');
        } else {
            expect($process->getExitCode())->toBe(1)
                ->and($process->getErrorOutput())->toContain('expected tested commit')
                ->and(file_exists($arguments))->toBeFalse()
                ->and(file_exists($release))->toBeFalse();
            if ($scenario !== 'push-failure') {
                expect($runGit('--git-dir', $bare, 'rev-parse', "refs/tags/$tag"))->toBe($other);
            }
        }
    } finally {
        $filesystem->remove($directory);
    }
})->with([
    'new stable tag' => ['new', false, true],
    'new prerelease tag' => ['new', true, true],
    'existing different tag' => ['existing-other', false, false],
    'different concurrent tag' => ['concurrent-other', false, false],
    'matching concurrent annotated tag' => ['concurrent-annotated', true, true],
    'tag changed during release lookup' => ['changed-before-create', false, false],
    'concurrent successful publish' => ['concurrent-release', false, true],
    'concurrent successful prerelease' => ['concurrent-release', true, true],
    'existing stable tag marked prerelease' => ['existing-wrong-prerelease', false, false],
    'existing prerelease tag marked stable' => ['existing-wrong-prerelease', true, false],
    'concurrent stable tag marked prerelease' => ['concurrent-wrong-prerelease', false, false],
    'concurrent prerelease tag marked stable' => ['concurrent-wrong-prerelease', true, false],
    'tag creation failure' => ['push-failure', false, false],
    'existing draft with no tag' => ['draft-present-no-tag', false, false],
    'existing draft with a tested tag' => ['draft-present-with-tag', false, false],
    'concurrent draft during creation' => ['draft-during-create', false, false],
]);
