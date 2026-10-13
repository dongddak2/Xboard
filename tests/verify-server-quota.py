# -*- coding: utf-8 -*-
"""ManduVPN SERVER-QUOTA-1: checks that need no PHP runtime, on the real files.

  python -I tests/verify-server-quota.py            # the committed state
  python -I tests/verify-server-quota.py <service>  # a copy, for injecting a defect

WHY THIS IS COMMITTED RATHER THAN KEPT IN A WORKSPACE. tests/server-quota-fixtures.php pins BEHAVIOUR but
cannot run where there is no php binary and no vendor/. This pins PREMISES and STRUCTURE and runs now, on
any machine with Python and nothing installed. For a while the arrangement was inverted - the instrument
that could not run was in the deliverable and the instrument that could was not - which made the strongest
evidence here testimony rather than evidence, because nobody else could repeat it. So it lives next to
what it verifies and is addressable at a blob id in the same commit.

These assert properties of the END STATE, not "did I make my edit". An end-state assertion can fail on a
mistake the author did not know they were making; a test of the intended change cannot.

Deliberately NOT here: no Python re-implementation of the period arithmetic. A replica would test the
replica. That arithmetic is asserted in tests/server-quota-fixtures.php, which needs a PHP binary.

Exit codes: 0 all checks passed. 1 a check failed. 2 a target file was missing, so some check could not
run - neither a pass nor a fail, and never reported as a pass.
"""
import io
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from php_probe import strip_comments, docblock_before, calls, reads_key, self_test

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')) + os.sep

fails = []
skipped = []


def check(name, actual, expected):
    if actual != expected:
        fails.append("FAIL  %s\n        expected: %r\n        actual:   %r" % (name, expected, actual))


def read(rel):
    p = rel if os.path.isabs(rel) else ROOT + rel
    if not os.path.isfile(p):
        skipped.append(rel)
        return None
    return io.open(p, encoding="utf-8").read()


def method_body(src, name):
    start = src.index("function %s(" % name)
    rest = src[start:]
    nxt = rest.find("\n    public ", 10)
    nxt2 = rest.find("\n    private ", 10)
    cut = min([x for x in (nxt, nxt2) if x != -1] or [len(rest)])
    return rest[:cut]


# The probes are checked before they are trusted: an absence assertion is only as good as the needle, and
# the silent direction is a needle that misses a real violation.
self_test()

SVC_REL = sys.argv[1] if len(sys.argv) > 1 else "app/Services/ServerQuotaService.php"
svc = read(SVC_REL)
fix = read("tests/server-quota-fixtures.php")
mig = read("database/migrations/2026_10_13_120000_create_manduvpn_server_user_usage.php")
srv = read("app/Services/ServerService.php")
job = read("app/Jobs/ServerQuotaUsageJob.php")
usr = read("app/Services/UserService.php")
cfg_save = read("app/Http/Requests/Admin/ConfigSave.php")
cfg_get = read("app/Http/Controllers/V2/Admin/ConfigController.php")

if None in (svc, fix, mig, srv, job, usr, cfg_save, cfg_get):
    print("ABORT  target file(s) missing, so no verdict is given:")
    for s in skipped:
        print("         %s" % s)
    sys.exit(2)

svc_code = strip_comments(svc)
mig_code = strip_comments(mig)
job_code = strip_comments(job)
usr_code = strip_comments(usr)

# ---------------------------------------------------------------- 1. the fixture's symbols all resolve
consts = set(re.findall(r"public const (\w+)", svc))
methods = set(re.findall(r"public static function (\w+)", svc))
check("the service parses into constants and methods", bool(consts and methods), True)
used_const = set(re.findall(r"Q::([A-Z][A-Z0-9_]*)\b(?!\s*\()", fix))
used_method = set(re.findall(r"Q::(\w+)\s*\(", fix))
check("every constant the fixture uses exists", sorted(used_const - consts), [])
check("every method the fixture calls exists", sorted(used_method - methods), [])
check("all four statuses are exercised",
      sorted(c for c in consts if c.startswith("STATUS_")),
      sorted(c for c in used_const if c.startswith("STATUS_")))

# ---------------------------------------------------------------- 2. the superseded calendar basis is absent
# A stale rule in the SPEC is a stale document; the same rule in DELIVERED CODE is a defect.
for fn in ("calcResetDayByMonthFirstDay", "calcResetDayByExpireDay"):
    check("the service does not CALL the superseded helper: %s" % fn, calls(svc_code, fn), False)
    check("the migration does not CALL the superseded helper: %s" % fn, calls(mig_code, fn), False)
for literal in ("Asia/Seoul", "Y-m-01", "first day of"):
    check("the service has no calendar literal in code: %s" % literal, literal in svc_code, False)
    check("the migration has no calendar literal in code: %s" % literal, literal in mig_code, False)
# and the service must still NAME them, so a later reader knows the omission was deliberate
check("the service names the superseded helpers as deliberately unused",
      "Deliberately NOT used" in svc and "calcResetDayByMonthFirstDay" in svc, True)
check("a period is 30 days", "const PERIOD_DAYS = 30;" in svc, True)
check("a period is 30 days in seconds", "PERIOD_DAYS * 86400" in svc, True)
check("the fixture asserts the calendar basis is dead", "calendar months are DEAD" in fix, True)

# ---------------------------------------------------------------- 3. raw bytes: no plan multiplier anywhere
# The whole reason this service exists. A rate here and the cap is no longer on raw bytes.
check("the service never indexes a rate, in either quote style", reads_key(svc_code, "rate"), False)
check("the service never reads server_rate", reads_key(svc_code, "server_rate") or "server_rate" in svc_code, False)
check("the service never multiplies by a server rate", "* $this->server" in svc_code, False)
check("the migration says raw bytes", "RAW bytes" in mig, True)

# ---------------------------------------------------------------- 4. the fail-OPEN exception, beside the branch
st = method_body(svc, "state")
st_doc = docblock_before(svc, "public static function state(")
check("the exception is documented BESIDE the branch, not elsewhere",
      "DELIBERATE EXCEPTION TO THE FAIL-CLOSED RULE" in st_doc, True)
check("the exception warns against being fixed", 'DO NOT "FIX" THIS TO FAIL CLOSED' in st_doc, True)
check("the exception says which failure is worse", "someone who has PAID" in st_doc, True)
check("the exception is scoped to quota accounting only", "quota accounting only" in st_doc, True)
check("the unknown branch reports used as null, not zero", "'used_bytes' => null," in st, True)
check("no branch of state() ever sets over_limit true for an unmeasured figure",
      re.search(r"STATUS_UNKNOWN.*?'over_limit' => true", st, re.S) is not None, False)
check("state() hardcodes no over_limit true at all", st.count("'over_limit' => true"), 0)
check("the measured branch computes over_limit rather than hardcoding it", "'over_limit' => $over," in st, True)
check("the fixture asserts the fail-open direction", "FAILS OPEN" in fix, True)

# ---------------------------------------------------------------- 5. the anchor is never derived on read
check("the service never queries orders", "v2_order" in svc, False)
check("the one-time derivation lives in the migration", "v2_order" in mig, True)
check("the migration says the derivation runs once", "ONE-TIME" in mig, True)
check("the migration backfills from the completed-order constant", "Order::STATUS_COMPLETED" in mig, True)
check("the migration does not use a bare status literal", re.search(r"whereIn\('status', \[", mig) is not None, False)
check("the anchor column name has one owner", svc.count("const ANCHOR_COLUMN"), 1)
check("the migration uses that same column name", "quota_anchor_at" in mig, True)

# ---------------------------------------------------------------- 6. the key is the entitlement owner
check("the migration keys on user, server, node_type and period",
      "['user_id', 'server_id', 'node_type', 'period_start_at']" in mig, True)
check("the migration records why it keys on the leaf, not the reporter",
      "ENTITLEMENT OWNER, NOT THE REPORTER" in mig, True)
check("node_type is in the service's usage lookup", "where('node_type', $nodeType)" in svc, True)
check("the migration explains why node_type is in the key", "unique only within a protocol" in mig, True)

# ---------------------------------------------------------------- 7. enforcement on the node path, gated
gau = method_body(srv, "getAvailableUsers")
check("the node user list consults the quota service", "ServerQuotaService::overLimitUserIds(" in gau, True)
check("enforcement only applies when the asking node is known",
      "if ($nodeId !== null && $users->isNotEmpty())" in gau, True)
check("enforcement restricts by rejecting from this node's list only", "->reject(" in gau, True)
check("the quota call sits inside the nodeId guard",
      gau.index("if ($nodeId !== null && $users->isNotEmpty())") < gau.index("ServerQuotaService::overLimitUserIds("), True)

# ---------------------------------------------------------------- 8. nothing here runs a migration
for label, src in (("service", svc_code), ("migration", mig_code), ("ServerService", strip_comments(srv))):
    check("%s does not run a migration" % label, calls(src, "Artisan::call"), False)
check("the migration is additive: it guards the table",
      "if (!Schema::hasTable('v2_server_user_usage'))" in mig, True)
check("the migration is additive: it guards the column",
      "if (!Schema::hasColumn('v2_user', 'quota_anchor_at'))" in mig, True)
check("the service tolerates the table being absent", "Schema::hasTable(self::USAGE_TABLE)" in svc, True)

# ---------------------------------------------------------------- 9. the admin cap path, and no second column
check("ConfigSave accepts the cap row", "'manduvpn_server_quota_gb' => 'nullable|array'" in cfg_save, True)
check("ConfigController exposes the cap row", "ServerQuotaService::caps()" in cfg_get, True)
check("the cap key has one owner", svc.count("const KEY_CAPS"), 1)
check("the migration adds no column for the cap", "quota_gb" in mig, False)
# the cap default must stay empty, and the reason is a trap that arms itself
check("the inheritance warning sits at the cap default", "ACQUIRES THAT DEFECT BY INHERITANCE" in svc, True)
check("the warning names the remedy", "resolveDomains()" in svc, True)

# ---------------------------------------------------------------- 10. the accumulation write path
check("trafficFetch dispatches the quota job", "ServerQuotaUsageJob::dispatch(" in usr_code, True)
check("the report instant is captured at ingestion", "reportedAt = time();" in usr_code, True)
check("the job is handed that instant", "protocol, $reportedAt)" in usr_code, True)
check("the job never computes its own period from the clock", calls(job_code, "date"), False)
check("the job uses the carried instant for the period", "$this->reportedAt" in job_code, True)

# raw bytes: a rate here would silently change what every configured cap means
check("the job never indexes a rate, in either quote style", reads_key(job_code, "rate"), False)
check("the job never reads a rate property", re.search(r"->\s*rate\b", job_code) is not None, False)
check("the job increments raw u", "DB::raw('u + ' . $u)" in job_code, True)
check("the job increments raw d", "DB::raw('d + ' . $d)" in job_code, True)

# at-least-once was accepted; body-hash dedup was BARRED, because it drops real data
for fn in ("md5", "sha1", "hash", "crc32", "getContent"):
    check("the job does not deduplicate by hashing: %s()" % fn, calls(job_code, fn), False)
check("the over-count direction is documented in the service", "CAN BE AN OVER-COUNT" in svc, True)
check("the service names the admin lever as the remedy", "cap lever" in svc, True)
check("body-hash dedup is recorded as barred in the service", "deduplication is barred" in svc, True)
check("the job repeats the bar so it is not re-added", "BARRED" in job, True)

# the increment-then-insert shape, which the UNIQUE index makes safe under concurrent reports
check("the job increments before inserting", job_code.index("->update([") < job_code.index("->insert(["), True)
check("the job runs inside a retrying transaction", "DB::transaction(" in job_code, True)

# IKEv2 has no byte counters at all, so there must be no branch pretending otherwise
check("the job has no ikev2 branch", "ikev2" in job_code.lower(), False)
check("the service records that IKEv2 has no input", "IKEV2 NODES HAVE NO INPUT AT ALL" in svc, True)

# the apply-order dependency on signup, in the file rather than in a message
check("the migration carries the apply-order constraint", "THE SIGNUP WORK GOES FIRST" in mig, True)
check("the apply-order constraint states the breakage", "BREAKS THE ADMIN CONFIG PATH" in mig, True)

# ---------------------------------------------------------------- report
if fails:
    for f in fails:
        print(f)
    print("\n%d failure(s)." % len(fails))
    sys.exit(1)

print("VERIFY PASS  (no PHP runtime - text and structure checks on the real files)")
print("  probes self-tested first: each absence needle catches the violation spellings")
print("  (case, whitespace, \\ and :: qualifiers) and rejects the benign look-alikes")
print("  superseded calendar basis absent from BOTH the service and the migration")
print("  no plan rate anywhere in the service or the job: the cap is on raw bytes")
print("  fail-OPEN exception beside the branch, and never sets over_limit on an unmeasured figure")
print("  anchor derived ONCE in the migration from Order::STATUS_COMPLETED, never on read")
print("  key = (user, server, node_type, period), attributed to the entitlement owner")
print("  enforcement on the node credential list, inside the nodeId guard")
print("  no hashing of any kind in the write path: body-hash dedup stays barred")
print("  NOT verified here: no PHP executed, no syntax check, no database, migration not applied,")
print("  and the write path has never run. At-least-once over-count is accepted by ruling.")
sys.exit(0)
