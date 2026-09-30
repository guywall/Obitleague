# 00 — Overview: how a change reaches GitHub

Plain-English map of the whole journey. Companion files explain each step
in depth.

```
 task arrives
      │
      ▼
 [1] work on a session branch          → 01-branches.md
      │
      ▼
 [2] save work as commits              → 02-commits.md
      │
      ▼
 [3] show the user, ask approval       → 05-approval.md
      │
      ▼
 [4] merge into main (fast-forward)    → 03-merges.md
      │
      ▼
 [5] push main to GitHub               → 04-push-and-origin.md
      │
      ▼
 [6] delete the session branch
      │
      ▼
 (later, by a human) deploy-live.sh    → 06-deploy.md
```

Two guarantees hold throughout:

- `main` never receives unapproved or untested work.
- Nothing here touches the live website. Deployment is a separate,
  deliberate human action.
