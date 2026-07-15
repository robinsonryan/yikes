# Yikes Hub — Specification

Status: **draft for review** — no implementation started.
Date: 2026-07-15
Supersedes: the "Staging sync (`scripts/yikes-pull.sh`)" section of `SPEC.md`. Everything else
in `SPEC.md` (capture UX, note format, FAB behavior) remains accurate for the capture side.

## 1. Problem / Goal

Yikes notes are currently per-app flat files. Remote stores (staging Docker volumes) are
pulled to a dev machine over SSH — a per-repo `yikes-pull.sh` on NBSS, done by hand on
afwd-web. This doesn't scale across projects, requires SSH + container-name archaeology from
every consumer, and splits local vs remote notes into different worlds.

**Goal:** one central hub. Apps **push notes on capture** over HTTPS (local DDEV and remote
staging alike — no SSH, no docker exec, no per-repo scripts). The hub provides:

1. A per-project triage UI for humans (view, approve, hold, ignore, delete).
2. An authenticated API that receives notes from apps and exposes approved notes to
   Claude Code (which implements them and marks them done).

**Canonicality shift (the big semantic change):** today the app repo's committed `.yikes/`
directory is the system of record and status lives in git-tracked frontmatter. With the hub,
**the hub is the system of record for notes and status.** The app-local `.yikes/` store
becomes (a) the offline retry queue for capture, and (b) a materialized working copy when
Claude Code processes notes. Pulled copies are no longer committed to app repos.

## 2. Architecture

Two artifacts, separate repos:

| Artifact | What | Repo |
|---|---|---|
| Capture package | `robinsonryan/yikes` composer package: FAB UI, context/screenshot capture, push client + offline queue. Dual-mode (see §5). | existing `robinsonryan/yikes` |
| Hub | Standalone Laravel app: Postgres storage, triage UI, ingest + agent APIs. Deployed as a Dokploy app on gerald. | new `yikes-hub` (scaffolded via `harness new-project`) |

The contract between them is the **note bundle JSON schema + API contract** (SP0, §9). The
hub is not a package; apps never install hub code.

- Domain: `yikes.4x4van.com` (owner-managed; acceptable long-term, revisit only if the hub
  outgrows personal infrastructure).
- Network path: Cloudflare Tunnel (`cloudflared` sidecar in the hub's Dokploy compose) — the
  hub publishes **no ports** on gerald. Cloudflare Access gates the UI; bearer tokens gate
  `/api/*` (§7).

## 3. Data model (hub, Postgres)

Follows the standard Postgres + UUID7 convention. JSONB for captured blobs — the hub stores
context/state verbatim, it does not normalize them.

```
projects
  id            uuid (uuid7, pk)
  slug          text unique          -- e.g. 'afwd-web', 'nbss'
  name          text
  created_at / updated_at

project_tokens
  id            uuid (uuid7, pk)
  project_id    fk -> projects
  name          text                 -- 'staging ingest', 'ryan agent', ...
  token_hash    text                 -- sha256; plaintext shown once at creation
  ability       enum: ingest | agent
  last_used_at  timestamptz null
  revoked_at    timestamptz null
  created_at

notes
  id            uuid (pk)            -- CLIENT-generated uuid7 at capture time (see §4 idempotency)
  project_id    fk -> projects
  type          enum: bug | layout | idea | refactor
  status        enum: new | approved | on_hold | ignored | done
  title         text
  body          text                 -- markdown; immutable after capture (v1)
  context       jsonb                -- url, route, page, element, viewport, dark_mode, user_agent, title, account...
  state         jsonb null           -- app-state snapshot
  created_by    jsonb                -- {name, email} as captured
  captured_at   timestamptz          -- client clock
  received_at   timestamptz          -- server clock
  resolution    jsonb null           -- {commit, note, completed_at} — set only on done
  created_at / updated_at

screenshots
  id            uuid (uuid7, pk)
  note_id       fk -> notes
  position      int                  -- display order
  path          text                 -- on-disk path under the screenshots volume
  bytes         int
  created_at
```

Screenshot **files** live on a Docker volume (`/storage/screenshots/<note-id>/…`), metadata
in Postgres. No file content in the DB.

### Status lifecycle

```
new ──(human, UI)──> approved ──(agent, API)──> done
 │──(human, UI)──> on_hold / ignored
```

- Humans transition status in the hub UI only. Delete is human-only, UI-only.
- The agent API can perform exactly one transition: `approved → done`, and must supply a
  `resolution` payload. The hub rejects any other agent transition.
- `done` requires `resolution.commit` — same rule as today's skill.

## 4. API surface

All under `/api/v1`, bearer token auth, JSON. Token ability scopes each group.

### Ingest (ability: `ingest` — held by app servers)

- `POST /api/v1/notes` — the note bundle (frontmatter fields + body + optional state).
  **Idempotent on note `id`**: the client generates a uuid7 at capture; replaying the same
  bundle (offline-queue flush, retries) is a no-op returning the existing record.
- `POST /api/v1/notes/{id}/screenshots` — multipart PNG upload, `position` field. Idempotent
  on (note_id, position).

### Agent (ability: `agent` — held by Claude Code / dev machines)

- `GET  /api/v1/projects/{slug}/notes?status=approved` — cursor-paginated list.
- `GET  /api/v1/notes/{id}` — full note incl. context/state.
- `GET  /api/v1/notes/{id}/screenshots/{position}` — PNG download.
- `PATCH /api/v1/notes/{id}` — body `{status: "done", resolution: {commit, note, completed_at}}`.
  Only valid from `approved`. Anything else → 422.

### Errors / limits

- Standard Laravel throttle on `/api/*` (per-token), plus a Cloudflare rate-limit rule (§7).
- Screenshot size cap: 5 MB per file (client compresses before upload; hub rejects over-cap
  with 413).

## 5. Capture package v2 (dual-mode)

Config additions in `config/yikes.php` / env:

```
YIKES_HUB_URL=      # empty → local mode (current behavior, untouched)
YIKES_HUB_TOKEN=    # ingest token for this project
YIKES_PROJECT=      # project slug on the hub
```

**Local mode (no hub URL):** exactly today's behavior — flat-file store, local index UI.
Existing installs keep working with zero changes.

**Hub mode:**

- On capture: write the bundle to the local `.yikes/` queue first (capture must never fail
  because the hub is down), then attempt a synchronous push with a short timeout. On success,
  mark the queue entry pushed; on failure, leave it queued.
- Queue flush: retried on next capture, via `php artisan yikes:flush`, and via the scheduler
  if the app runs one. No queue-worker dependency — Statamic sites often don't run workers.
- Note ids are client-generated uuid7 (already the case) → pushes are idempotent replays.
- The local index/triage UI is **disabled** in hub mode — the hub owns triage. The FAB
  remains.
- Checklists code is untouched by this work (see §10).

## 6. Hub UI (triage)

Behind Cloudflare Access; no app-level login in v1 (Access identity is trusted; hub reads the
`Cf-Access-Authenticated-User-Email` header for audit/attribution).

- Project list → per-project note queue: filter by status/type, sorted newest-first.
- Note detail: body, full context block, screenshots, state viewer (pretty-printed JSON).
- Actions: approve / hold / ignore / re-open, delete (with confirm). No body editing in v1.
- Token management: create/revoke project tokens, plaintext shown once.
- Stack: Blade + Alpine, Tailwind v4 with semantic tokens, light+dark from day one, mobile-
  first (testers will file notes from odd viewports; the hub should not itself be a layout
  bug). No SPA framework — the hub is a CRUD queue.

## 7. Security layers (outermost first)

1. **Cloudflare Tunnel** — `cloudflared` sidecar in the Dokploy compose; DNS for
   `yikes.4x4van.com` routes through the tunnel. Zero published ports on gerald; no origin IP
   to discover.
2. **Cloudflare Access** — Zero Trust application covering everything **except** `/api/*`.
   Email OTP / Google SSO, allowlist of team emails. Unauthenticated UI requests die at the
   edge.
3. **API bearer tokens** — hashed at rest, per-project, per-ability, revocable, `last_used_at`
   tracked. Laravel throttle per token.
4. **Cloudflare rate-limit rule** on `/api/*` as the backstop for a leaked token.
5. Backups: nightly `pg_dump` + screenshots volume snapshot (mechanism per gerald's existing
   backup convention — confirm during SP5).

## 8. Claude Code integration (process-yikes skill v2)

The `process-yikes` skill (ships in the package, symlinked into app repos) becomes
mode-aware:

- **Local mode:** current behavior, unchanged.
- **Hub mode:** fetch approved notes for `YIKES_PROJECT` from the agent API, materialize each
  (note + state + screenshots) into a scratch `.yikes/` working copy, implement per the
  existing procedure, then `PATCH … status=done` with the implementing commit sha. No git
  commits of note files; the resolution lives in the hub.
- Agent token source: `YIKES_HUB_TOKEN` scoped `agent` in the dev machine's project `.env`
  (distinct from the server's `ingest` token).

## 9. Sub-projects & parallelization

```
SP0 (contract)
 ├─→ SP1 (hub core) ──→ SP2 (agent API)
 │        └──────────→ SP3 (hub UI)
 ├─→ SP4 (capture package v2)
 ├─→ SP6 (skill v2)          [testable once SP2 is up]
SP5 (infra)                  [fully parallel from day one]
SP7 (migration & rollout)    [last; needs SP1–SP6]
```

| # | Sub-project | Contents | Depends on |
|---|---|---|---|
| SP0 | **Contract** | Note-bundle JSON schema + API contract doc (`docs/hub-contract.md` here, mirrored into the hub repo when it exists). Small — hours, not days. | — |
| SP1 | **Hub core** | Scaffold via `harness new-project` (workspace `sites`, stack laravel — flagged in §11), migrations/models per §3, ingest endpoints, token auth + hashing, idempotency, throttle. | SP0 |
| SP2 | **Hub agent API** | List/detail/screenshot endpoints, `done` transition with resolution validation. | SP1 |
| SP3 | **Hub triage UI** | §6 in full, incl. token management screens. | SP1 (parallel with SP2) |
| SP4 | **Capture package v2** | Dual-mode config, push client, offline queue + `yikes:flush`, disable local index in hub mode, client-side screenshot compression. Testable against a stub server from the SP0 contract alone. | SP0 |
| SP5 | **Infra** | Dokploy app + Postgres + screenshots volume on gerald, cloudflared tunnel, DNS, Cloudflare Access app + `/api/*` bypass, rate-limit rule, backup wiring. | — (parallel; needs SP1 only to deploy something real) |
| SP6 | **Skill v2** | Mode-aware `process-yikes` per §8. | SP0 (contract); integration-test against SP2 |
| SP7 | **Migration & rollout** | Import existing stores (afwd-web `.yikes/` incl. staging volume, NBSS) via a one-shot import command; create projects + issue tokens; set env on both apps; remove committed `.yikes/` note copies from app repos; retire NBSS `yikes-pull.sh`. | all |

Maximum parallel width after SP0: **SP1, SP4, SP5, SP6** simultaneously; SP2 + SP3 fork off
SP1 in parallel.

## 10. Out of scope (v1)

- **Checklists**: excluded from the hub entirely. Decision on record: checklists shouldn't
  have landed in yikes and will be **extracted into a separate package** later. Until then
  they keep working app-locally; nothing in this work touches them.
- Note body/title editing, comments, assignment, notifications, webhooks out.
- Public/prod feature-request capture (still deferred, per original spec).
- Multi-org auth — Cloudflare Access allowlist + tokens is the whole story.
- Migrating the domain off `yikes.4x4van.com`.

## 11. Edge cases & failure modes

- **Hub down at capture** → local queue, flush later (§5). Capture UX never blocks on the hub.
- **Duplicate delivery** (flush retries, double-submit) → idempotent by client uuid7; no dupes.
- **Token leak** → revoke in UI; CF rate limit bounds the blast radius meanwhile.
- **Agent PATCH on a non-approved note** → 422; skill reports, human re-triages.
- **Screenshot over cap** → client compresses first; hub 413s as backstop.
- **Clock skew** → `captured_at` (client) and `received_at` (server) both stored; UI sorts by
  `received_at`.
- **Same project, local + staging both pushing** → fine by design; `context.url` and token
  name distinguish origin. (Optional: record which token ingested each note for provenance.)
- **Hub redeploy mid-flush** → client retries queued entries; idempotency absorbs replays.

## 12. Open questions (decide before or during build)

1. **Postgres instance**: reuse the existing shared Postgres on gerald vs a dedicated
   Dokploy-managed instance for the hub. (Recommend dedicated: the hub's backup/restore story
   stays self-contained.)- answer: reuse dedicated instance.
2. **Harness workspace** for `yikes-hub`: `sites` (Laravel convention) vs `services` (it's
   infrastructure, not a client site). Protocol says stack decides → `sites`, but flag it. answer: this is a tough one. My lean is sites. It is actually a site where we manage yikes. But, the distinction could go either way. - let's just use sites.
3. **Provenance column**: store `ingested_by_token_id` on notes? Cheap now, awkward later —
   recommend yes. Answer: yes
4. **Access ↔ API interplay**: screenshots fetched by the *UI* go through Access; by the
   *agent* through token auth. Same endpoint with dual guard, or separate UI routes?
   (Recommend separate web routes for the UI; keep `/api/*` token-only.) answer: separate routes. They are absolutely distinct actions.
5. **Retention**: do `done`/`ignored` notes ever get purged, or live forever? (Recommend:
   forever until it hurts; revisit with data.) answer: Keep till it hurts. It will be a long time before we need to purge. We'll write the code for that when we need it.
