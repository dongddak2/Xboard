# tests/baseline — archived pre-audit instruments, NOT checks

These two files are the verifiers **as they were before the needle audit**, kept verbatim:

| file | was | pre-audit predicates it still contains |
|---|---|---|
| `pre-audit-verify_quota.py.txt` | the quota verifier | `:74` `"'rate'" in svc_code` · `:152` `"rate" in job_code` · `:157` `for banned in ("md5(", "sha1(", "hash(", "getContent()")` |
| `pre-audit-verify_signup.py.txt` | the signup verifier | `:61` `"admin_setting(self::KEY_DOMAINS" in svc_code` · `:62` `"admin_setting(" in svc_code` |

## Why they are here

`tests/verify-needle-regression.py` demonstrates the needle fix by running the **old** predicates
beside the new ones. Until now it could not run them — the pre-audit verifiers were never committed,
so the old side was *quoted* from them, and a quote is a copy. These files make the old side
addressable: the regression check parses them and lifts both the needle literals and the `in`
operator out of the text, instead of me re-typing either.

## What this does and does not establish

It does: the literals the regression check calls "the old predicate" are now read from a committed
blob, so a reader can diff them against what the check prints.

It does **not** make the archive a pre-fix commit. These files entered git *after* the fix, so the
repository dates the *archive*, not the *text*. Nothing in git proves this text is what ran before
the audit; that rests on my word. Committing old text later cannot manufacture the history that
would have existed had the instrument been committed when it was written — which is exactly the
cost of not committing an instrument, and it is not refundable.

## They must never be executed

Two independent reasons:

1. They contain the **defective** needles. Running them would run known-broken predicates as if
   they were checks, and their output would read as PASS.
2. `pre-audit-verify_signup.py.txt:58` does `sys.path.insert` into a session temp directory that no
   longer exists, so the file cannot even import.

So they are placed where the runner's convention **rejects** them, and the rejection is asserted,
not assumed: `tests/run-checks.py` `self_test()` pins `kind_of()` to `None` for all three paths in
this directory. Placing them here is also what surfaced a defect in that convention — `kind_of()`
matched on the basename while discovery globs `tests/*` without recursing, so any committed
`tests/<subdir>/verify-X.py` would have been in the manifest and never in the run set, failing
MISSING forever. The rule now requires the depth to agree.
