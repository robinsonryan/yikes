---
name: process-yikes
description: Process the Yikes dev/QC note queue. Use when the user asks to "process the yikes notes", "work the yikes queue", "handle the yikes backlog", "run the yikes", "implement the approved yikes notes", or otherwise wants the in-app Yikes feedback notes triaged and the approved ones implemented.
---

# Process Yikes Notes

Yikes notes are dev/QC feedback captured in-app (bug, layout, idea, refactor) and committed to
the repo as flat files. Each note carries the page context it was captured on (URL, route name,
page/component name, document title, user/account, dark/light mode, viewport, optionally the
specific element it is about), an optional app-state snapshot, and zero or more screenshots.
Your job: report the queue, then implement the **approved** notes.

## Locating the notes

The notes directory defaults to `.yikes/` at the project root. The path is configurable —
check `config/yikes.php` (or the package config, `config('yikes.path')`) for a custom path
before assuming the default. Layout:

```
.yikes/
  notes/<id>.md            # one note per file: YAML frontmatter (context) + markdown body
  state/<note-id>.json     # app-state snapshot (may be absent)
  screenshots/<note-id>/   # attached screenshots (PNG)
```

## Procedure

### 1. Read the queue and report

Read every file in `.yikes/notes/*.md`. Group by frontmatter `status` and report counts to the
user, e.g. "6 notes: 2 approved, 3 new, 1 done." List each `new` note (id, type, title/first
line) so the user can triage them — but do not work on them.

### 2. Select work

Work ONLY notes with `status: approved`, unless the user names a specific note — then work
that one regardless of status (confirm first if it is `done` or `ignored`). Skip `on-hold`,
`ignored`, and `done` entirely. If there are no approved notes, say so and stop after the
report.

### 3. Implement each approved note

For each approved note:

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

### 4. Mark the note done

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

### 5. Batch notes that touch the same surface

Before starting implementation, scan the approved set for notes that touch the same page or
component (same `context.page`, overlapping components). Implement those together as one
coherent change rather than sequential conflicting edits — one commit may resolve several
notes. Mark each covered note `done` with the shared commit sha and its own resolution summary.

## Hard rules

- **Never delete a note file** — status changes only. Deletion is a human action via the
  Yikes index UI.
- **Never delete, move, or modify screenshots or state files.** They are the permanent record
  of what was reported.
- Only Claude sets `status: done`, and only with a real implementing commit sha in
  `resolution.commit`. Never flip a note to done without shipped work.
- Do not change any status other than to `done` (triage — approved/on-hold/ignored — belongs
  to the user in the index UI).

## Wrap-up

Report what was done: per note — id, what changed, commit sha — plus anything left in the
queue (remaining `new` notes awaiting triage, `on-hold` count).
