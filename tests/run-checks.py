# -*- coding: utf-8 -*-
"""ManduVPN: the one entry point that runs every committed check in tests/.

  python -I tests/run-checks.py

ADDING A CHECKER MUST NOT REQUIRE REMEMBERING TO WIRE IT. So this does not hold a list - a list is the
thing that gets forgotten. It DISCOVERS checkers by filename, and the naming convention IS the wiring:

  tests/*.check.cjs     a Node checker          -> run with node
  tests/verify-*.py     a Python verifier       -> run with python
  tests/php_probe.py    the shared probes       -> run its own self-test
  tests/*-fixtures.php  a PHP fixture           -> run with php IF a php binary exists

Name a new checker to match one of those and it runs here with no edit to this file. Name it something
else and it will not, which is the one failure mode of discovery - so the convention is stated above and
this file reports what it found, so a checker that is present but unmatched is visible as absent from the
list rather than silently skipped.

WHY NOT CI: there is no test workflow in this repository, and the only workflow present is one we are not
permitted to touch. This is the human trigger, and it is deliberately NOT filed as an application-day
item: everything it runs needs no database, no .env and no PHP except the fixtures, so it belongs in the
set of things that must be green TODAY. Filing a runnable check under a day that may never arrive is how
a pin becomes a comment.

Exit codes, and they are not collapsed:
  0  every discovered check ran and passed
  1  at least one check FAILED
  2  nothing failed, but at least one check COULD NOT RUN - no verdict, never reported as a pass
"""
import glob
import os
import subprocess
import sys

TESTS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(TESTS, '..'))


def which(cmd):
    for d in os.environ.get('PATH', '').split(os.pathsep):
        for ext in ('', '.exe', '.cmd', '.bat'):
            p = os.path.join(d, cmd + ext)
            if os.path.isfile(p):
                return p
    return None


def discover():
    """(label, argv, why_not) for every check this convention knows how to run."""
    found = []
    node = which('node')
    php = which('php')

    for path in sorted(glob.glob(os.path.join(TESTS, '*.check.cjs'))):
        rel = os.path.relpath(path, ROOT).replace('\\', '/')
        found.append((rel, [node, path] if node else None, None if node else 'no node binary on PATH'))

    for path in sorted(glob.glob(os.path.join(TESTS, 'verify-*.py'))):
        rel = os.path.relpath(path, ROOT).replace('\\', '/')
        found.append((rel, [sys.executable, '-I', path], None))

    probe = os.path.join(TESTS, 'php_probe.py')
    if os.path.isfile(probe):
        rel = os.path.relpath(probe, ROOT).replace('\\', '/')
        found.append((rel + ' (self-test)', [sys.executable, '-I', probe], None))

    for path in sorted(glob.glob(os.path.join(TESTS, '*-fixtures.php'))):
        rel = os.path.relpath(path, ROOT).replace('\\', '/')
        # The fixtures are dependency-free by design, so a bare php binary is enough - but this cell has
        # none, which is exactly why the other checkers exist. Not having one is "could not run".
        found.append((rel, [php, path] if php else None, None if php else 'no php binary on PATH'))

    return found


def main():
    checks = discover()
    if not checks:
        print('ABORT  no checks discovered in tests/ - the naming convention in this file says what is picked up')
        return 2

    failed, unrun, passed = [], [], []
    for label, argv, why_not in checks:
        if argv is None:
            unrun.append((label, why_not))
            print('NOT RUN  %-52s %s' % (label, why_not))
            continue
        r = subprocess.run(argv, cwd=ROOT, capture_output=True, text=True)
        out = (r.stdout or '') + (r.stderr or '')
        if r.returncode == 0:
            passed.append(label)
            print('PASS     %s' % label)
        elif r.returncode == 2:
            unrun.append((label, 'the check itself gave no verdict (exit 2)'))
            print('NO VERDICT  %s' % label)
            print(''.join('           %s\n' % l for l in out.strip().splitlines()[:12]))
        else:
            failed.append(label)
            print('FAIL     %s  (exit %d)' % (label, r.returncode))
            print(''.join('           %s\n' % l for l in out.strip().splitlines()[:20]))

    print('')
    print('%d passed, %d failed, %d could not run, of %d discovered' % (
        len(passed), len(failed), len(unrun), len(checks)))

    if failed:
        return 1
    if unrun:
        print('')
        print('Nothing failed, but the run is NOT a pass: a check that could not run is neither a pass')
        print('nor a fail. Supply what it needs, or accept that those properties are unverified here.')
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
