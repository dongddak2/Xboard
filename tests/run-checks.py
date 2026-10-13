# -*- coding: utf-8 -*-
"""ManduVPN: the one entry point that runs every committed check in tests/.

  python -I tests/run-checks.py
  python -I tests/run-checks.py --self-test    # just the convention's own sensitivity

ADDING A CHECKER MUST NOT REQUIRE REMEMBERING TO WIRE IT. So this holds no list - a list is the thing
that gets forgotten. The naming convention IS the wiring:

  tests/*.check.cjs     a Node checker      -> node
  tests/verify-*.py     a Python verifier   -> python
  tests/php_probe.py    the shared probes   -> its own self-test
  tests/*-fixtures.php  a PHP fixture       -> php, IF a php binary exists

THE REPO IS THE MANIFEST, WHICH IS WHY DISCOVERY ALONE IS NOT ENOUGH.
Discovery by itself cannot tell "every check passed" from "the checks are gone": delete or rename them all
and a glob finds nothing, so nothing fails. Measured, not supposed - a tests/ directory holding only this
runner and one file named to the convention that asserts nothing reported 1 passed, exit 0. That is this
week's shape again: ABSENCE IS SILENT.
And a glob reads the WORKING TREE, so an untracked file in tests/ would be EXECUTED, not merely
misleading to someone reading the directory.

Both holes close with one comparison. `git ls-files tests/` is the EXPECTED set, discovery is the RUNNER,
and the two must agree:

  tracked but NOT discovered  -> a committed checker vanished from the tree        -> FAIL
  discovered but NOT tracked  -> NOT EXECUTED, and the run FAILS                   -> FAIL
  no git, so no manifest      -> the composition of this run cannot be established -> NO VERDICT

The untracked case is PREVENTION rather than detection: the file is dropped from the run set before the
first subprocess, so it never executes. Reporting it in the summary instead would print the verdict after
the code had already run - right answer, hazard untouched. A committed checker that vanished is a
different matter: the remaining committed checks are still safe to run, so they run and the result fails.

The no-manifest case matters more than it looks: a manifest check that silently skipped itself when git
is unavailable would be no check at all, so its absence is a no-verdict rather than a degraded pass.

Adding a checker still needs no edit here, because a newly committed file appears in BOTH sets at once.
That is the point of using the repo rather than a hand-kept list.

Why untracked-and-executing is a FAIL rather than a note: a run whose composition is not addressable at a
commit is not reproducible, and a result nobody else can reproduce is testimony rather than evidence. One
line to soften if that is judged too strict.

Exit codes, and they are not collapsed:
  0  every discovered check ran and passed, and the set matched the manifest
  1  a check FAILED, or the set disagreed with the manifest
  2  nothing failed, but something COULD NOT RUN - no verdict, and never reported as a pass

A child's exit 2 is carried through as NO VERDICT rather than flattened into failure, so a check with its
own three-state contract keeps it when wrapped.
"""
import glob
import os
import subprocess
import sys

TESTS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(TESTS, '..'))
HERE = 'tests/' + os.path.basename(os.path.abspath(__file__))


def which(cmd):
    for d in os.environ.get('PATH', '').split(os.pathsep):
        for ext in ('', '.exe', '.cmd', '.bat'):
            p = os.path.join(d, cmd + ext)
            if os.path.isfile(p):
                return p
    return None


def kind_of(rel):
    """Which runner the convention assigns to a tests/ path, or None if it is not a checker.

    One function, applied to BOTH the working tree and the git manifest, so the two sets are computed by
    the same rule and a disagreement means the tree moved rather than the rule.
    """
    name = rel.split('/')[-1]
    if rel == HERE:
        return None                      # the runner is not one of its own checks
    # DEPTH MUST AGREE WITH DISCOVERY. This rule used to look only at the basename while discovery
    # globs tests/* and does not recurse, so a committed tests/<subdir>/verify-X.py entered the
    # MANIFEST and could never be DISCOVERED - a permanent false MISSING for a file the runner was
    # never going to run. Found by trying to place a file where the convention should reject it.
    if not rel.startswith('tests/') or rel.count('/') != 1:
        return None
    if name.endswith('.check.cjs'):
        return 'node'
    if name == 'php_probe.py':
        return 'python'
    if name.startswith('verify-') and name.endswith('.py'):
        return 'python'
    if name.endswith('-fixtures.php'):
        return 'php'
    return None


def self_test():
    """The convention's own sensitivity. A manifest comparison is only as good as the rule behind it."""
    for rel, want in (
        ('tests/premise-settings-resolution.check.cjs', 'node'),
        ('tests/verify-signup-policy.py', 'python'),
        ('tests/verify-needle-regression.py', 'python'),
        ('tests/php_probe.py', 'python'),
        ('tests/signup-email-policy-fixtures.php', 'php'),
        (HERE, None),                    # must not try to run itself
        ('tests/README.md', None),
        ('tests/verify.py', None),       # no dash: deliberately NOT matched, so the rule is exact
        ('tests/helper.py', None),
        ('tests/notes-fixtures.txt', None),
        # The archived pre-audit instruments. These are HISTORICAL TEXT, not checkers: they contain
        # the defective needles on purpose, so being run would mean running known-broken predicates
        # as if they were checks. Their exclusion is ASSERTED here rather than left to the name.
        ('tests/baseline/pre-audit-verify_quota.py.txt', None),
        ('tests/baseline/pre-audit-verify_signup.py.txt', None),
        ('tests/baseline/README.md', None),
        # ...and the depth rule itself, which is what keeps the manifest and discovery agreeing.
        ('tests/baseline/verify-anything.py', None),
        ('tests/sub/php_probe.py', None),
        ('tests/a/b/premise.check.cjs', None),
    ):
        got = kind_of(rel)
        assert got == want, 'kind_of(%r) = %r, expected %r' % (rel, got, want)

    # The ceiling extractor's own sensitivity. Forwarding a child's declared non-coverage is only worth
    # anything if it fires on what the committed checkers ACTUALLY print, and stays empty otherwise - a
    # ceiling reporter that silently finds nothing is the swallow it was written to fix.
    quota_style = 'VERIFY PASS\n  some check\n  NOT verified here: no PHP executed, no database,\n  and the write path has never run.\n'
    cjs_style = 'PASS  premise 1\nNOTE  source-text assertions, not a behavioural comparison: that would\n      need the Laravel container. No PHP was executed.\n'
    none_style = 'PASS  php_probe self-test: every probe catches the violation spellings\n'
    got = ceiling_lines(quota_style)
    assert got == ['NOT verified here: no PHP executed, no database,',
                   'and the write path has never run.'], got
    got = ceiling_lines(cjs_style)
    assert got == ['NOTE  source-text assertions, not a behavioural comparison: that would',
                   'need the Laravel container. No PHP was executed.'], got
    assert ceiling_lines(none_style) == [], ceiling_lines(none_style)
    # An unindented line ENDS the block, so a ceiling cannot swallow the rest of a child's output.
    assert ceiling_lines('NOTE  a limit\nPASS  unrelated later line\n') == ['NOTE  a limit']
    return True


# The markers a checker uses to declare what it did NOT verify. MEASURED from the committed checkers
# rather than invented: verify-server-quota.py and verify-signup-policy.py print "NOT verified here:",
# premise-settings-resolution.check.cjs prints "NOTE". A continuation line is an indented non-empty line
# directly below.
CEILING_MARKERS = ('NOT verified here', 'NOT VERIFIED HERE', 'NOTE ', 'NOTE\t')


def ceiling_lines(out):
    """A passing child's SELF-DECLARED non-coverage, so aggregation cannot swallow it.

    QA found, and LEAD confirmed on a second checker, that this runner collapsed a 12-line PASS into one
    'PASS' line - and the lines it dropped were precisely the ones saying what the tool had NOT checked.
    The PASS was true for what the checker asserts, so this was never a false pass; the defect was that
    the SUITE OVERSTATED THE STRENGTH OF THE EVIDENCE relative to running the tool by hand. It is the
    three-state problem one layer up: 'passed, with a declared ceiling' was flattened into 'passed'.

    A wrapper discarding the inner tool's own report is the general shape, so the ceiling travels
    verbatim and the PASS line is marked. A passing child that declares NO ceiling is reported as such,
    because 'no limits stated' and 'limits stated and dropped' must not look the same either - absence
    is silent unless something says so out loud.
    """
    keep, grabbing = [], False
    for line in out.splitlines():
        s = line.strip()
        if any(s.startswith(m) for m in CEILING_MARKERS):
            keep.append(s)
            grabbing = True
            continue
        if grabbing:
            if s and line[:1] in (' ', '\t'):
                keep.append(s)
            else:
                grabbing = False
    return keep


def tracked_checks():
    """The committed tests/ checkers, or None when git cannot answer."""
    try:
        r = subprocess.run(['git', 'ls-files', 'tests/'], cwd=ROOT, capture_output=True, text=True)
    except OSError:
        return None
    if r.returncode != 0:
        return None
    out = [l.strip().replace('\\', '/') for l in r.stdout.splitlines() if l.strip()]
    return set(p for p in out if kind_of(p))


def discovered_checks():
    """The checkers present in the working tree right now, by the same rule."""
    found = {}
    for path in sorted(glob.glob(os.path.join(TESTS, '*'))):
        if not os.path.isfile(path):
            continue
        rel = 'tests/' + os.path.basename(path)
        k = kind_of(rel)
        if k:
            found[rel] = k
    return found


def main():
    self_test()

    discovered = discovered_checks()
    tracked = tracked_checks()

    composition_failed = []
    no_manifest = False
    if tracked is None:
        no_manifest = True
        print('NO MANIFEST  git could not list tests/, so the composition of this run cannot be')
        print('             established - that is a no-verdict, not a pass')
    else:
        vanished = sorted(tracked - set(discovered))
        untracked = sorted(set(discovered) - tracked)
        for rel in vanished:
            composition_failed.append('MISSING  %s is committed but not in the tree - a checker vanished' % rel)
        for rel in untracked:
            # PREVENTION, NOT DETECTION. An earlier version reported this in the summary - by which time
            # the file had already run, so its PASS printed alongside the warning. The verdict was right
            # and the hazard was untouched: unreviewed code had executed. So the file is removed from the
            # run set here, BEFORE the first subprocess, and never executed at all.
            composition_failed.append('UNTRACKED  %s is not committed - NOT EXECUTED, and this run is not reproducible' % rel)
            discovered.pop(rel, None)
        for line in composition_failed:
            print(line)
        if not composition_failed:
            print('MANIFEST OK  %d committed checker(s), all present, nothing untracked' % len(tracked))

    if not discovered:
        print('ABORT  no checks discovered in tests/ - the convention at the top of this file says what counts')
        return 1 if tracked else 2

    node, php = which('node'), which('php')
    failed, unrun, passed = [], [], []
    ceilinged, silent = [], []
    for rel, kind in sorted(discovered.items()):
        path = os.path.join(ROOT, rel)
        if kind == 'node':
            argv, why = ([node, path], None) if node else (None, 'no node binary on PATH')
        elif kind == 'php':
            argv, why = ([php, path], None) if php else (None, 'no php binary on PATH')
        else:
            argv, why = [sys.executable, '-I', path], None
        label = rel + (' (self-test)' if rel.endswith('php_probe.py') else '')

        if argv is None:
            unrun.append(label)
            print('NOT RUN  %-52s %s' % (label, why))
            continue
        r = subprocess.run(argv, cwd=ROOT, capture_output=True, text=True)
        out = (r.stdout or '') + (r.stderr or '')
        if r.returncode == 0:
            passed.append(label)
            ceiling = ceiling_lines(out)
            if ceiling:
                print('PASS*    %s   (* passed WITH a declared ceiling, forwarded below)' % label)
                for l in ceiling:
                    print('           %s' % l)
                ceilinged.append(label)
            else:
                print('PASS     %s   (declares no ceiling of its own)' % label)
                silent.append(label)
        elif r.returncode == 2:
            # Carried through, not flattened: a check with a three-state contract keeps it when wrapped.
            unrun.append(label)
            print('NO VERDICT  %s' % label)
            for l in out.strip().splitlines()[:12]:
                print('           %s' % l)
        else:
            failed.append(label)
            print('FAIL     %s  (exit %d)' % (label, r.returncode))
            for l in out.strip().splitlines()[:20]:
                print('           %s' % l)

    print('')
    print('%d passed, %d failed, %d could not run, of %d discovered%s' % (
        len(passed), len(failed), len(unrun), len(discovered),
        '' if tracked is None else ' (%d committed)' % len(tracked)))
    if passed:
        # The count alone would re-hide what the forwarding just exposed, so the split is in the summary.
        print('         of the %d that passed, %d declared a ceiling (shown above) and %d declared none'
              % (len(passed), len(ceilinged), len(silent)))

    if failed or composition_failed:
        if composition_failed and not failed:
            print('')
            print('No check failed, but the set that ran is not the set the repository commits.')
            print('That is a failure of the run, not of the checks.')
        return 1
    if unrun or no_manifest:
        print('')
        print('Nothing failed, but the run is NOT a pass: a check that could not run, or a run whose')
        print('composition could not be established, is neither a pass nor a fail.')
        return 2
    return 0


if __name__ == '__main__':
    if '--self-test' in sys.argv[1:]:
        self_test()
        print('PASS  run-checks convention self-test: the rule matches the four conventional shapes')
        print('      and rejects the runner itself, a dashless verify.py, a helper and a non-check file,')
        print('      the archived pre-audit instruments under tests/baseline/, and anything below depth')
        print('      1 - which discovery cannot reach, so the manifest must not claim it either')
        print('      and the ceiling extractor fires on both marker styles the committed checkers use,')
        print('      stops at an unindented line, and stays empty when a child declares nothing')
        sys.exit(0)
    sys.exit(main())
