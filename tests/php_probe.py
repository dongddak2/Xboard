# -*- coding: utf-8 -*-
"""Source probes for the no-PHP verifiers, with their own sensitivity tests.

WHY THESE EXIST RATHER THAN PLAIN SUBSTRING CHECKS. The verifiers assert things like "this file does not
call md5()". A plain `"md5(" in src` check has two failure directions and they are NOT symmetric:

  OVER-match - a longer harmless name contains the needle, e.g. "update(" contains "date(" and
               "password_hash(" contains "hash(". This gives a FALSE FAIL: loud, it stops you, you fix it.
  UNDER-match - a real violation spelled slightly differently, e.g. MD5( or md5 ( or admin_setting (.
               This gives a FALSE PASS: silent. Nothing stops you and the assertion reports success.

The needle that failed taught us; the needles that passed are where the silence lives. Measured on the
plain needles this replaces: MD5(, md5 (, SHA1(, HASH(, hash (, getContent (, GetContent(),
admin_setting (, ADMIN_SETTING( and server["rate"] were ALL missed - ten silent holes, because PHP
function names are case-insensitive and whitespace before the paren is legal.

So the probes below are case-insensitive, tolerate whitespace, accept a leading \\ or :: qualifier (both
are real ways to make the call), and use an identifier boundary so a longer name does not match. Narrowing
to silence a false FAIL is exactly how a false PASS gets built, so self_test() asserts BOTH directions:
every probe must catch the spellings a violation would use AND reject the benign look-alike.

Run the self-test on its own with:  python -I tests/php_probe.py
"""
import re


def strip_comments(src):
    """PHP source with // # and /* */ comments removed, string literals KEPT.

    A premise lives in code, never in prose: naming a hazard in a comment in order to say "we do not do
    this" must not read as doing it. String literals stay because a 'server_rate' inside a query IS use.
    """
    out, i, n = [], 0, len(src)
    state = None  # None | 'sq' | 'dq' | 'line' | 'block'
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ''
        if state is None:
            if c == "'":
                state = 'sq'
                out.append(c)
            elif c == '"':
                state = 'dq'
                out.append(c)
            elif c == '/' and nxt == '/':
                state = 'line'
                i += 1
            elif c == '#':
                state = 'line'
            elif c == '/' and nxt == '*':
                state = 'block'
                i += 1
            else:
                out.append(c)
        elif state in ('sq', 'dq'):
            out.append(c)
            quote = "'" if state == 'sq' else '"'
            if c == '\\':
                if i + 1 < n:
                    out.append(nxt)
                    i += 1
            elif c == quote:
                state = None
        elif state == 'line':
            if c == '\n':
                out.append(c)
                state = None
        elif state == 'block':
            if c == '*' and nxt == '/':
                state = None
                i += 1
        i += 1
    return ''.join(out)


def docblock_before(src, signature):
    """The /** ... */ block immediately preceding a signature - where a reason has to live to be read."""
    at = src.index(signature)
    head = src[:at]
    start = head.rfind('/**')
    return head[start:] if start != -1 else ''


def calls(code, name):
    """True if `code` calls the function `name`.

    Case-insensitive, because PHP function names are. Allows whitespace before the paren. Allows a
    leading \\ or :: qualifier, since both are real call spellings. Rejects a longer identifier that
    merely ends with `name`, so password_hash() is not a hash() call and ->update() is not a date() call.
    """
    return re.search(r'(?<![A-Za-z0-9_])' + re.escape(name) + r'\s*\(', code, re.I) is not None


def reads_key(code, key):
    """True if `code` indexes an array by the literal `key`, in either quote style.

    Plain `"'rate'" in src` misses server["rate"], which is the same access written differently.
    """
    return re.search(r'\[\s*[\'"]' + re.escape(key) + r'[\'"]\s*\]', code) is not None


def self_test():
    """Both error directions, asserted. A probe is only trustworthy if its sensitivity is measured."""
    # --- calls(): must CATCH every spelling a real violation would use
    for spelling in ('md5($x)', 'MD5($x)', 'md5 ($x)', 'md5\t($x)', '\\md5($x)', 'Foo::md5($x)',
                     'self::md5($x)', '= md5($x);'):
        assert calls(spelling, 'md5'), 'calls() missed a real violation: %r' % spelling
    for spelling in ('getContent()', 'getContent ()', 'GetContent()', '->getContent()'):
        assert calls(spelling, 'getContent'), 'calls() missed: %r' % spelling
    for spelling in ('admin_setting($k)', 'admin_setting ($k)', 'ADMIN_SETTING($k)'):
        assert calls(spelling, 'admin_setting'), 'calls() missed: %r' % spelling

    # --- calls(): must REJECT the benign look-alikes that made the plain needles over-match
    assert not calls('password_hash($p)', 'hash'), 'calls() over-matched password_hash as hash'
    assert not calls('hash_equals($a, $b)', 'hash'), 'calls() over-matched hash_equals as hash'
    assert not calls('->update([])', 'date'), 'calls() over-matched update as date'
    assert not calls('new DateTime()', 'date'), 'calls() over-matched DateTime as date'
    assert not calls('$x = $md5;', 'md5'), 'calls() matched a variable, not a call'
    assert not calls('my_md5($x)', 'md5'), 'calls() over-matched a longer function name'
    # the one that must still work after all that narrowing - narrowing is how a false PASS gets built
    assert calls('hash($x)', 'hash'), 'calls() was narrowed until it missed the real thing'

    # --- reads_key(): both quote styles, and not a bare mention
    for spelling in ("$server['rate']", '$server["rate"]', "$server[ 'rate' ]"):
        assert reads_key(spelling, 'rate'), 'reads_key() missed: %r' % spelling
    assert not reads_key('$rate = 1;', 'rate'), 'reads_key() matched a variable'
    assert not reads_key("'rate' => 1,", 'rate'), 'reads_key() matched an array literal key'

    # --- strip_comments(): prose is removed, string literals survive
    assert 'md5' not in strip_comments('// never call md5() here\n$x = 1;')
    assert 'md5' not in strip_comments('/* md5() is banned */ $x = 1;')
    assert "'server_rate'" in strip_comments("$q->where('server_rate', 1); // not a comment match")
    assert 'kept' in strip_comments('$s = "kept"; # dropped')
    return True


if __name__ == '__main__':
    self_test()
    print('PASS  php_probe self-test: every probe catches the violation spellings and rejects the look-alikes')
