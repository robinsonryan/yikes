---
name: process-yikes
description: Process the Yikes dev/QC note queue. Use when the user asks to "process the yikes notes", "work the yikes queue", "handle the yikes backlog", "run the yikes", "implement the approved yikes notes", or otherwise wants the in-app Yikes feedback notes triaged and the approved ones implemented.
---

# Process Yikes Notes

Yikes notes are dev/QC feedback captured in-app (bug, layout, idea, refactor). Each note
carries the page context it was captured on (URL, route name, page/component name, document
title, user/account, dark/light mode, viewport, optionally the specific element it is about),
an optional app-state snapshot, and zero or more screenshots. Your job: report the queue,
then implement the **approved** notes.

## Step 0 — detect the mode

The package runs in one of two modes; determine which BEFORE anything else by checking the
host app's env/config for a hub URL:

```bash
grep -E '^YIKES_(HUB_URL|HUB_TOKEN|PROJECT)=' .env
# or: php artisan tinker --execute="var_dump(config('yikes.hub.url'));"
```

- **`YIKES_HUB_URL` empty or absent → LOCAL MODE.** Notes are flat files committed to this
  repo. Follow "Local mode" below.
- **`YIKES_HUB_URL` set → HUB MODE.** The hub is the system of record; notes are fetched
  over its agent API, implemented locally, and marked done on the hub. Follow "Hub mode"
  below. The app-local `.yikes/` directory is only a capture queue / working copy and is
  NOT committed.

---

## Local mode

### Locating the notes

The notes directory defaults to `.yikes/` at the project root. The path is configurable —
check `config/yikes.php` (or the package config, `config('yikes.path')`) for a custom path
before assuming the default. Layout:

```
.yikes/
  notes/<id>.md            # one note per file: YAML frontmatter (context) + markdown body
  state/<note-id>.json     # app-state snapshot (may be absent)
  screenshots/<note-id>/   # attached screenshots (PNG)
```

### Procedure

#### 1. Read the queue and report

Read every file in `.yikes/notes/*.md`. Group by frontmatter `status` and report counts to the
user, e.g. "6 notes: 2 approved, 3 new, 1 done." List each `new` note (id, type, title/first
line) so the user can triage them — but do not work on them.

#### 2. Select work

Work ONLY notes with `status: approved`, unless the user names a specific note — then work
that one regardless of status (confirm first if it is `done` or `ignored`). Skip `on-hold`,
`ignored`, and `done` entirely. If there are no approved notes, say so and stop after the
report.

#### 3. Implement each approved note

Follow the shared implementation steps (below).

#### 4. Mark the note done

After the implementing commit, edit the note's frontmatter:

```yaml
status: done
resolution:
  commit: <sha of the implementing commit>
  note: <one-line summary of what was done>
  completed_at: <ISO8601 timestamp>
```

Leave everything else in the file untouched. Include this frontmatter edit in the same commit
as the implementation, or in an immediate follow-up commit (e.g.
`chore(yikes): mark 20260711-142530-a1b2c3d4 done`).

---

## Hub mode

The hub owns the queue and triage. You talk to its `/api/v1` agent API with a bearer token.

**Token:** use `YIKES_HUB_AGENT_TOKEN` from the DEV MACHINE'S `.env` — an `agent`-ability
token used only by this skill. (`YIKES_HUB_TOKEN` is the app's `ingest` token — local
captures push with it — and ingest tokens get `403` on every agent endpoint. If
`YIKES_HUB_AGENT_TOKEN` is absent, fall back to `YIKES_HUB_TOKEN`, but if that 403s on the
list call it's the ingest token — ask the user for an agent token.) `YIKES_PROJECT` is the
project's slug on the hub.

```bash
HUB="$(grep -E '^YIKES_HUB_URL=' .env | cut -d= -f2-)"
TOKEN="$(grep -E '^YIKES_HUB_AGENT_TOKEN=' .env | cut -d= -f2-)"
[ -n "$TOKEN" ] || TOKEN="$(grep -E '^YIKES_HUB_TOKEN=' .env | cut -d= -f2-)"
PROJECT="$(grep -E '^YIKES_PROJECT=' .env | cut -d= -f2-)"
```

**Fail closed on the project slug.** `$PROJECT` is the *only* thing scoping the queue to
this codebase. If it is empty, STOP — do not fetch, do not build a `/projects//notes` URL,
do not fall back to "all projects." An unset `YIKES_PROJECT` is a misconfiguration, not a
"pull everything" signal:

```bash
[ -n "$PROJECT" ] || { echo "YIKES_PROJECT is unset — refusing to pull. Set it in .env."; exit 1; }
```

Treat `$PROJECT` as this run's identity: every note you fetch, materialize, or implement
must belong to it. The hub enforces this at the API (a token gets `403` on any slug that is
not its own project), and the checks below re-enforce it locally so a stale or misplaced
working copy can never smuggle another project's notes into this codebase.

#### 1. Fetch the approved queue and report

```bash
curl -sS -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$HUB/api/v1/projects/$PROJECT/notes?status=approved"
```

If this returns `403`, the token's project does not match `$PROJECT` (wrong token, or wrong
slug) — report it and stop. Do NOT try other slugs.

Response: `{ "data": [ …note resources… ], "next_cursor": "…" }` — ordered oldest-first;
follow `cursor=<next_cursor>` pages until `next_cursor` is `null`. Report the approved count
(and note that `new`-note triage happens in the hub UI, not here). If there are no approved
notes, say so and stop.

#### 2. Materialize each note into a local working copy

For each approved note, fetch the detail (the list omits `state`) and its screenshots:

```bash
curl -sS -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$HUB/api/v1/notes/<id>"

# one per entry in the detail's `screenshots` array (position, ascending):
curl -sS -H "Authorization: Bearer $TOKEN" \
  -o ".yikes/screenshots/<id>/001-$(date -u +%Y%m%d-%H%M%S).png" \
  "$HUB/api/v1/notes/<id>/screenshots/1"
```

Write the working copy into `.yikes/` in the exact local-mode layout so the implementation
steps are identical in both modes:

- `.yikes/notes/<YYYYMMDD-HHMMSS>-<last-8-of-id>.md` (timestamp from `captured_at`):
  YAML frontmatter `id, project, title, type, status, created_at` (= `captured_at`),
  `created_by`, `context`, `state_file`, `screenshots` (the relative paths you saved),
  `resolution: null` — then the note `body` below the frontmatter. **`project` is required
  and must be the `project` slug from the API response** (`note.project`) — it is the
  ownership stamp the implement step checks. Never omit it and never hand-edit it to a
  different slug. Note: the hub's wire format uses `on_hold`; the flat-file frontmatter
  convention is `on-hold` (irrelevant for `approved` notes, but normalize if you ever
  materialize one).
- `.yikes/state/<id>.json` — the detail's `state` value, if not null (then set
  `state_file: state/<id>.json`).
- `.yikes/screenshots/<id>/NNN-<YYYYMMDD-HHMMSS>.png` — position `NNN` zero-padded to 3.

**Do NOT git-commit any of these working-copy files** — in hub mode `.yikes/` is not
tracked; the hub is the record. Never `git add .yikes` in hub mode.

#### 3. Implement each approved note

Follow the shared implementation steps (below). The implementing commits contain only code —
no note files.

#### 4. Mark the note done on the hub

After the implementing commit:

```bash
curl -sS -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  "$HUB/api/v1/notes/<id>" \
  -d '{
    "status": "done",
    "resolution": {
      "commit": "<sha of the implementing commit>",
      "note": "<one-line summary of what was done>",
      "completed_at": "<ISO8601 timestamp, e.g. 2026-07-15T15:33:39+00:00>"
    }
  }'
```

`commit`, `note`, and `completed_at` are all required and non-empty; `200` returns the
updated note. A `422` means the note is no longer `approved` (someone re-triaged it) or the
payload is malformed — report it to the user and move on; do not force it. This PATCH is the
ONLY mutation the agent API allows.

Optionally update the working copy's frontmatter to match (status + resolution) so a re-run
sees it as done — but the hub's state is what counts.

---

## Shared implementation steps (both modes)

For each approved note:

0. **Verify the note belongs to THIS project — before anything else.** In hub mode, check the
   frontmatter `project` against `$YIKES_PROJECT` (the slug you pulled with). If it does not
   match — or the `project` field is missing — this note is not yours: **do not read it, do
   not implement it, do not mark it done.** Skip it and add it to a "refused (wrong project)"
   list you surface to the user at wrap-up. This is the guard against a stale, copied, or
   mis-scoped `.yikes/` working copy leaking another project's notes into this codebase; a
   note landing in the folder is never sufficient license to work it. (Local mode has a
   single project — the repo itself — so there is nothing to cross-check; notes there carry
   no `project` field and this step is a no-op.)
1. **Absorb the full context.** Read the note body (the user's actual request) and the
   frontmatter `context` block. Read `.yikes/state/<note-id>.json` if the note's `state_file`
   is set — it shows the client-side state at capture time. **View every screenshot** listed in
   the note's `screenshots` array: they are PNG images — use the Read tool on each file so you
   actually see what the user saw. Screenshots are often the clearest statement of the problem.
2. **Locate the code.** The frontmatter's `context.page` names the page component or area
   (e.g. `account/billing/Index`), `context.route` the route name, and `context.title` the
   document title; on plain server-rendered sites the URL + title are usually the anchor.
   If `context.element` is set, the note is about ONE specific element — its `selector`,
   `tag`, and visible `text` pinpoint it in the rendered page; grep for the text or the
   distinctive classes in the selector to find the template that renders it. Note
   `context.dark_mode` and `context.viewport` — layout bugs frequently only reproduce in
   that mode/size.
3. **Implement** the fix or feature per the note. Follow the project's own conventions
   (CLAUDE.md, design tokens, test patterns).
4. **Run the project's relevant quality gates** for what you touched (lint/format/typecheck/
   tests — whatever the project defines). Fix failures before moving on.
5. **Commit** with a Conventional Commits message that references the note id, e.g.
   `fix(billing): align statement totals on mobile (yikes: 20260711-142530-a1b2c3d4)`.

### Batch notes that touch the same surface

Before starting implementation, scan the approved set for notes that touch the same page or
component (same `context.page`, overlapping components). Implement those together as one
coherent change rather than sequential conflicting edits — one commit may resolve several
notes. Mark each covered note `done` with the shared commit sha and its own resolution summary.

## Hard rules

- **Never delete a note file** — status changes only. This applies to the hub-mode working
  copy too: materialized notes, state files, and screenshots stay on disk untouched until a
  human removes them. Deletion is a human action (index UI in local mode, hub UI in hub mode).
- **Never delete, move, or modify screenshots or state files.** They are the permanent record
  of what was reported.
- Only Claude sets `status: done`, and only with a real implementing commit sha in
  `resolution.commit`. Never flip a note to done without shipped work.
- Do not change any status other than to `done` (triage — approved/on-hold/ignored — belongs
  to the human: index UI in local mode, hub UI in hub mode). In hub mode the API enforces
  this: `approved → done` is the only transition an agent token can make.
- Hub mode: never git-commit `.yikes/` contents; the resolution lives in the hub, not in a
  frontmatter edit.

## Wrap-up

Report what was done: per note — id, what changed, commit sha — plus anything left in the
queue (local mode: remaining `new` notes awaiting triage and `on-hold` count; hub mode: any
notes that 422ed on the done PATCH or otherwise need human attention). If any notes were
**refused for wrong project** (step 0), list them explicitly with their `project` slug — a
foreign note in the working copy means a bad pull or a stale/shared `.yikes/`, and the user
needs to know to clean it up.
