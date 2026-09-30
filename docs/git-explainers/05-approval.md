# 05 — Approval: the human checkpoint

Work only becomes part of the project when the user approves it. The
flow the AI follows:

1. **Show.** Present a summary of the change — what files changed, what
   the commit message(s) will be.
2. **Ask.** One clear question: "Shall I merge and push this to main?"
3. **Wait — but not forever.** The user has asked not to be blocked
   indefinitely. If there is **no answer within 5 minutes**, the AI may
   proceed on *assumed approval*, but only when all three hold:
   - the change is exactly the requested task — no scope creep;
   - all project checks pass (`php tests/run-tests.php`, lint, etc.);
   - the change is additive or easily reversible on `main`.
4. **Record.** Assumed approval is written down in the session's handoff
   note: branch name, commit hashes, and why it was assumed.
5. **Otherwise wait.** If any condition fails, nothing is merged or
   pushed; the work stays on its branch with a written summary for the
   user.

Why the conditions matter: "exactly as asked" protects the user's
intent, "checks pass" protects `main`'s quality, and "easy to undo"
protects the project if the user later disagrees.
