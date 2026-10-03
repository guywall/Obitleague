PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS people (
    uuid TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    birth_date TEXT NOT NULL,
    death_date TEXT,
    eligibility TEXT NOT NULL CHECK (eligibility IN ('candidate', 'approved', 'rejected')),
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS people_public ON people (eligibility, death_date, birth_date, name);

CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS web_sessions (
    token_hash TEXT PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    csrf_token TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS web_sessions_expiry ON web_sessions (expires_at);

CREATE TABLE IF NOT EXISTS leagues (
    id INTEGER PRIMARY KEY,
    season INTEGER NOT NULL,
    kind TEXT NOT NULL CHECK (kind = 'main'),
    ruleset_version TEXT NOT NULL,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (season, kind)
);

CREATE TABLE IF NOT EXISTS league_members (
    league_id INTEGER NOT NULL REFERENCES leagues(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('member', 'spectator')),
    joined_at TEXT NOT NULL,
    PRIMARY KEY (league_id, account_id)
);

CREATE TABLE IF NOT EXISTS entries (
    id INTEGER PRIMARY KEY,
    league_id INTEGER NOT NULL REFERENCES leagues(id),
    account_id INTEGER NOT NULL REFERENCES accounts(id),
    state TEXT NOT NULL CHECK (state IN ('draft', 'submitted')),
    ruleset_version TEXT NOT NULL,
    season INTEGER NOT NULL,
    expected_version INTEGER NOT NULL DEFAULT 1,
    draft_revision_id INTEGER,
    submitted_revision_id INTEGER,
    updated_at TEXT NOT NULL,
    UNIQUE (league_id, account_id),
    FOREIGN KEY (draft_revision_id) REFERENCES entry_revisions(id),
    FOREIGN KEY (submitted_revision_id) REFERENCES entry_revisions(id),
    CHECK ((state = 'draft' AND submitted_revision_id IS NULL) OR (state = 'submitted' AND submitted_revision_id IS NOT NULL)),
    CHECK (draft_revision_id IS NULL OR submitted_revision_id IS NULL)
);

CREATE TABLE IF NOT EXISTS entry_revisions (
    id INTEGER PRIMARY KEY,
    entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
    kind TEXT NOT NULL CHECK (kind IN ('draft', 'submitted', 'superseded')),
    ruleset_version TEXT NOT NULL,
    idempotency_key TEXT,
    submitted_at TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (entry_id, idempotency_key)
);
CREATE UNIQUE INDEX IF NOT EXISTS one_current_submission_per_entry
    ON entry_revisions (entry_id) WHERE kind = 'submitted';

CREATE TABLE IF NOT EXISTS entry_picks (
    revision_id INTEGER NOT NULL REFERENCES entry_revisions(id) ON DELETE CASCADE,
    slot INTEGER NOT NULL CHECK (slot BETWEEN 1 AND 10),
    person_uuid TEXT NOT NULL REFERENCES people(uuid),
    PRIMARY KEY (revision_id, slot),
    UNIQUE (revision_id, person_uuid)
);

-- Reported deaths awaiting editorial review, then approved, retracted, or
-- corrected. One row per person; a correction bumps the revision so the award
-- ledger can supersede the earlier one. Cause is an editorial decision separate
-- from the date and never affects points.
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY,
    person_uuid TEXT NOT NULL UNIQUE REFERENCES people(uuid),
    death_date TEXT NOT NULL,
    cause_status TEXT NOT NULL CHECK (cause_status IN ('not_disclosed', 'pending_official', 'confirmed', 'contested')),
    cause TEXT,
    status TEXT NOT NULL CHECK (status IN ('reported', 'approved', 'retracted')),
    revision INTEGER NOT NULL DEFAULT 1,
    reporter_account_id INTEGER REFERENCES accounts(id),
    reviewed_by INTEGER REFERENCES accounts(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS events_status ON events (status, created_at);

-- Append-only signed award ledger, one line per (team, pick, event revision).
-- A confirmation is a positive delta; a correction or withdrawal is a negative
-- delta, never an in-place edit. Awards are attributed per team so an amendment
-- keeps the teams and picks it does not change. Net deltas give the live award.
CREATE TABLE IF NOT EXISTS awards (
    id INTEGER PRIMARY KEY,
    operation_key TEXT NOT NULL UNIQUE,
    entry_id INTEGER NOT NULL REFERENCES entries(id),
    person_uuid TEXT NOT NULL REFERENCES people(uuid),
    season INTEGER NOT NULL,
    ruleset_version TEXT NOT NULL,
    event_revision TEXT NOT NULL,
    completed_age INTEGER NOT NULL,
    points_delta INTEGER NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS awards_entry_season ON awards (entry_id, season);
CREATE INDEX IF NOT EXISTS awards_person_season ON awards (person_uuid, season);
