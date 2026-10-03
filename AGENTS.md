# AGENTS.md — read this before doing anything

This file binds every AI coding agent (Freebuff or otherwise) working in
this repository, regardless of which folder or tool the session starts
from. It exists so the project's directives are followed from anywhere.

## Order of authority

1. `START-HERE.md` — the plain-English model of how this repo's git
   works (`main` is the only branch that matters).
2. `AI_PLUGIN_GUIDE.md` — the task handoff map for code work.
3. `docs/GIT-WORKFLOW.md` — **the delivery rules.** How approved work
   is named, committed, merged, and pushed. These apply to every
   session, every time.
4. `docs/git-explainers/` — plain-English explanation of every git
   concept the rulebook uses.

## Non-negotiables (summary — full detail in docs/GIT-WORKFLOW.md)

- Work on a session branch; never commit directly to `main`.
- Rename auto-generated branch names to readable
  `<scope>/<short-topic>` form before sharing or merging
  (e.g. `feat/git-workflow-rules`, `fix/header-cta-duplicate`).
- One logical change per commit; plain-sentence imperative subject
  ≤ 72 chars, no `fix:`-style prefixes; body explains what and why.
- Run the applicable checks before committing:
  `php tests/run-tests.php`, `php -l` on changed PHP,
  `node --check` on changed JS, `git diff --check`.
- Merge with `git merge --ff-only`, push **only** `main`
  (`git push origin main`), never force-push. The one exception is a
  pull request: when the user asks to review the work on GitHub, push
  the session branch and open a PR with `gh` instead of merging, and
  leave `main` alone until the PR is accepted
  (see `docs/git-explainers/07-pull-requests.md`).
- Ask the user for approval before merging/pushing; if no answer in
  **5 minutes**, proceed only when the change is exactly as asked, all
  checks pass, and it is trivially reversible — and record the assumed
  approval in the session handoff note. Otherwise wait.
- Delete the session branch after a successful push.
- Keep the guidance live: any change to this process updates
  `docs/GIT-WORKFLOW.md`, `START-HERE.md`, `AI_PLUGIN_GUIDE.md`, and
  the matching explainer file in the same change — and, when a new
  concept is added, its entry in `docs/git-explainers/README.md` and in
  `docs/GIT-EXPLAINED.md` too.
- The live site changes only when a human runs `bash deploy-live.sh`.
  Never deploy, and never run the data-changing scripts in `tests/`,
  without explicit authorization.
