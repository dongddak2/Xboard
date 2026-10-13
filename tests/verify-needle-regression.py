# -*- coding: utf-8 -*-
"""ManduVPN: before-and-after for the absence needles, PER AXIS, so the count is a measurement.

  python -I tests/verify-needle-regression.py

WHY THIS FILE EXISTS. Showing that the fixed needles catch MD5( proves the NEW needles fire. It does not
prove the old ones missed it, and those are different facts:

    A fix is demonstrated by the OLD version failing to catch what the NEW version catches,
    not by the new version catching it.

Without the before-run, "we closed N silent holes" and "we tightened N needles that were already
adequate" produce identical evidence. So every case runs BOTH predicates against THE SAME subject.

AND THE COUNT ALONE IS NOT THE ANSWER. Two axes can make an absence needle miss a real call - CASE,
because PHP function names are case-insensitive, and WHITESPACE, because a space before the paren is
legal. Different needles were holed on different axes, so "N spellings missed" collapses two facts. This
reports, per needle, WHICH AXIS WAS ACTUALLY LIVE:

    case only        the old needle already tolerated whitespace, so only capitals got through
    whitespace only  the old needle was case-insensitive, so only the space got through
    both axes        the old needle was a plain substring test and neither got through
    none             the old needle was already adequate; the change tightened nothing

That distinction came from QA, who diffed the two checker blobs and found the old .cjs dependant needle
already had \\s*\\( - so for that needle the live hole was case, not whitespace. Verified here against the
blob itself rather than taken on report.

PROVENANCE OF THE OLD SIDE. Both instruments' old text is now committed, and the old predicates are READ
OUT OF IT rather than reproduced here:

  PYTHON  tests/baseline/pre-audit-verify_*.py.txt are the pre-audit verifiers, kept verbatim. This file
          PARSES them and lifts the needle literals and the `in` operator out of the syntax tree, so
          neither is re-typed. Earlier revisions quoted the predicates as hand-written lambdas; a quote
          is a copy, and a copy is not a reference.
  CJS     the old needle is committed at blob 8accaa52 (commit d6f43e5): /admin_setting\\s*\\(/, no i flag.
          It is still reproduced as a literal below, because it lives in a blob rather than in the tree.

WHAT IS STILL ASSERTED BY HAND, so the grade is not overstated:

  1. THE ARCHIVE IS NOT A PRE-FIX COMMIT. tests/baseline/* entered git AFTER the fix, so the repository
     dates the archive, not the text. Nothing in git proves this text is what ran before the audit. The
     fully-addressable "before" is the .cjs one, which was committed while still defective, and that
     difference cannot be closed retroactively.
  2. The MAPPING from an extracted literal to the function it was meant to detect ("md5(" -> md5) is
     mine, and so are the subject spellings below.

The archived files are deliberately named and placed so the runner's convention REJECTS them: they hold
the defective needles, and one of them imports from a temp directory that no longer exists. That
rejection is asserted in run-checks.py's self_test(), not left to the filename.
"""
import ast
import io
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from php_probe import calls, reads_key, self_test

BASELINE = os.path.join(HERE, 'baseline')


# --------------------------------------------------------------------------- the old side, PARSED
def extract_in_needles(path):
    """Lift every `"<literal>" in <name>` predicate out of an archived instrument.

    Returns {literal: lineno}. The literal AND the membership operator both come from the file's syntax
    tree, so this is a reading of the old instrument rather than a restatement of it. A `for x in (...)`
    over string literals is resolved so the looped needles are found too, since that is how the hashing
    bar was written.
    """
    src = io.open(path, encoding='utf-8').read()
    tree = ast.parse(src)
    loop_vars = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.For) and isinstance(node.target, ast.Name) \
                and isinstance(node.iter, (ast.Tuple, ast.List)):
            lits = [e.value for e in node.iter.elts
                    if isinstance(e, ast.Constant) and isinstance(e.value, str)]
            if lits:
                loop_vars[node.target.id] = (lits, node.lineno)
    found = {}
    for node in ast.walk(tree):
        if not isinstance(node, ast.Compare) or len(node.ops) != 1:
            continue
        if not isinstance(node.ops[0], ast.In):          # the operator is read, not assumed
            continue
        left = node.left
        if isinstance(left, ast.Constant) and isinstance(left.value, str):
            found.setdefault(left.value, node.lineno)
        elif isinstance(left, ast.Name) and left.id in loop_vars:
            lits, lineno = loop_vars[left.id]
            for lit in lits:
                found.setdefault(lit, lineno)
    return found


ARCHIVES = {
    'quota': os.path.join(BASELINE, 'pre-audit-verify_quota.py.txt'),
    'signup': os.path.join(BASELINE, 'pre-audit-verify_signup.py.txt'),
}

missing = [p for p in ARCHIVES.values() if not os.path.isfile(p)]
if missing:
    # No verdict, not a pass: without the archived text there is no old side to run, and a before-and-
    # after that silently drops the before is the thing this file exists to prevent.
    for p in missing:
        print('NO VERDICT  the archived pre-audit instrument is absent: %s' % p)
    sys.exit(2)

NEEDLES = {k: extract_in_needles(p) for k, p in ARCHIVES.items()}

# The literal each function's old needle was written as. Looked up in the archive, never defaulted: if a
# literal is not in the parsed set the archive is not the text this file was built against, and that is
# a failure rather than a skip.
WANT = {
    'md5': ('quota', 'md5('),
    'sha1': ('quota', 'sha1('),
    'hash': ('quota', 'hash('),
    'getContent': ('quota', 'getContent()'),
    'admin_setting': ('signup', 'admin_setting('),
}
RATE = ('quota', "'rate'")

absent = ['%s -> %r not found in the %s archive' % (fn, lit, which)
          for fn, (which, lit) in sorted(WANT.items()) if lit not in NEEDLES[which]]
if RATE[1] not in NEEDLES[RATE[0]]:
    absent.append('server[rate] -> %r not found in the %s archive' % (RATE[1], RATE[0]))
if absent:
    for a in absent:
        print('NO VERDICT  %s' % a)
    print('\nThe archived instrument does not contain the predicates this comparison is about.')
    sys.exit(2)

# The old predicate, built from the lifted literal: substring containment, exactly as parsed.
old_py = {fn: (lambda l: (lambda c: l in c))(lit) for fn, (which, lit) in WANT.items()}
OLD_SRC = {fn: '%s.py.txt:%d   %r in <code>' % (which, NEEDLES[which][lit], lit)
           for fn, (which, lit) in WANT.items()}

# CJS side: reproduced from blob 8accaa52 - whitespace tolerant, case sensitive.
old_cjs_admin_setting = lambda c: re.search(r'admin_setting\s*\(', c) is not None

NEW = {name: (lambda n: (lambda c: calls(c, n)))(name) for name in old_py}

# The realistic argument list per function, because the old getContent needle looked for EMPTY parens -
# "getContent()" - so handing it an argument would report it as missing even the plain call. That is a
# third axis (argument presence) and it is not live for a function that takes none, but using the wrong
# call shape would have manufactured a defect that is not there.
ARGS = {
    'md5': '$d',
    'sha1': '$d',
    'hash': '$d',
    'getContent': '',          # takes no arguments; the old needle required the empty pair
    'admin_setting': '$k',
}


# One call, written four ways: the two axes crossed.
def spellings(name):
    a = ARGS[name]
    return {
        'plain': '$x = %s(%s);' % (name, a),
        'UPPER': '$x = %s(%s);' % (name.upper(), a),
        'spaced': '$x = %s (%s);' % (name, a),
        'UPPER+sp': '$x = %s (%s);' % (name.upper(), a),
    }


def live_axes(old, name):
    """Which axes the old predicate let through, measured rather than assumed."""
    s = spellings(name)
    caught = {k: old(v) for k, v in s.items()}
    if not caught['plain']:
        return 'BROKEN: missed the plain call', caught
    missed_case = not caught['UPPER']
    missed_space = not caught['spaced']
    if missed_case and missed_space:
        return 'both axes', caught
    if missed_case:
        return 'case only', caught
    if missed_space:
        return 'whitespace only', caught
    return 'none - already adequate', caught


self_test()

fails = []
rows = []

print('OLD SIDE, lifted from the archived instruments (literal and `in` both parsed, not re-typed):')
for fn in sorted(OLD_SRC):
    print('    %-14s %s' % (fn, OLD_SRC[fn]))
print('    %-14s %s.py.txt:%d   %r in <code>' % ('server[rate]', RATE[0], NEEDLES[RATE[0]][RATE[1]], RATE[1]))
print('')

CASES = [('%s() - python verifier' % n, old_py[n], NEW[n], n) for n in
         ('md5', 'sha1', 'hash', 'getContent', 'admin_setting')]
CASES.append(('admin_setting() - cjs checker', old_cjs_admin_setting, NEW['admin_setting'], 'admin_setting'))

print('%-34s %-24s %s' % ('needle (instrument)', 'live axis, measured', 'old caught / new caught'))
print('-' * 96)
for label, old, new, name in CASES:
    axis, caught = live_axes(old, name)
    s = spellings(name)
    detail = []
    for k in ('plain', 'UPPER', 'spaced', 'UPPER+sp'):
        o, n = old(s[k]), new(s[k])
        detail.append('%s %s/%s' % (k, 'Y' if o else '-', 'Y' if n else '-'))
        # whatever the old predicate did, the NEW one must catch every spelling of a real call
        if not n:
            fails.append('FAIL  %s: the new predicate missed %r' % (label, s[k]))
    rows.append((label, axis))
    print('%-34s %-24s %s' % (label, axis, '  '.join(detail)))

# the double-quoted index, which is an axis of its own: quote style
idx = {'single': "$r = $server['rate'];", 'double': '$r = $server["rate"];'}
_rate_lit = RATE[1]
old_rate = lambda c: _rate_lit in c
print('')
print('%-34s %-24s %s' % ('server[rate] - python verifier', 'quote style only',
                          '  '.join('%s %s/%s' % (k, 'Y' if old_rate(v) else '-',
                                                  'Y' if reads_key(v, 'rate') else '-')
                                    for k, v in idx.items())))
for k, v in idx.items():
    if not reads_key(v, 'rate'):
        fails.append('FAIL  server[rate]: the new predicate missed the %s-quoted form' % k)
if old_rate(idx['double']):
    fails.append('FAIL  server[rate]: the old predicate was supposed to miss the double-quoted form')
rows.append(('server[rate] - python verifier', 'quote style only'))

# The guard: narrowing to silence a false fail is how a false pass is built, so the plain call must stay
# caught, and the benign look-alike must stay rejected.
if not calls('$x = hash($d);', 'hash'):
    fails.append('FAIL  the new hash predicate was narrowed until it missed the real call')
if calls('$h = password_hash($p);', 'hash'):
    fails.append('FAIL  the new hash predicate flags password_hash, which is not a hash() call')
if calls('if (hash_equals($a,$b)) {}', 'hash'):
    fails.append('FAIL  the new hash predicate flags hash_equals, which is not a hash() call')

print('')
if fails:
    for f in fails:
        print(f)
    print('\n%d case(s) not demonstrated.' % len(fails))
    sys.exit(1)

by_axis = {}
for _, axis in rows:
    by_axis[axis] = by_axis.get(axis, 0) + 1
print('MEASURED  %d needles, and the live axis differs between them:' % len(rows))
for axis in sorted(by_axis):
    print('            %-26s %d needle(s)' % (axis, by_axis[axis]))
print('')
print('          So the honest statement is not a single hole count. The python needles were plain')
print('          substring tests and were holed on BOTH axes; the cjs needle already tolerated')
print('          whitespace and was holed on CASE alone; the index needle was holed on quote style.')
print('          Every spelling of a real call is caught by the new predicates, and password_hash')
print('          and hash_equals are still not treated as hash() calls.')
print('')
print('NOT verified here: the archive was committed AFTER the fix, so git dates the ARCHIVE and not')
print('          the TEXT. The old side came from tests/baseline/, so the literals above can be diffed')
print('          against a blob, but nothing in the repository proves that text is what ran before the')
print('          audit - the .cjs before is the only one git can date. No PHP was executed.')
sys.exit(0)
