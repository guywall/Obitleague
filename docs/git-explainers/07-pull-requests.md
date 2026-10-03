# 07 — Pull requests: asking GitHub to review work

**What a pull request ("PR") is.** A pull request is a *proposal* on
GitHub: "here is a branch with some work — please look at it, then
accept it into `main`." GitHub shows the changes side by side, lets
someone comment on them, and only merges when it is approved. Think of
it as handing a page from the sketchbook to a colleague for a signature
before it goes into the official album.

**When we use one.** Normally we do **not**. The default here is: the AI
merges approved work straight into `main` and pushes it (see
`03-merges.md` and `04-push-and-origin.md`). A PR is used only when you
ask for one — "open a PR" or "let me review it on GitHub first". Then the
work waits on GitHub until it is accepted instead of going straight in.

**Why it needs a tool.** Opening a PR is a GitHub operation, not a git
one, so it uses GitHub's own command-line tool, `gh` (the "GitHub CLI").
That is a one-off install, and it needs to be *logged in* to your GitHub
account the first time.

- Install (Windows, no administrator needed):
  `winget install --id GitHub.cli --scope user`
- Log in once: `gh auth login` — it opens your browser and asks you to
  approve. Afterwards `gh auth status` should name your account.

**A trap worth knowing.** Your PC can already push to GitHub without
`gh`, so it is tempting to assume `gh` is logged in too. It is not — the
saved password for `git push` is a *different* credential, and `gh`
refuses it with `missing required scope 'read:org'`. Always check
`gh auth status` before expecting a PR to work. Also, the shell that
installed `gh` may need restarting before it can find the `gh` command.

**The steps, in plain words:**

1. The AI does the work on its session branch, as usual.
2. Instead of merging, it pushes that branch up to GitHub. (This is the
   one time a branch other than `main` is sent to GitHub.)
3. It opens the PR: `gh pr create --base main ...`.
4. **Nothing is merged into `main` while the PR is open.** The PR is the
   record of the review.
5. When you accept it, the PR is merged on GitHub, and the local copy is
   brought up to date with `git pull origin main`.

**If the work is already merged.** Asking for a PR after the work has
already reached `main` cannot produce a useful one: a PR needs a branch
that is *different* from `main`, and `main` already contains those
commits, so the PR would be empty. In that case the right gesture is a
version tag or a GitHub release, not a PR.
