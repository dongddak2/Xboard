# -*- coding: utf-8 -*-
"""ManduVPN I18N-P0 — dictionary check for resources/lang/*.json.

WHAT THIS PROVES: that Laravel's JSON translation lookup, applied to these files
with fallback_locale = en-US, returns a string in the requested language for every
key the code can emit, with placeholders intact.

WHAT THIS DOES NOT PROVE: the HTTP response. This resolves the dictionaries the way
`Illuminate\Translation\Translator` does (current locale, then fallback, then the key
itself); it does not boot Laravel and does not send a request. Live proof needs a run
on the test host (see RC_BACKEND 72.5-style command list in the P0 report).

Run:  python .manduvpn-backup/check_lang_keys.py
"""
import io, json, os, re, sys

LANG = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "resources", "lang")
FALLBACK = "en-US"                     # config/app.php 'fallback_locale'
DEFAULT = "ko-KR"                      # config/app.php 'locale'
PH = re.compile(r":[a-zA-Z_]+")

D = {loc: json.load(io.open(os.path.join(LANG, loc + ".json"), encoding="utf-8"))
     for loc in ("en-US", "zh-CN", "zh-TW", "ko-KR", "ja-JP")}

HANGUL = re.compile(r"[가-힣]")
KANA = re.compile(r"[぀-ヿ]")
HAN = re.compile(r"[一-鿿]")


def translate(key, locale, replace=None):
    """Laravel's JSON resolution order: locale -> fallback -> the key itself."""
    line = D.get(locale, {}).get(key)
    if line is None:
        line = D.get(FALLBACK, {}).get(key)
    if line is None:
        line = key
    for name, value in (replace or {}).items():
        line = line.replace(":" + name, str(value))
    return line


fails = []


def check(cond, label):
    if not cond:
        fails.append(label)


# 1. every key the code can emit is present in ko-KR and ja-JP, byte-for-byte
emitted = set(D["en-US"]) | {
    "Plan period cannot be empty",
    "Wrong plan period",
    "This payment period cannot be purchased, please choose another period",
}
for loc in ("ko-KR", "ja-JP"):
    missing = sorted(emitted - set(D[loc]))
    check(not missing, "%s is missing %d keys: %s" % (loc, len(missing), missing[:3]))

# 2. no key falls through to the fallback for ko-KR / ja-JP, and none renders as
#    the raw key (that is what the Chinese-message bug looked like, one level up)
for loc in ("ko-KR", "ja-JP"):
    for key in sorted(emitted):
        check(key in D[loc], "%s: %r would fall back" % (loc, key))

# 3. placeholders survive translation
for loc in ("ko-KR", "ja-JP"):
    for key, val in D[loc].items():
        src = D["en-US"].get(key, key)
        check(set(PH.findall(src)) == set(PH.findall(val)),
              "%s: placeholder mismatch on %r" % (loc, key))

# 4. LOGIN-FAIL-I18N (named regression case, required by god via LEAD 2026-09-30):
#    the two strings a failing login can return. AuthController.php:243 (no such
#    account) and :258 (password mismatch) return the SAME string, and :235 is the
#    lockout. Reproduce live with a NONEXISTENT email only: :243 returns 400 without
#    incrementing the password-error counter, so it cannot lock anybody out. A real
#    account tried 5 times locks for 60 minutes.
#    On the test panel as measured 2026-09-30 that lockout does NOT fire at all: no
#    password_limit_* row exists in v2_settings, and the increment at :251 reads the
#    setting WITHOUT a default while the check at :232 defaults to 1 -- so the counter
#    is never written and 429 is unreachable. Do not delete the warning above on the
#    strength of "it did not lock": it is correct as soon as those settings exist, and
#    it is the operational default the code assumes.
WRONG_PW = "Incorrect email or password"
LOCKED = "There are too many password errors, please try again after :minute minutes."
check(HANGUL.search(translate(WRONG_PW, "ko-KR")), "ko-KR wrong-password is not Korean")
check(KANA.search(translate(WRONG_PW, "ja-JP")), "ja-JP wrong-password is not Japanese")
check(not HAN.search(translate(WRONG_PW, "ko-KR")), "ko-KR wrong-password still contains Han")
check(not HAN.search(translate(WRONG_PW, "en-US")), "en-US wrong-password contains Han")
check(HAN.search(translate(WRONG_PW, "zh-CN")), "zh-CN wrong-password lost its Chinese")

# 5. the headerless path — no content-language header means the default locale
check(HANGUL.search(translate(WRONG_PW, DEFAULT)),
      "default locale %s answers a wrong password in a non-Korean language" % DEFAULT)

# 6. an unknown locale, and an unknown key, both degrade to English - never Chinese
check(not HAN.search(translate(WRONG_PW, "fr-FR")), "unknown locale degrades to Chinese")
check(translate("a key nobody defined", "ko-KR") == "a key nobody defined",
      "an undefined key does not degrade to its English source")

print("keys: " + ", ".join("%s=%d" % (k, len(v)) for k, v in sorted(D.items())))
print("")
print("LOGIN-FAIL-I18N / wrong password, by locale:")
for loc in ("ko-KR", "ja-JP", "en-US", "zh-CN", "(no header -> %s)" % DEFAULT):
    print("  %-24s %s" % (loc, translate(WRONG_PW, DEFAULT if loc.startswith("(") else loc)))
print("")
print("LOGIN-FAIL-I18N / lockout (:minute = 60), by locale:")
for loc in ("ko-KR", "ja-JP", "en-US", "zh-CN"):
    print("  %-24s %s" % (loc, translate(LOCKED, loc, {"minute": 60})))

print("")
if fails:
    print("FAIL %d" % len(fails))
    for f in fails[:20]:
        print("  -", f)
    sys.exit(1)
print("ALL PASS")
