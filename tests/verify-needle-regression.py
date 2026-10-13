# -*- coding: utf-8 -*-
"""ManduVPN: before-and-after for the ten absence needles, so "we closed ten silent holes" is a
measurement rather than a claim.

  python -I tests/verify-needle-regression.py

WHY THIS FILE EXISTS. The fixed needles were shown to catch MD5( and ADMIN_SETTING (, which proves the
NEW needles fire. It does not prove the old ones missed them, and those are different facts:

    A FIX IS DEMONSTRATED BY THE OLD VERSION FAILING TO CATCH WHAT THE NEW VERSION CATCHES,
    NOT BY THE NEW VERSION CATCHING IT.

Without the before-run, "we closed ten silent holes" and "we tightened ten needles that were already
adequate" produce identical evidence. So each case below runs BOTH predicates against THE SAME subject.

PROVENANCE OF THE "OLD" SIDE - it is real code, not a reconstruction. These are the literal expressions
the pre-audit verifiers used, which still exist verbatim in the session scratchpad they were written in:

    verify_quota.py:157   for banned in ("md5(", "sha1(", "hash(", "getContent()"):
    verify_quota.py:74    check(..., "'rate'" in svc_code, False)
    verify_quota.py:152   check(..., "rate" in job_code, False)
    verify_signup.py:62   check(..., "admin_setting(" in svc_code, False)

They are reproduced here as one-line lambdas so both sides can be run side by side. That reproduction is
itself a copy, and a copy is not a reference - so each one is quoted above with its file and line, and the
expressions are short enough to compare by eye. The alternative, committing the pre-audit verifiers purely
to be run once, would put a known-defective instrument in the deliverable.

Two directions are measured, because the needles were wrong in both:
  SILENT  - a real violation the old needle missed, which reported a PASS. Ten of these.
  LOUD    - a benign look-alike the old needle flagged, which reported a FAIL. One of these.
  AGREE   - a look-alike both versions reject. One of these, kept because it is what made the needle LOOK
            over-broad without ever matching it.
Only the silent ones are dangerous; the loud ones are included because fixing the appearance of
over-breadth is exactly how the silence was built, and the fix had to do both without trading one for
the other.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from php_probe import calls, reads_key, self_test

# --- the OLD predicates, as the pre-audit verifiers wrote them (see PROVENANCE above) ---
old_md5 = lambda code: "md5(" in code
old_sha1 = lambda code: "sha1(" in code
old_hash = lambda code: "hash(" in code
old_get_content = lambda code: "getContent()" in code
old_admin_setting = lambda code: "admin_setting(" in code
old_rate_quoted = lambda code: "'rate'" in code

# --- the NEW predicates, from the committed shared module ---
new_md5 = lambda code: calls(code, "md5")
new_sha1 = lambda code: calls(code, "sha1")
new_hash = lambda code: calls(code, "hash")
new_get_content = lambda code: calls(code, "getContent")
new_admin_setting = lambda code: calls(code, "admin_setting")
new_rate_quoted = lambda code: reads_key(code, "rate")

# (label, subject, old, new, direction)
#   SILENT: a real violation. old must MISS it (returning a pass) and new must CATCH it.
#   LOUD:   a benign look-alike. old CATCHES it (a false fail) and new must REJECT it.
CASES = [
    ("MD5( uppercase",              "$x = MD5($data);",                 old_md5,           new_md5,           "SILENT"),
    ("md5 ( space before paren",    "$x = md5 ($data);",                old_md5,           new_md5,           "SILENT"),
    ("SHA1( uppercase",             "$x = SHA1($data);",                old_sha1,          new_sha1,          "SILENT"),
    ("sha1 ( space before paren",   "$x = sha1 ($data);",               old_sha1,          new_sha1,          "SILENT"),
    ("HASH( uppercase",             "$x = HASH($data);",                old_hash,          new_hash,          "SILENT"),
    ("hash ( space before paren",   "$x = hash ($data);",               old_hash,          new_hash,          "SILENT"),
    ("getContent ( space",          "$b = $request->getContent ();",    old_get_content,   new_get_content,   "SILENT"),
    ("GetContent() mixed case",     "$b = $request->GetContent();",     old_get_content,   new_get_content,   "SILENT"),
    ("admin_setting ( space",       "$v = admin_setting ($key);",       old_admin_setting, new_admin_setting, "SILENT"),
    ("ADMIN_SETTING( uppercase",    "$v = ADMIN_SETTING($key);",        old_admin_setting, new_admin_setting, "SILENT"),
    ('server["rate"] double quotes', '$r = $server["rate"];',           old_rate_quoted,   new_rate_quoted,   "SILENT"),
    ("password_hash( benign",       "$h = password_hash($p, PASSWORD_DEFAULT);", old_hash, new_hash,          "LOUD"),
    ("hash_equals( benign",         "if (hash_equals($a, $b)) {}",      old_hash,          new_hash,          "AGREE"),
]

# The one that must survive all that narrowing: narrowing to silence a false fail is how a false pass is
# built, so the plain call must still be caught by the new predicate.
SURVIVES = ("hash( plain, must still be caught", "$x = hash($data);", new_hash)

self_test()

fails = []
silent_closed = 0
loud_fixed = 0
agreed = False

print("%-32s %-8s %-10s %-10s" % ("case", "kind", "old", "new"))
print("-" * 64)
for label, subject, old, new, kind in CASES:
    o, n = old(subject), new(subject)
    if kind == "SILENT":
        # old must MISS (False = reported a pass) and new must CATCH (True = reports a fail)
        ok = (o is False and n is True)
        if ok:
            silent_closed += 1
        verdict = "miss -> catch" if ok else "NOT DEMONSTRATED"
    elif kind == "LOUD":
        # old must CATCH a benign string (a false fail) and new must REJECT it
        ok = (o is True and n is False)
        if ok:
            loud_fixed += 1
        verdict = "false fail -> clean" if ok else "NOT DEMONSTRATED"
    else:
        # AGREE: both are already correct to reject this. Recorded because it is the look-alike that made
        # hash( LOOK over-broad without ever actually matching it - the appearance that invited the wrong
        # narrowing. Keeping it stops someone re-adding it as evidence of over-breadth.
        ok = (o is False and n is False)
        agreed = ok
        verdict = "both reject (never a false fail)" if ok else "NOT DEMONSTRATED"
    if not ok:
        fails.append("FAIL  %s (%s): old=%r new=%r - expected %s" % (label, kind, o, n, verdict))
    print("%-32s %-8s %-10s %-10s %s" % (label, kind, o, n, verdict))

label, subject, pred = SURVIVES
if pred(subject) is not True:
    fails.append("FAIL  %s: the new predicate was narrowed until it missed the real call" % label)
print("%-32s %-8s %-10s %-10s %s" % ("hash( plain", "GUARD", "-", pred(subject), "must stay caught"))

print("")
if fails:
    for f in fails:
        print(f)
    print("\n%d case(s) not demonstrated." % len(fails))
    sys.exit(1)

print("DEMONSTRATED  %d silent holes closed (old reported a PASS, new reports a FAIL)" % silent_closed)
print("              %d loud false-fail cleaned up, without losing the real call" % loud_fixed)
print("              hash_equals( recorded as a look-alike BOTH versions reject: it made hash( look")
print("              over-broad without ever matching it, which is the appearance that invited the")
print("              wrong narrowing")
print("              both predicates ran against the same subject in each case, which is what makes")
print("              this a measurement rather than a claim about the old needles")
sys.exit(0)
