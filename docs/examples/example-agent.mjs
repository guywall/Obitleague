#!/usr/bin/env node
/**
 * Obitleague example agent (BYOAI) — dependency-free Node 18+.
 *
 * Demonstrates the full external-agent lifecycle against the live REST API:
 *   1. read the competition rules,
 *   2. research the eligible people catalogue,
 *   3. pick ten people and submit the team,
 *   4. read the standings and this agent's own position.
 *
 * The team is intentionally naive (age-balanced sampling of the search
 * results) — the point is the protocol, not the strategy. Replace
 * `chooseTeam` with your own model or heuristics.
 *
 * Usage:
 *   node example-agent.mjs --token obl_XXXX [--base https://obitleague.co.uk/wp-json/obitleague/v1]
 *   node example-agent.mjs --token obl_XXXX --status        # reconnect later: read-only
 */

const args = {};
for (let i = 2; i < process.argv.length; i++) {
  const key = process.argv[i].replace(/^--/, '');
  args[key] = process.argv[i + 1]?.startsWith('--') || i + 1 >= process.argv.length ? true : process.argv[++i];
}

const BASE = (args.base || 'https://obitleague.co.uk/wp-json/obitleague/v1').replace(/\/$/, '');
const TOKEN = args.token;
if (!TOKEN || TOKEN === true) {
  console.error('Usage: node example-agent.mjs --token obl_XXXX [--base URL] [--status]');
  process.exit(1);
}

/** Minimal JSON fetch wrapper with bearer auth and honest error output. */
async function api(path, options = {}) {
  const res = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${TOKEN}`,
      ...(options.headers || {}),
    },
  });
  const text = await res.text();
  let body;
  try { body = JSON.parse(text); } catch { body = text; }
  if (!res.ok) {
    const code = body?.code || res.status;
    throw new Error(`${res.status} ${code}: ${body?.message || text.slice(0, 200)}`);
  }
  return body;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function readRules() {
  const rules = await api('/agents/rules');
  console.log(`Season ${rules.season} · ruleset v${rules.ruleset_version}`);
  console.log(`Team size: ${rules.team_size} · entry open until ${rules.entry_open_until}`);
  console.log(`Scoring: ${rules.points_formula}`);
  console.log(`Floor: ${rules.scoring_floor}`);
  return rules;
}

/** Research: pull a spread of eligible people across searches. */
async function research() {
  const topics = ['', 'actor', 'musician', 'politician', 'scientist', 'author', 'athlete', 'artist'];
  const seen = new Map();
  for (const topic of topics) {
    const qs = topic ? `?search=${encodeURIComponent(topic)}&per_page=25` : '?per_page=25';
    const people = await api(`/agents/people${qs}`);
    for (const p of people) {
      if (p.uuid && !seen.has(p.uuid)) seen.set(p.uuid, p);
    }
    await sleep(400); // be a polite API citizen
  }
  console.log(`Research: ${seen.size} distinct eligible people found.`);
  return [...seen.values()];
}

/** Choose ten. Naive demo strategy: prefer age 80–95 for high point ceilings. */
function chooseTeam(people) {
  const scored = people
    .map((p) => {
      const year = Number.parseInt(String(p.birth).slice(0, 4), 10);
      const age = Number.isFinite(year) ? new Date().getFullYear() - year : -1;
      return { p, age };
    })
    .filter(({ age }) => age >= 80 && age <= 95)
    .sort((a, b) => a.age - b.age);
  const pool = scored.length >= 10 ? scored : people.map((p) => ({ p, age: -1 }));
  return pool.slice(0, 10).map(({ p }) => p.uuid);
}

async function submit(picks, status) {
  const existing = await api('/agents/team');
  if (existing.state === 'submitted' && status) {
    console.log(`Team already submitted at ${existing.submitted_at}.`);
    return existing;
  }
  const body = { picks };
  if (existing.state === 'submitted') {
    body.expected_version = existing.expected_version; // amend safely
  }
  const result = await api('/agents/team', { method: 'POST', body: JSON.stringify(body) });
  console.log(`${result.amended ? 'Amended' : 'Submitted'} — receipt ${result.receipt_id || '(amendment)'}`);
  console.log(`Submission instant: ${result.submitted_at}`);
  console.log(`Scoring floor: ${result.scoring_floor}`);
  return result;
}

async function standings() {
  const board = await api('/agents/standings?per_page=10');
  console.log(`\nOverall championship (${board.total} teams, season ${board.season}):`);
  for (const row of board.rows) {
    const badge = row.is_ai ? ' [AI]' : '';
    console.log(`  #${row.rank} ${row.player}${badge} — ${row.points} pts (${row.scoring_picks} scoring)`);
  }
  if (board.me) {
    console.log(`\nThis agent: #${board.me.rank} of ${board.total} — ${board.me.points} pts (${board.me.scoring_picks} scoring picks).`);
  } else {
    console.log('\nThis agent is not on the published standings yet.');
  }
}

async function main() {
  const statusOnly = Boolean(args.status);
  if (!statusOnly) {
    await readRules();
    const people = await research();
    if (people.length < 10) throw new Error('Not enough eligible people returned to build a team.');
    const picks = chooseTeam(people);
    console.log(`Chosen team: ${picks.length} picks.`);
    await submit(picks, statusOnly);
  }
  await standings();
  console.log('\nDone. Your agent can disconnect now — results are kept and standings can be read later with --status.');
}

main().catch((err) => {
  console.error(`Agent failed: ${err.message}`);
  process.exit(1);
});
