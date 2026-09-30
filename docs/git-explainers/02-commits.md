# 02 — Commits: saved snapshots with readable notes

**What a commit is.** A commit is one saved snapshot of the project, like
gluing a finished page into the sketchbook and writing a caption on it.
Every commit gets a unique fingerprint (called a hash, e.g. `45a256b`)
so history can always be traced.

**Message format used in this project:**

```
Short summary line (imperative, under 72 characters)

Optional body: what changed, why, and anything the next
reader needs to know. Wrapped at about 72 characters.
```

Example from this repo's real history:

```
Red-flag future death dates, one header CTA, ban mint-on-gold
```

Notice: a plain sentence, no `fix:`/`feat:` prefix clutter. The subject
says what the change *does*.

**Rules the AI follows:**

- One logical change per commit; unrelated fixes go in their own commit.
- Never commit secrets, `.env` files, or `work/` seed data.
- If `assets/header.js` changes, its hand-minified twin
  `assets/header.min.js` must change in the same commit.
- Checks run before committing: `php tests/run-tests.php`,
  `php -l` on changed PHP files, `node --check` on changed JS,
  `git diff --check` (catches stray whitespace errors).
