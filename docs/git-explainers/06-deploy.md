# 06 — Deploy: putting work on the live website

**Git never touches the live site.** Pushing to GitHub only updates the
cloud backup. The website obitleague.co.uk changes only when a human
runs:

```
bash deploy-live.sh
```

from the main project folder. The script is deliberately guarded: it
refuses to run unless `main` is fully pushed to GitHub and the tests
pass, so a half-finished state can never reach the live site.

**After deploying**, verify with:

```
bash tests/smoke-live.sh
```

**The whole chain, at a glance:**

```
AI works  →  approval  →  main  →  GitHub (backup)
                                    │
                        (human runs deploy-live.sh)
                                    ▼
                            live website
```

Every arrow is controlled; nothing happens automatically.
