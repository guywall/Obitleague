# Git workflow rules — Obitleague

Last updated: 2026-10-03

Authority: this file and the explainers in `docs/git-explainers/` are the
guidance for how work moves from an approved change to GitHub.
`START-HERE.md` stays the plain-English overview; where the two disagree,
this file wins and the other is corrected.

## The one rule

Every approved change reaches GitHub as a **new version of `main`**.
A working session never pushes to `main` directly, and approved work is
never left sitting on a session branch. The default flow is:

1. Do the work in the session branch (the Freebuff worktree branch).
2. Show the user what changed and ask for approval.
3. On approval: merge **fast-forward** into local `main`, then push
   `main` to GitHub (`origin`). Delete the session branch afterwards.
4. If the user does not answer within **5 minutes**, treat the change as
   approved **only if** it is exactly what was asked, scope-clean, and
   all checks pass (see "Approval timing" below). Otherwise stop and wait.

One alternative exists: when the user asks to *review the work as a pull
request* rather than merge it, follow "Pull requests" below instead of
step 3. That is the only case in which a session branch is pushed.

## Branch rules

- `main` is the only long-lived branch. It is always safe: it contains
  only approved, pushed work.
- Each Freebuff session works on its own branch (the worktree branch).
  If the auto-generated name is unreadable
  (`freebuff/for-this-project-i-want-you-to-...`), rename it to a short,
  human-readable topic name **before** merging, following the convention
  in `Freebuff-session-branch-naming-note.md`:
  - Format: `<scope>/<short-topic>`, e.g.
    `feat/git-workflow-rules`, `fix/header-cta-duplicate`,
    `docs/agent-rules-update`.
  - Scope prefixes in use: `feat/`, `fix/`, `docs/`, `discovery/`,
    `chore/`. Keep the topic to 3–6 words, lowercase, hyphenated.
  - Never include UUIDs or session noise in a shared branch name.
- Never commit directly on `main` from a session. If `main` has moving
  work (another session), rebase the session branch onto updated `main`
  before merging.

## Commit rules

A good commit message here has two parts, separated by a blank line:

1. **Subject line** — imperative, ≤ 72 chars, no prefix noise like
   `fix:` (this repo's existing style is plain sentences, e.g.
   "Red-flag future death dates, one header CTA, ban mint-on-gold").
2. **Body (optional but encouraged for non-trivial changes)** — one or
   two short paragraphs: *what* changed and *why*, plus any follow-up
   the next reader needs. Wrap at ~72 chars.

Rules:

- One logical change per commit. Never mix an unrelated fix into a
  feature commit; make a second commit instead.
- Never commit: `.env`, secrets, `work/` seed data (git-ignored),
  or another session's untracked files.
- Runs that touch generated files (`assets/*.min.js`) must keep the
  hand-minified twin in sync in the same commit.
- Before committing, run the project checks that apply:
  `php tests/run-tests.php`, `php -l` on changed PHP,
  `node --check` on changed JS, `git diff --check`.

## Merge & push rules

- Merge into `main` with `git merge --ff-only <branch>` when history is
  linear; fall back to a normal merge commit only when a rebase would
  rewrite shared history.
- Commit message on a merge commit (if one is needed):
  `Merge branch '<branch>' — <short human topic>`.
- Push **only `main`**: `git push origin main`. Never push a session
  branch to `origin` unless the user asks to review it on GitHub — that is
  the pull-request path below.
- After a successful push, delete the local session branch
  (`git branch -d <branch>`). If a remote session branch exists, delete
  that too (`git push origin --delete <branch>`).
- Do not force-push. Do not rewrite published history. If a push is
  rejected because `origin/main` moved, pull with `--rebase`, re-run
  checks, and push again.

## Pull requests (when the user asks for review on GitHub)

The default is still direct-to-`main`. A pull request is used only when
the user asks for one — "open a PR", "let me review it on GitHub" — or
says they want a review record for a large change. Do not impose the PR
step on work the user asked to merge.

One-off setup (Windows):

- Install the GitHub CLI per-user (no admin needed):
  `winget install --id GitHub.cli --scope user`.
- Authenticate once: `gh auth login` (browser/device flow). A token given
  to `gh auth login --with-token` must carry `repo`, `read:org` and
  `workflow` scopes.
- A working `git push` does **not** mean `gh` is authenticated. Git
  Credential Manager holds a separate token that `gh` usually refuses
  with `missing required scope 'read:org'`. Always confirm with
  `gh auth status` before relying on `gh`.
- `gh` may not be on `PATH` in the shell that installed it. Restart the
  shell, or call it by full path under
  `%LOCALAPPDATA%\Microsoft\WinGet\Packages\...\bin\gh.exe`.

The flow:

1. Do the work on the session branch and commit as usual.
2. Push the session branch: `git push -u origin <branch>`. This is the one
   case where a session branch is pushed.
3. Open the PR:
   `gh pr create --base main --title "<lead commit subject>" --body "<what and why>"`.
4. Do **not** merge into local `main` while the PR is open — the PR is the
   review record.
5. Once it is approved, merge it on GitHub (or `gh pr merge --squash
   --delete-branch` if the user asks), then `git pull origin main` and
   delete the local session branch.
6. Never force-push a branch under review; add commits instead.

If the user asks for a PR **after** the work is already merged into
`main` — the usual case, because the default flow merges immediately —
there is nothing to open a PR against: a PR needs a branch that diverges
from `main`, and `main` already contains the commits. Say so plainly, and
offer a version tag or a GitHub release instead of inventing an empty
branch.

## Approval timing

The user asked for requests to be allowed but not to block progress:

- Ask once, clearly, showing the diff summary and the intended commit
  message(s).
- If no answer arrives within **5 minutes**, proceed under "assumed
  approval" **only when all of these hold**:
  - the change is exactly the task that was requested (no scope creep);
  - the applicable checks all pass;
  - the change is additive or trivially reversible on `main`.
- If any of those fail, do not push; leave the session branch with a
  written summary and wait.
- Assumed approval is recorded in the session's handoff note (branch
  name, commit hashes, why it was assumed).

## Guidance files are live documents

Any change to the process described here must itself be written back
into this file, `START-HERE.md`, and (if relevant)
`AI_PLUGIN_GUIDE.md`, in the same change that alters the process.
The explainers in `docs/git-explainers/` must be updated whenever the
step they explain changes. When a new explainer file is added, list it in
`docs/git-explainers/README.md` and mention the concept in
`docs/GIT-EXPLAINED.md` too.

## Explanations for the user

Every part of this workflow is explained in plain English in
`docs/git-explainers/` — one short file per concept, written for
someone who is not a git expert. Start with
`docs/git-explainers/00-overview.md` if the whole thing is new.
