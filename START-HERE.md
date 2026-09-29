# START HERE — how this project's Git works (read this first)

Last updated: 2026-09-29

## The one rule

Everything that matters lives in the branch called **`main`**. That is now the
**only branch** — on your PC and on GitHub. If it is not on `main`, it does not
exist. If it IS on `main`, it is saved forever.

## What is what

- **Your project folder** `C:\Users\guy\Documents\Obitz\Obitleague` — this IS
  the website code, sitting on `main`. Deploys to obitleague.co.uk run from
  here (`bash deploy-live.sh`).
- **GitHub** (`github.com/guywall/Obitleague`) — the cloud backup. It holds
  exactly one branch: `main`.
- **`.freebuff/worktrees/...` folders** — disposable sandboxes the AI
  assistant (Freebuff) creates for itself while working. When a session
  finishes, its work is merged into `main` and the sandbox is deleted. A pile
  of them is leftover clutter, NOT missing work.

## If you ever feel lost, do exactly this

1. Open a terminal in the project folder.
2. `git status` — if it says "nothing to commit, working tree clean", all is well.
3. `git log --oneline -5` — the top line is the newest piece of work.
4. If your other PC looks different, run `git pull origin main` there.
   Both PCs now match. That's the whole trick.

## The only 4 commands you need day-to-day

| I want to...            | Command                                                        |
| ----------------------- | -------------------------------------------------------------- |
| Get latest work         | `git pull origin main`                                          |
| See what's going on     | `git status`                                                    |
| Save my edit            | `git add -A` then `git commit -m "what I did"` then `git push origin main` |
| Put it on the live site | `bash deploy-live.sh` (refuses to run unless main is pushed and tests pass) |

## Words that were confusing you

- **branch** — a parallel copy of the code. We keep exactly one: `main`.
- **worktree** — a second folder sharing the same history; Freebuff makes
  these for its own work sessions and throws them away after.
- **origin** — the GitHub cloud copy. `origin/main` = what's on GitHub.
- **merge** — combine one line of work into `main`.
- **unmerged changes** — work sitting somewhere that isn't in `main` yet.
  As of today there are **none**: every branch was verified contained in
  `main`, then deleted.

## Current state (2026-09-29)

- `main` contains ALL work through plugin version 0.12.1 — every feature
  branch was merged in and then removed.
- The live site obitleague.co.uk only changes when you run `deploy-live.sh`.
- The four live-site issues (duplicate header, stray `?>` output, missing
  `header.min.js`, `/people/` 404) are fixed on `main` as of 2026-09-29.
  If obitleague.co.uk still shows any of them, the site just needs a
  deploy: run `bash deploy-live.sh`, then `bash tests/smoke-live.sh`.
