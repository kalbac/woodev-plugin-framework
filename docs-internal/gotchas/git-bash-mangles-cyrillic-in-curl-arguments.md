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

## The same trap through `gh`, where it PERSISTS — added s115

`curl` gives you an error. `gh` does not: it writes the mangled bytes into GitHub and returns 200,
so the damage outlives the session.

Creating a board's single-select options with Cyrillic names:

```bash
gh project field-create 9 --owner kalbac --name "Приоритет" --data-type SINGLE_SELECT \
  --single-select-options "Сейчас,Следом,Потом,Ждёт оператора,Заморожено,После v2"
```

The server stored `РЎРµР№С‡Р°СЃ` for `Сейчас` — permanently, on the board the whole team reads.

⚠ **The cause is NOT established.** The byte-identical call created board №6's field correctly the
same hour, and board №9's incorrectly. Do not write down a mechanism for this; write down the check.

**The check — read the value back FROM THE SERVER, never trust your own echo.** A terminal can
garble the display of data that is fine, and it can also display fine what it stored broken, so the
only trustworthy read is a separate query:

```bash
gh api graphql -f query='{ user(login:"kalbac"){ projectV2(number:6){
  field(name:"Приоритет"){ ... on ProjectV2SingleSelectField { options { id name } } } } } }' \
  --jq '.data.user.projectV2.field.options[] | "\(.id)  \(.name)"'
```

**The fix — write through `gh api graphql`, which carried the same Cyrillic intact every time:**
`updateProjectV2Field(input:{ fieldId:…, singleSelectOptions:[{name:"Сейчас", color:RED, …}] })`.

⚠ **`updateProjectV2Field` REPLACES the option set, so every option id changes.** Any id you wrote
into a doc or a script before the repair is now dead. Re-read the ids after any such repair — the
s115 repair invalidated all six ids it had just recorded.

## Related

- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — the same shell mangling arguments, for paths instead of text
- [wp-safe-remote-request-local-rig](wp-safe-remote-request-local-rig.md) — the rig-probe rules this sits beside
- [an-invented-fixture-tests-your-assumptions-not-the-carrier](an-invented-fixture-tests-your-assumptions-not-the-carrier.md) — why the probe was being run against the live API at all
