# Trusted timestamps

A timestamp proves that a note, and the files the note links to, existed in that
form at a given time. It says nothing about who wrote them, and nothing about
whether they are true. Signed by a timestamp authority, which is a service with
no stake in the content.

The feature is invisible until an authority is configured. With none, the
Timestamp button is not shown.

## What is stamped

Not the note file. A note says "the spectrum is in
`attachments/cell-07.csv`", so a token over the note's own bytes would say
nothing about the spectrum. Instead the app builds a **manifest**: a small text
file holding the note's SHA-256 plus the SHA-256 of every file inside the notes
tree that the note links to. The manifest is what gets stamped, and it is kept.

```
ScienceData note timestamp manifest v1
note: Lab/Run 7.md
title: Run 7
user: alice
built: 2026-09-20T22:14:03+00:00

sha256 6f1c…  Lab/Run 7.md
sha256 b499…  Lab/attachments/cell-07.csv
```

Plain text on purpose: someone checking this in ten years should not need our
code to see what was covered.

## Files the feature writes

Two files per timestamp, in a **visible** `timestamps/` folder at the root of
the top-level notebook, beside `attachments/`. Visible because a token has to be
handed to other people, and has to travel with the notebook when it is shared,
published or deposited. Both are excluded from the notebook tree the same way
`attachments` is, so they never appear as a notebook.

| File | What it is |
|------|-----------|
| `<notebook>/timestamps/<note>-<UTC>.manifest` | the text above, exactly as stamped |
| `<notebook>/timestamps/<note>-<UTC>.tsr` | the authority's answer: a full RFC 3161 response, DER, carrying the authority's certificate |

`<note>` is the note's path with `/` replaced by `__`; `<UTC>` is
`YYYYMMDDTHHMMSSZ`. Nothing else is written, and nothing is ever deleted: a
timestamp of a note that has since changed is still evidence about the note as
it was.

## Settings

Two places, in this order.

**A config file**, which is where an operator should put them: one block in any
`config/*.config.php`, read on every request, editable in an editor and
revertible like any text.

```php
'markdown_notes_tsa' => [
    'url'    => 'https://example.org/tsa/',
    'ca'     => '',          // empty: use my_ca_certificate
    'policy' => '',
    'pins'   => [
        ['fingerprint' => '4EBC…', 'from' => '2026-09-20', 'until' => '', 'note' => 'the authority we run'],
    ],
],
```

**App configuration**, the `oc_appconfig` table with `appid` = `markdown_notes`,
used for whatever the block does not mention. This is the path for a container
built by a script.

| Key in the block | App-config key | Meaning |
|------|-----|---------|
| `url` | `tsa_url` | The authority's RFC 3161 endpoint. Empty (the default) hides the feature. |
| `ca` | `tsa_ca` | CA file that tokens are verified against. Empty means the `my_ca_certificate` system value, which every node in this deployment already sets, so this is normally left alone. |
| `policy` | `tsa_policy` | Optional policy OID to ask the authority for. |
| `pins` | `tsa_pins` (JSON) | The authorities this node accepts, and when. See below. |

```
occ config:app:set markdown_notes tsa_url --value https://example.org/tsa/
```

Either way the setting is per node, so it has to be made on each.

## Accepted authorities (`tsa_pins`)

Chain validation answers one question: did our certificate authority vouch for
whoever signed this token. It does not answer the other: is that the authority
we actually run, during a period we still trust it.

`pins` answers the second. In the config file it is a PHP array; in app
configuration the same thing as a JSON array in one row:

```json
[
  {
    "fingerprint": "4EBCF9E6AB9EB5238F8A7E7F055E70E28DEB98A6C9F0039C93FFBC39B5D56907",
    "from": "2026-09-20",
    "until": "",
    "note": "authority on the silo2 pod, issued 2026-09-20"
  }
]
```

A `pins` key in the config file wins outright, even when empty. `occ
markdown_notes:tsa-pins` says which of the two is in force.

| Field | Meaning |
|-------|---------|
| `fingerprint` | SHA-256 digest of the authority's certificate, uppercase hex, no colons. See below. |
| `from` | `YYYY-MM-DD`, or empty for "always". Tokens dated before this are rejected. |
| `until` | `YYYY-MM-DD` **inclusive**, or empty for "onwards". Tokens dated after this are rejected. |
| `note` | Free text. Why this entry exists. |

An **empty array, or no value at all, accepts any token that chains to the CA.**
That is where a server starts, and a reasonable place to stay until there is
something to distrust.

This list is the withdrawal mechanism, and it exists because this deployment's
CA publishes no revocation list. That is deliberate: a revocation list is signed
by the CA, so it is worthless in the one case that matters, a stolen CA key.
Removing an entry here does not depend on the compromised key at all.

The dates are the part that matters. Retire an authority by deleting its entry
and every genuine token it ever issued stops verifying too. Give it an `until`
of the day before the breach instead, and only the compromised window is cut
out, while years of honest evidence keep verifying.

### What a fingerprint is

The SHA-256 digest of the certificate itself (the DER bytes). It names one exact
certificate: two certificates with the same subject, or a re-issue of the same
key, have different fingerprints. It is what every tool prints when asked to
identify a certificate:

```
openssl x509 -in tsa.crt -noout -fingerprint -sha256
SHA256 Fingerprint=4E:BC:F9:E6:AB:…
```

Colons, case and spacing differ between tools; the digest does not, so they are
stripped before comparison.

## The command

`occ markdown_notes:tsa-pins` computes fingerprints and prints the list. By
default it **changes nothing**: given an authority to add, it prints the block to
paste into the config file, because a trust setting should be reviewed and saved
by a person. With `--write` it stores the list in the `tsa_pins` app-config row
instead. It never edits a file.

| Option | Effect |
|--------|--------|
| `--add <path>` | Add the authority in a **PEM certificate file**, or read the authority's certificate out of a **`.tsr` token** and add that. The fingerprint is computed for you. |
| `--fingerprint <sha256>` | Add (or update) an entry by digest, in any spelling. |
| `--from <YYYY-MM-DD>` | Accept tokens dated on or after this day. |
| `--until <YYYY-MM-DD>` | Accept tokens dated on or before this day, inclusive. |
| `--note <text>` | Free text kept with the entry. |
| `--remove <sha256>` | Drop the entry with that fingerprint, from app configuration. To remove one from the config file, delete the line. |
| `--write` | Store the result in app configuration instead of printing it. |

`--fingerprint` exists for three situations `--add` does not cover: you were
given a digest rather than a file, for instance by whoever runs the authority; a
token carries more than one certificate and you want a particular one, since
`--add` takes the signer and prints the rest; or you are changing the dates on an
entry that already exists and have no copy of the certificate to hand. Adding a
fingerprint that is already listed replaces that entry rather than duplicating
it, which is how an authority gets retired:

```
occ markdown_notes:tsa-pins --fingerprint 4EBC… --until 2026-09-19 --note "key compromised, discovered 20 Sep"
```

Examples:

```
occ markdown_notes:tsa-pins                                        # show the list
occ markdown_notes:tsa-pins --add /var/lib/tsa/tsa.crt --from 2026-09-20
occ markdown_notes:tsa-pins --add "Lab/timestamps/Run 7-….tsr"     # learn it from a token
occ markdown_notes:tsa-pins --remove 4EBCF9E6AB9E…
```

## What the dialog reports

Two questions are answered separately, because they fail for different reasons
and must not be confused.

**Is the token genuine?**

| Shown | Meaning |
|-------|---------|
| Token verified | Chains to the CA, and the signer is accepted for that date. |
| Token verified as of its own time | As above, but the authority's certificate has expired since. OpenSSL builds the chain as of now, so without this every token would fail the day the certificate expired. Verified again at the time the token carries. |
| Not from an accepted authority | Chains to the CA, but the signer is not in `tsa_pins`. The signer's subject is named. |
| Dated outside the accepted period | The signer is listed, but this token is dated outside its window. |
| Token does NOT verify | The signature does not check out. The reason from openssl is shown. |
| Token not checked | No CA file on this server, so nothing to check against. |
| Manifest missing | The `.tsr` is there but its `.manifest` is not, so there is nothing to compare. |

**Do the files still match?** Separately, each file named in the manifest is
re-hashed. A changed note is **not** an error: it is the normal state of a
notebook still being written, so the dialog names what changed or went missing
rather than reporting a failure.

## Checking a token by hand

Nothing here is proprietary. Given the two files from `timestamps/`:

```
openssl ts -reply  -in "Run 7-20260920T221403Z.tsr" -text
openssl ts -verify -in "Run 7-20260920T221403Z.tsr" \
    -data "Run 7-20260920T221403Z.manifest" -CAfile my_ca_cert.pem
```

and to see who signed it:

```
openssl ts -reply -in x.tsr -token_out -out x.tk
openssl pkcs7 -inform DER -in x.tk -print_certs
```

The authority itself, how it is stood up and the record it keeps of everything
it has issued, are documented with the service rather than with this app. On a
ScienceData node that is
`/usr/local/share/doc/sciencedata/timestamping.md`.
