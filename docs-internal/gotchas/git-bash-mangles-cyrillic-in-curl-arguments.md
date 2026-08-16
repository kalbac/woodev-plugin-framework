# Gotcha: [tooling/windows] — Git Bash mangles Cyrillic in curl arguments, and the API blames you
> Tags: windows, msys, probes, encoding | Session: s76

## What happens

Probing a carrier API from Git Bash on Windows with a Cyrillic query returns errors that look like
the API's fault:

```
GET /v2/location/suggest/cities?name=Моск   -> 400 Bad Request   (no detail at all)
GET /v2/location/cities?city=Москва         -> 500 v2_internal_error
```

Two different failure codes from two endpoints reads as "the test contour is flaky" or "these
parameters are wrong" — and both readings send you rewriting a request that was fine.

## Root cause

The shell hands the argument to `curl` in the console's own code page, not UTF-8. `--data-urlencode`
then percent-encodes those (wrong) bytes faithfully, and the server receives a string that is not
the word you typed. A bare 400 with no body is exactly what a server does with a query it cannot
make sense of; a 500 is what one does when the mangled bytes reach further into its stack.

The tell: the SAME request with the Cyrillic pre-encoded works immediately.

## Fix

❌ Wrong — letting the shell carry the Cyrillic:

```bash
curl -s -G "$BASE/location/suggest/cities" --data-urlencode "name=Моск" -H "Authorization: Bearer $TOK"
# 400, every time
```

✅ Correct — pre-encode, and paste the percent-encoded form into the URL:

```bash
curl -s "$BASE/location/suggest/cities?name=%D0%9C%D0%BE%D1%81%D0%BA&country_code=RU" \
  -H "Authorization: Bearer $TOK"
# 200
```

✅ Also correct — do the probe where the encoding is not in question: a `wp eval-file` probe inside
the rig container (`docker cp` into `/tmp`, per the rig notes) runs UTF-8 source through PHP and
never touches the Windows console at all. That is the better route whenever the probe is really
about our own code rather than about the raw HTTP.

And when reading probe output back, remember the console garbles the DISPLAY too: piping JSON
through a terminal can show mojibake for a payload that is perfectly fine on disk. Decode with an
explicit UTF-8 writer before concluding the data is broken.

## Related

- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — the same shell mangling arguments, for paths instead of text
- [wp-safe-remote-request-local-rig](wp-safe-remote-request-local-rig.md) — the rig-probe rules this sits beside
- [an-invented-fixture-tests-your-assumptions-not-the-carrier](an-invented-fixture-tests-your-assumptions-not-the-carrier.md) — why the probe was being run against the live API at all
