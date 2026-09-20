# 6. An assistant that mostly refuses

- **Status:** accepted
- **Date:** 2026-09-20
- **Context:** §9.6 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md), "AI features — scoped honestly"
- **Constrained by** [ADR 0002](0002-stay-on-cpanel-shared-hosting.md)

This is the governance page §9.6 asks for — "in one page not forty".

## The decision

The pilgrim assistant is built, switched off, and **structurally incapable of
answering a religious question that no named scholar has approved** — whether
it is switched on or not, and whatever an API key is set to.

§9.6 asks for an assistant that answers "strictly from scholar-approved
Knowledge Centre content, with citations and a hard 'I'll connect you to an
advisor' fallback", and ends: **"Never let it improvise rulings."** Every one
of those is implemented as code above the model, not as wording inside a
prompt.

## Why the constraints are not in the prompt

A system prompt that says "only use the sources below" is a request. A model
that ignores it is not malfunctioning; it is doing the thing models do. A
prompt is the right place to make refusal *easy* and the wrong place to make
it *certain*.

So five constraints sit in `App\Services\Assistant\PilgrimAssistant`, outside
the model:

1. **Only approved content is retrieved.** `App\Services\Assistant\Corpus`
   queries through the editorial gate's `live()` scope. A draft, an article in
   review and a withdrawn one are invisible to it, and no prompt wording can
   reach them.
2. **No matching source means no model call at all.** The question goes
   straight to a human, and the model is never asked.
3. **No configured provider means no model call.**
4. **An answer that cites nothing is discarded.** The model is told to mark
   each claim `[1]`, `[2]` and so on; a reply with no valid marker is thrown
   away and replaced with the referral. An uncited sentence about a rite is
   exactly the improvised ruling §9.6 forbids, and reading it will not tell
   you which it is.
5. **The asker is never named in the prompt.** The exchange log knows who
   asked; the model does not.

Constraint 4 is the one worth arguing about, because it throws away work that
may well have been correct. That is the right trade here: the cost of
discarding a good answer is one pilgrim waiting for a person, and the cost of
showing a bad one is a pilgrim performing their Umrah wrongly on the authority
of a travel agency's website.

## Today it answers nothing, and that is correct

The Knowledge Centre, the Ziyarah Guide and the Learning Academy contain no
approved content, because approval needs a named scholar and **nobody has been
named**. Every question therefore takes route 2 above and goes to a person.

This is the feature working. An assistant that began answering the moment a
key was pasted in, from a corpus of nothing, is the failure this design exists
to prevent — and it is the shape of failure that is hardest to notice, because
a fluent answer from no source reads exactly like a fluent answer from a good
one.

## Approved use cases

- Questions a pilgrim asks in the portal, answered from approved pages, with
  the passages cited and openable so the answer can be checked.
- Handing a question the approved pages do not cover to the scholar's queue,
  with the question carried over so the pilgrim does not type it twice.

## Prohibited, and enforced rather than asked for

| Prohibited | How it is prevented |
|---|---|
| An independent ruling | No approved source, no answer (2); no citation, no answer (4) |
| Reasoning by analogy to a case the sources do not cover | Constraint 4 discards it — an analogy has nothing to cite |
| Scripture the sources do not contain | Same |
| Medical or legal advice | Same — and named in the prompt so the model declines gracefully |
| Personal data in the prompt | Constraint 5; the provider class is never handed a name |
| Publishing an answer to other pilgrims | Not built. Publishing is a scholar's act, gated by `question.publish` |

## Human in the loop

Every customer-facing use is either answered from approved words a scholar
already signed off, or referred to a scholar. There is no path by which the
assistant's output reaches another pilgrim, becomes a Knowledge Centre page,
or is quoted by the office as Rihla's position.

## The label

Anything a model wrote carries "AI-assisted, from our scholars' own words",
**above** the text rather than beneath it. A disclosure under an answer is
read after the answer has been believed.

## Logging, and an end to it

`assistant_exchanges` records the question, the answer, whether it referred,
the reason it referred, and the passages it was allowed to read — which is
what makes an answer auditable against its sources afterwards.

§9.6 requires the log and says nothing about how long to keep it. A religious
question is often personal in a way the asker would not expect to be filed, so
`assistant:prune` deletes exchanges older than
`assistant.log_retention_days` (180 by default). Nothing runs it
automatically — there is no queue worker (ADR 0002) — so it belongs in the
cPanel cron beside `notices:sweep`.

The reason column is the useful one in the other direction too: a month of
referrals says exactly what the Knowledge Centre is missing.

## Retrieval is keyword matching, and that is the safe crudeness

There is no vector store on cPanel shared hosting and no embedding budget
(ADR 0002). `Corpus` matches the words of the question against the title and
body of approved content. That finds less than a good retriever would, and
finding less means referring more questions to a human — so the failure mode
of the crude retriever is an unanswered question, not a wrong ruling. When the
corpus is large enough for that to be the wrong trade, the replacement goes
behind `Corpus` and nothing else changes.

## What this costs, and who has to decide it

Nothing, today. Switching it on needs an Anthropic API key and a view on cost
per question, and neither exists. Both are the owner's to supply, and so is
the thing that matters more: **a scholar willing to put their name to the
pages.** Without that, a key changes nothing — which is the point of building
it in this order.
