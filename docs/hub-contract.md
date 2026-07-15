# Yikes Hub — API Contract (v1)

Status: **normative** for SP1–SP6. Changes require bumping this doc first.
Companion to `SPEC-HUB.md`. Mirrored into the `yikes-hub` repo at `docs/hub-contract.md`;
the hub copy is authoritative once both exist.

## Conventions

- Base path: `/api/v1`. JSON request/response bodies, UTF-8.
- Auth: `Authorization: Bearer <token>` on every request. Tokens are per-project and carry
  one ability: `ingest` or `agent`. Wrong ability → `403`.
- All ids are UUIDv7 strings (lowercase, hyphenated).
- Timestamps are ISO 8601 with offset (e.g. `2026-07-15T14:16:05+00:00`).
- Enum values: `type` ∈ `bug | layout | idea | refactor`; `status` ∈
  `new | approved | on_hold | ignored | done`.
  (Wire format uses `on_hold`; the flat-file frontmatter historically used `on-hold` —
  clients normalize to underscore form when pushing.)

## Error envelope

Non-2xx responses:

```json
{ "message": "Human-readable summary.", "errors": { "field": ["problem"] } }
```

`errors` present only on `422`. Statuses used: `401` (bad/revoked token), `403` (wrong
ability / wrong project), `404`, `409` (idempotency payload mismatch, see below), `413`
(screenshot too large), `422` (validation / illegal transition), `429` (throttled).

---

## Ingest endpoints (ability: `ingest`)

### POST /api/v1/notes

Push one captured note (the "note bundle"): everything except screenshot binaries.
The token's project determines `project` — there is no project field in the payload;
a token can only ingest into its own project.

Request body:

```json
{
  "id": "019f6622-80bd-70a9-9728-91aaa6766f79",
  "title": "text change",
  "type": "bug",
  "body": "should read 2010 and older vans with Duramax Diesel",
  "created_by": { "name": "Unknown", "email": "" },
  "captured_at": "2026-07-15T14:16:05+00:00",
  "context": { },
  "state": null,
  "screenshot_count": 1
}
```

| Field | Type | Req | Notes |
|---|---|---|---|
| `id` | uuid7 | ✔ | Client-generated at capture. Idempotency key. |
| `title` | string\|null | — | ≤ 200 chars. |
| `type` | enum | ✔ | |
| `body` | string | ✔ | Markdown. May be empty string, not null. |
| `created_by` | object | ✔ | `{name: string, email: string}` — both may be empty strings. |
| `captured_at` | timestamp | ✔ | Client clock. Server stamps `received_at` itself. |
| `context` | object | ✔ | Stored verbatim as JSONB. Free-shape; known keys: `url`, `route`, `page`, `account`, `department`, `dark_mode`, `viewport{width,height}`, `user_agent`, `title`, `element{selector,tag,text}`. |
| `state` | object\|null | — | App-state snapshot, stored verbatim. |
| `screenshot_count` | int ≥ 0 | ✔ | How many screenshot uploads will follow. Lets consumers detect incomplete bundles. |

Notes are created with `status: new`. `status` and `resolution` are NOT accepted on ingest.

Responses:

- `201` — created:
  ```json
  { "id": "…", "status": "new", "received_at": "…" }
  ```
- `200` — idempotent replay: a note with this `id` already exists **and** the stored
  payload hash matches. Body identical in shape to `201`.
- `409` — a note with this `id` exists but the payload differs. Client must not retry;
  this indicates a bug or uuid collision. (Replays after partial failure always send the
  identical payload, so honest clients never see 409.)

### POST /api/v1/notes/{id}/screenshots

Multipart form: `file` (PNG) + `position` (int ≥ 1, order as captured).

- Max 5 MB per file (`413` above). Content type must be `image/png`.
- Idempotent on `(note id, position)`: re-upload to an existing position with identical
  bytes → `200`; differing bytes → `409`.
- `404` if the note doesn't exist or belongs to another project.
- `201` → `{ "note_id": "…", "position": 1 }`

Screenshots may arrive any time after the note (queue flush order is note-first, then
screenshots ascending by position).

---

## Agent endpoints (ability: `agent`)

### GET /api/v1/projects/{slug}/notes

Query params: `status` (default `approved`), `cursor`, `per_page` (default 25, max 100).
`403` if `{slug}` is not the token's project.

```json
{
  "data": [ { …note resource… } ],
  "next_cursor": "…" 
}
```

`next_cursor` is `null` on the last page. Ordering: `received_at` ascending (work the
queue oldest-first).

### GET /api/v1/notes/{id}

Full note resource:

```json
{
  "id": "…",
  "project": "afwd-web",
  "title": "…",
  "type": "bug",
  "status": "approved",
  "body": "…",
  "created_by": { "name": "…", "email": "…" },
  "captured_at": "…",
  "received_at": "…",
  "context": { },
  "state": null,
  "screenshots": [ { "position": 1, "bytes": 48213 } ],
  "resolution": null
}
```

List entries in `GET …/notes` use the same shape minus `state` (fetch detail for it).

### GET /api/v1/notes/{id}/screenshots/{position}

Raw PNG, `Content-Type: image/png`. `404` for unknown position.

### PATCH /api/v1/notes/{id}

The **only** mutation agents may perform: `approved → done`.

```json
{
  "status": "done",
  "resolution": {
    "commit": "b6940b8",
    "note": "Corrected Duramax gear-ratio footnote",
    "completed_at": "2026-07-15T15:33:39+00:00"
  }
}
```

- `resolution.commit` and `resolution.note` required, non-empty. `completed_at` required.
- `422` if the note's current status is not `approved`, or if `status` is anything but
  `"done"`.
- `200` → the updated note resource.

---

## Throttling

Laravel per-token throttle: 60 req/min (`429` + `Retry-After`). Screenshot uploads count.
Cloudflare rate-limit rule sits in front as backstop; clients must treat `429` from either
layer identically (back off, retry queued work later).

## Versioning

Breaking changes bump the path (`/api/v2`). Additive fields may appear without notice —
clients must ignore unknown response fields.
