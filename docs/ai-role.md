# AI Role in the Maintenance Intelligence System

This document captures what the AI is *for* in this product, the authority
rules it must respect, and the systems it will eventually replace or unify.
It is the north star for anyone designing prompts, retrieval pipelines,
task-suggestion logic, or UI surfaces that involve the assistant.

The AI is **advisory only** — every suggestion is reviewed and accepted by a
certified human technician before it becomes a live task or a sign-off.
Nothing in this document changes that.

---

## 1. The job: a seeker of authoritative information

A line technician's time is dominated by *finding* the right answer, not by
performing the work itself. The information lives in several systems with
different UIs, search quality, and refresh cadences. The AI's primary job is
to **collapse that search** — to surface the right page of the right
document, or the right pages across multiple documents, ranked in the order
of regulatory and operational authority.

### Authority order (most authoritative first)

1. **Endeavor Tech Supplements** — Endeavor Air's own documents created from
   FAA administrative directives (ADs, etc.) before those items are folded
   into the manufacturer manual. Supersede everything below.
2. **Manufacturer docs** (AMM, FIM, SB, CMM, etc.) — Endeavor may amend
   these in-house with FAA permission. Authoritative wherever a tech
   supplement does not override.
3. **FAA AC 43.13-1B** — *Acceptable Methods, Techniques, and Practices —
   Aircraft Inspection and Repair*. The baseline applied when nothing above
   specifies otherwise.

The AI must **tag every retrieved chunk with its tier** and **resolve
conflicts top-down**. Citations in AI output must name the tier and the
specific document so the technician can verify the chain of authority on
the line.

---

## 2. Source systems (today and tomorrow)

### Today (at Endeavor, outside this app)

Technicians currently jump between three+ systems:

- **SABER** — legacy 1990s-era web application. Slow, dated UI, painful to
  search.
- **PDFs / FAA Maintenance Manual / DigiDocs** — manufacturer references in
  multiple formats and locations.
- **Veryon Diagnostics** — where trend and fault-occurrence data lives.
  Effectively the source of what becomes a tasklist.

### Today (inside this app, early prototype phase)

We are using **PDFs as placeholders for all of the above** so the workflow
can be built end-to-end before the real integrations exist. The
`documents` table and the `OPEN IN MAINTENANCE PORTAL` flow assume PDF for
now. The architecture should *not* assume PDF forever.

### Tomorrow (target state)

One application, one search surface, with the AI doing the routing:

- Ingest **PDFs and webpages** (and any future formats) into a tier-aware
  index. SABER content, Endeavor tech supplements, manufacturer manuals,
  AC 43.13-1B, and Veryon trend data all become first-class sources.
- The AI returns **the specific page of the specific document** (or a
  multi-doc bundle of pages) that answers the technician's question,
  ordered by authority tier.
- The AI scrapes those sources to **suggest tasklist items** for the active
  fault, each item cited back to the page(s) it came from.

---

## 3. Tasklist: shared across people and shifts

A tasklist is not the property of one technician. It is the shared state
that lets work survive handoffs.

- **Workers** execute tasks and mark progress.
- **Supervisors** track open items, sign off completed work, and watch
  blockers.
- **Other shifts** pick up partially completed work and continue without
  losing context — who did what, what the references were, what is still
  open.

The AI's task suggestions feed into this shared list. They are proposals
until a certified human accepts them.

---

## 4. Deferred-repair workflow

Not every reported failure is fixed immediately. The system must support a
**inspected → deferred** state for items that are safe for continued
operation but require non-urgent repair:

- The item was inspected.
- A certified tech determined it is **safe for operation** as-is.
- The repair is **non-urgent** and is deferred to a scheduled window.
- The deferral, who authorized it, and the document(s) that justify
  deferring it remain visible to supervisors and to subsequent shifts.

The AI should be able to:

- Recognize when a finding qualifies for deferral under the authority docs
  (e.g., MEL / CDL / approved tech supplement).
- Surface the relevant page(s) that justify deferral so the human can
  confirm.
- Propose the deferred-repair task with a follow-up due date sourced from
  the authority doc, not invented.

---

## 5. AI provider strategy — Copilot and Claude in parallel

At Endeavor today, **Microsoft Copilot is likely the only enterprise-approved
AI**. Approval for Anthropic (Claude) is being pursued but is not in place.
Both integrations must therefore be developed **in parallel** so that:

- The product can ship on Microsoft Copilot the moment IT approval lands.
- It can switch to Claude (or run both side-by-side for A/B comparison) once
  Anthropic is approved, without rewriting the application layer.

### What "Microsoft Copilot" means is not yet pinned down

Endeavor IT has not yet specified which Microsoft surface is the approved
one. The three plausible answers, each with materially different code:

- **Azure OpenAI Service** — enterprise HTTPS API for GPT-4o etc. Shape is
  similar to Anthropic's Messages API; the easiest of the three to slot in
  behind a shared provider interface.
- **M365 Copilot (Microsoft Graph)** — the chat product embedded in
  Microsoft 365. Integration is via Microsoft Graph + the Copilot APIs,
  with Entra ID (OAuth) auth and a tenant-data-oriented programming model.
  More invasive to integrate than Azure OpenAI.
- **Copilot Studio** — low-code platform for custom copilots, usually
  surfaced via Direct Line / webchat in Teams. Heaviest integration;
  conversation is hosted by Microsoft rather than the app calling a
  completion endpoint.

The eventual provider abstraction should be flexible enough to host any of
the three behind the same internal contract. When Endeavor IT clarifies,
this section gets narrowed.

### Architectural requirements that follow

- **Provider interface.** Replace today's concrete `MIS\AiClient` (curl-to-
  `api.anthropic.com`) with an `AiProvider` interface and at least two
  implementations: `AnthropicProvider` (existing logic) and a
  `CopilotProvider` (whichever Microsoft surface is approved).
- **Factory + config.** A factory reads `config/config.php` and returns the
  configured provider. The active provider should be selectable per
  environment and, ideally, per feature (e.g., diagnostic chat vs. task
  suggestion) in case the providers have different strengths or limits.
- **Capability parity.** The interface must support prompt caching,
  tool/function use, streaming, and system + context separation, because
  the doc-grounding work in §1–§4 needs all of those regardless of
  provider. Where a provider lacks a capability natively (e.g., Anthropic
  prompt caching vs. Azure OpenAI session reuse), the implementation
  fakes it or no-ops it cleanly — the calling code should not branch on
  provider name.
- **Audit logging.** Every request must record which provider answered it,
  the model id, and token usage. Compliance reviews will care about which
  AI saw which data.
- **Data handling.** Some surfaces (e.g., M365 Copilot) implicitly access
  tenant data. Document data-flow boundaries before integration.

### Where the existing code is today

- `src/AiClient.php` is a thin curl wrapper around the Anthropic Messages
  API with a deterministic stub fallback when no API key is set.
- It is consumed by `public/api/ai.php` (the diagnostic chat) and
  `public/api/tasks.php` (the AI-suggest task flow).
- Refactoring to the provider-interface model touches both callers, but
  the public-facing API methods (`ask(...)`) can stay the same shape so
  the JS does not change.

## 6. Design implications for current and future code

Things to keep in mind when touching this codebase:

- **Reference documents need a tier field.** The current `documents` table
  treats every doc as equal. To do authority-ranked retrieval we need a
  tier (`tech_supplement` | `manufacturer` | `faa_baseline`) on every
  document or chunk.
- **Retrieval must handle PDF *and* HTML.** Don't lock the pipeline to PDF
  text extraction only — SABER and webpage sources are first-class.
- **Citations are mandatory.** AI output that proposes a task or answers a
  diagnostic question must cite the tier and the page (or anchor) of the
  document it came from. No ungrounded suggestions.
- **Conflict resolution is top-down.** When two sources disagree, the
  higher-tier source wins and the AI must say so, not silently choose.
- **The current AI-suggest task flow** (`public/api/tasks.php`) feeds only
  *document titles* into the LLM context. This is the placeholder version.
  Replacing it with retrieval-grounded suggestions is the main upgrade
  path implied by this document.
