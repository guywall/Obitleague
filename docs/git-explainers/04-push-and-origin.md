# 04 — Push and origin: the cloud backup

**What "origin" is.** `origin` is simply the nickname git uses for the
project's home on GitHub: `github.com/guywall/Obitleague`. Think of it
as the cloud copy of the photo album.

**What a push is.** Pushing copies commits from the PC up to GitHub.
It is a backup and a sharing step — it does **not** put anything on the
live website (that is deploy, see `06-deploy.md`).

**What a pull is.** Pulling copies commits from GitHub down to the PC.
If the other PC looks out of date: `git pull origin main` there, and
both PCs match.

**Rules here:**

- Only `main` is pushed: `git push origin main`.
- Session branches stay local unless the user asks to review one on
  GitHub (see `07-pull-requests.md`); any remote session branch is deleted
  after its merge.
- Every push to `main` happens only after user approval (or valid
  assumed approval — see `05-approval.md`) and after checks pass.
- No force-pushes. GitHub's copy of `main` is append-only history.
