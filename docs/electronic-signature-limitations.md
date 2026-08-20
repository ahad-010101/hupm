# Electronic signature — what it is, and what it is not

**Written at WP-22. Required by its definition of done, and by TDD §9.3.**
**Read this before relying on an electronic signature for anything contested.**

HUPM signs documents in-house. There is no DocuSign, no Adobe Sign, no third
party at all. That was a deliberate choice — it avoids a per-envelope cost and a
dependency on an integration that could change under us — and it comes with two
consequences the client needs to have been told about in writing.

---

## 1. We are both the custodian and a party

An electronic signature is only as good as the evidence behind it. Ours is
thorough: consent, typed name, the exact label of the button pressed, the
signer's IP and user agent, a millisecond timestamp, and a SHA-256 of the exact
bytes on screen. All of it is immutable and all of it is on the certificate
appended to the executed PDF.

But **Heads Up Enterprises holds that evidence, and Heads Up Enterprises is a
party to any dispute it would be used in.**

A third-party provider attests as a neutral: they have no stake in whether the
tenant signed. We cannot say that about ourselves. In practice this means:

- For an uncontested document — house rules, a maintenance notice, an
  acknowledgement — this is fine and normal.
- For a document that might be argued over in a Georgia dispossessory
  proceeding, opposing counsel can point out that the landlord produced,
  stored and controls every piece of evidence that the signature happened.

Nothing in the build fixes this. It is a property of doing it in-house.

**What reduces the risk:** the integrity hash. If the file has not changed since
signing, the verification on `/admin/signatures` says so, and any alteration —
including one by us — makes it say the opposite. That is a check anyone can run
and we cannot quietly pass. It is the strongest thing we have.

---

## 2. Housing Authority documents may require wet signatures

**This one needs an answer per agency, and it has not been asked yet.**

HAP contracts and Section 8 tenancy addenda frequently require ink. Some housing
authorities accept electronic execution; some do not; some accept it only on
their own portal. Signing a HAP contract in HUPM and discovering the agency does
not recognise it means the contract is not in force — with a tenancy already
underway.

**Before using this feature for any Housing Authority document:**

1. Ask the agency, in writing, whether they accept electronically signed HAP
   contracts and tenancy addenda.
2. If they do, ask what form they accept — our certificate, their portal, or a
   specific provider.
3. Record the answer against the housing authority record.

Until that is answered for a given agency, **use paper for HAP contracts and
tenancy addenda.** The document vault stores a scan perfectly well, and a
scanned wet signature has none of the questions above hanging over it.

This is Q-13-shaped: a commercial question with a technical consequence, and one
only the client can resolve.

---

## What the system actually records

For each signature, kept permanently and immutably:

| Element | Where it lives |
|---|---|
| Consent text, version and its SHA-256 | `consent_records`, verified against version control |
| Typed legal name | `signature_events.typed_name` |
| Exact control label pressed | `signature_events.button_label` |
| Signer identity and email | `signature_requests.user_id` |
| IP address and user agent | every `signature_events` row |
| Timestamp, millisecond precision | `signature_events.occurred_at` |
| SHA-256 of the exact bytes signed | `signature_events.document_sha256` |
| That the whole document was scrolled | `signature_events` `scrolled_complete` |
| The full event log | rendered onto the certificate page |

The consent text lives in `App\Support\ElectronicRecordsConsent`, in version
control, never in the database. A consent record that stored only "agreed,
version 1.0" would prove nothing if version 1.0 were a row somebody could edit.

---

## One technical boundary worth knowing

The certificate is appended to the original PDF by importing its pages verbatim
— nothing is re-rendered, because re-rendering would change the bytes and break
the hash the certificate asserts.

**PDFs that use cross-reference streams cannot be imported by the free parser.**
Newer Word and Acrobat output sometimes does. When that happens the certificate
is still produced, it stands alone, and it says on its face that the document is
retained separately and identifies it by hash. The signature is still valid — the
binding is cryptographic rather than physical — but the executed file is a
certificate rather than a certificate plus the document.

If that becomes common in practice, the fix is the commercial FPDI parser add-on
(a one-off licence), not a redesign.
