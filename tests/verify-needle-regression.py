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

PROVENANCE OF THE OLD SIDE. Two different instruments are measured, and they differ in how well the
"before" can be established:

  PYTHON  the pre-audit verifiers were never committed, so there is no old blob to run. Their predicates
          are quoted below as one-line lambdas, with the file and line they came from:
              verify_quota.py:157   for banned in ("md5(", "sha1(", "hash(", "getContent()"):
              verify_quota.py:74    check(..., "'rate'" in svc_code, False)
              verify_signup.py:62   check(..., "admin_setting(" in svc_code, False)
          Reproducing them is itself a copy, and a copy is not a reference. The quotes are one line each
          so they can be compared by eye, and committing a known-defective instrument purely to run it
          once would be worse.
  CJS     the old needle IS committed, at blob 8accaa52 (commit d6f43e5), and its text is reproduced from
          that blob: /admin_setting\\s*\\(/ with no i flag. For this one the "before" is addressable.
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from php_probe import calls, reads_key, self_test

# --- OLD predicates, as the pre-audit instruments wrote them (see PROVENANCE above) ---
# PYTHON side: plain substring tests, no case or whitespace tolerance.
old_py = {
    'md5': lambda c: "md5(" in c,
    'sha1': lambda c: "sha1(" in c,
    'hash': lambda c: "hash(" in c,
    'getContent': lambda c: "getContent()" in c,
    'admin_setting': lambda c: "admin_setting(" in c,
}
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
old_rate = lambda c: "'rate'" in c
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
sys.exit(0)
