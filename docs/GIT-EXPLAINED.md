# Git explained in plain words (Obitleague)

Last updated: 2026-09-30

This is the simple, non-technical version of `docs/GIT-WORKFLOW.md`.
Read this one if the technical rulebook feels dense.

## The big picture

Think of the project as a photo album of the website.

- **`main`** is the official album. Only finished, approved pictures go in.
  GitHub keeps a copy of the album in the cloud as backup.
- A **branch** is a rough sketchbook. The AI works in a sketchbook so it
  cannot accidentally ruin the official album.
- A **commit** is gluing one finished picture into the sketchbook, with a
  written note of what the picture shows.
- A **push** is photocopying the official album to GitHub so it is safe.
- A **merge** is copying an approved page from the sketchbook into the
  official album.
- A **worktree** is just a second folder on the PC where the AI can use a
  sketchbook without disturbing the main folder.

## The process, step by step

1. **The AI gets a task.** It works in its own branch (sketchbook).
2. **The AI shows you the result.** "Here is what changed. Shall I keep it?"
3. **You say yes or no.** Yes → it goes onto `main` and up to GitHub.
   No → it stays in the sketchbook and can be thrown away.
4. **If you don't answer in 5 minutes**, the AI may go ahead anyway, but
   only if the change is exactly what was asked, all tests pass, and it
   is easy to undo. Otherwise it waits.
5. **The branch is tidied up.** Any ugly auto-generated branch name
   (like `freebuff/for-this-project-i-want-you-to-...`) gets renamed to
   something readable, e.g. `fix/header-cta-duplicate`.
6. **Work is saved onto `main` and pushed to GitHub.** The sketchbook
   branch is then deleted — it is no longer needed.
7. **The live site does not change.** That only happens when someone
   runs `bash deploy-live.sh`, separately.

## The words, one by one

Each of these has its own one-page explainer in `docs/git-explainers/`:

- **Repository ("repo")** — the whole project folder plus its full
  history. One repo: Obitleague.
- **Branch** — see above; the sketchbook.
- **Commit** — one saved snapshot of the work, with a message.
- **Commit message** — the note explaining the snapshot. First line is a
  short summary; more detail can follow underneath.
- **Push** — copy new commits up to GitHub (the cloud backup).
- **Pull** — copy new commits down from GitHub (e.g. to the other PC).
- **Merge** — combine one branch's work into another (usually into
  `main`).
- **Fast-forward merge** — the simplest kind of merge: `main` simply
  slides forward to include the new commits, with no extra "merge
  commit" clutter.
- **Origin** — the nickname for the GitHub copy of the project.
- **Worktree** — the extra folder Freebuff uses for a session.
- **Deploy** — putting the code onto the live website
  (`bash deploy-live.sh`). Separate from git entirely.

## What you need to remember

- If it's not on `main` (and pushed), it isn't saved. `git status`
  should say "working tree clean" when everything is safe.
- The live site only changes with `bash deploy-live.sh`.
- Messy pile of old `freebuff/...` branches or folders? Leftover clutter,
  not missing work.
