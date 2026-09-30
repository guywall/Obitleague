# 01 — Branches: the sketchbooks

**What a branch is.** A branch is a parallel copy of the project where
work can happen without touching `main`. In this project `main` is the
only official line; every session of AI work happens on its own branch,
like a disposable sketchbook.

**Naming.** Freebuff generates branch names automatically and they are
ugly (a long sentence plus a UUID). Before work is shared or merged, the
branch is renamed to a human-readable name:

- Format `<scope>/<short-topic>` — e.g. `feat/git-workflow-rules`,
  `fix/header-cta-duplicate`, `docs/agent-rules-update`.
- Scopes: `feat/` (new feature), `fix/` (bug fix), `docs/`
  (documentation), `chore/` (housekeeping), `discovery/`
  (investigation work).
- 3–6 words, lowercase, hyphens, no UUIDs.

This follows the convention recorded in
`Freebuff-session-branch-naming-note.md`.

**Lifetime.** A branch lives only as long as its task. Once its work is
merged into `main` and pushed, the branch is deleted. A pile of old
`freebuff/...` branches is clutter, not lost work — everything already
in `main` is safe.
