# 03 — Merges: moving approved work into main

**What a merge is.** Merging copies the commits from a session branch
into `main`, so the official line now contains the approved work.

**Fast-forward preferred.** When `main` has not moved since the branch
was made, git can simply slide `main` forward to include the new
commits — no extra "merge commit" is created, so history stays a clean
straight line. This is the default here (`git merge --ff-only`).

**Merge commits.** If `main` has moved and rebasing would rewrite
history others already have, a normal merge is made instead. Its message
is `Merge branch '<branch>' — <short human topic>`, so even the merge
points are readable in `git log`.

**After merging.** The session branch has served its purpose and is
deleted (`git branch -d <name>`). The work is not lost — it lives in
`main` now.

**Safety rules:**

- Never force-push; never rewrite history that is already on GitHub.
- If the push is rejected because GitHub's `main` moved ahead, pull with
  `git pull --rebase origin main`, re-run the checks, push again.
