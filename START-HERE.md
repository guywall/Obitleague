 START HERE — how this project's Git works (read this first)

Last updated: 2026-10-03

# The one rule

Everything that matters lives in the branch called **`main`**. That is now the
**only branch** — on your PC and on GitHub. If it is not on `main`, it does not
exist. If it IS on `main`, it is saved forever.

# What is what

- **Your project folder** `C:\Users\guy\Documents\Obitz\Obitleague` — this IS
  the website code, sitting on `main`. Deploys to obitleague.co.uk run from
  here (`bash deploy-live.sh`).
- **GitHub** (`github.com/guywall/Obitleague`) — the cloud backup. It holds
  exactly one branch: `main`.
- **`.freebuff/worktrees/...` folders** — disposable sandboxes the AI
  assistant (Freebuff) creates for itself while working. When a session
  finishes, its work is merged into `main` and the sandbox is deleted. A pile
  of them is leftover clutter, NOT missing work.

# Start here on a new thread

**AI sessions:** read `AGENTS.md` first — it binds every agent working
from any folder and points to the rules in order. Then read
`AI_PLUGIN_GUIDE.md` and `docs/GIT-WORKFLOW.md` — that rulebook governs
how approved work is named, committed, merged into `main`, and pushed
to GitHub (including the 5-minute approval rule). Plain-English
versions: `docs/GIT-EXPLAINED.md` and `docs/git-explainers/`.

# Start here if you feel lost

1. Open a terminal in the project folder.
2. `git status` — if it says "nothing to commit, working tree clean", all is well.
3. `git log --oneline -5` — the top line is the newest piece of work.
4. If your other PC looks different, run `git pull origin main` there.
   Both PCs now match. That's the whole trick.

# The only 4 commands you need day-to-day

| I want to...            | Command                                                        |
| ----------------------- | -------------------------------------------------------------- |
| Get latest work         | `git pull origin main`                                          |
| See what's going on     | `git status`                                                    |
| Save my edit            | `git add -A` then `git commit -m "what I did"` then `git push origin main` |
| Put it on the live site | `bash deploy-live.sh` (refuses to run unless main is pushed and tests pass) |

# If you want to review work before it lands (pull requests)

The normal flow merges approved work straight into `main`. If you would
rather look at a change on GitHub first, say "open a PR" and the AI will
push the session branch and open a **pull request** instead of merging.
That needs GitHub's own tool, `gh`, installed once
(`winget install --id GitHub.cli --scope user`) and logged in once
(`gh auth login`). Plain-English guide:
`docs/git-explainers/07-pull-requests.md`.

# Words that were confusing you

- **branch** — a parallel copy of the code. We keep exactly one: `main`.
- **worktree** — a second folder sharing the same history; Freebuff makes
  these for its own work sessions and throws them away after.
- **origin** — the GitHub cloud copy. `origin/main` = what's on GitHub.
- **merge** — combine one line of work into `main`.
- **pull request (PR)** — a GitHub proposal to review a branch *before*
  it is merged. Optional; only when you ask for it.
- **unmerged changes** — work sitting somewhere that isn't in `main` yet.
  As of today there are **none**: every branch was verified contained in
  `main`, then deleted.

# Current state (2026-10-04)

- `main` contains ALL work through plugin version 0.15.5 — every feature
  branch was merged in and then removed.
- The live site obitleague.co.uk is running 0.15.5 (deployed 2026-10-04),
  and the stored person pages were recomposed so the current prose shows.
- The live site only changes when you run `deploy-live.sh`.
- The GitHub CLI (`gh`) is installed per-user and logged in as `guywall`
  (2026-10-03), so pull requests work. Its token lacks `read:org`, so
  commands that need org membership warn; run `gh auth refresh -h
  github.com` (one browser click) if that ever matters.
