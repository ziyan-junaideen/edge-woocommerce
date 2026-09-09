# CLAUDE.md

@AGENTS.md

Everything about this codebase — layout, build, verification, the local test site,
the Edge API and its invariants — lives in `AGENTS.md`, which is shared with the
other agents that work here. Read it; don't duplicate it back into this file.

## Keeping the two files in sync

`AGENTS.md` is what Codex reads during reviews, so it is the canonical brief. When
you learn something about this repo that any agent would need, it goes in
`AGENTS.md`, not here — including corrections. `AGENTS.md` has its own "Keeping
this file current" section; follow it.

Only put something in `CLAUDE.md` if it is specific to Claude Code's own tooling.
If you are about to write a fact about the plugin here, you are in the wrong file.

## Verifying in the browser

Frontend changes to the block checkout are worth seeing rather than reasoning
about. Use the `claude-in-chrome` skill, and rebuild the JS first — the plugin is
symlinked into the Studio site, so `npx wp-scripts build` is the whole deploy.

- Sign in with the auto-login URL in `AGENTS.md` rather than typing credentials.
- The hosted card form is a cross-origin iframe. You can see it and click the
  page around it, but you cannot read or type into its fields, so use the test
  cards for anything that needs a real card entered by hand and expect to drive
  that part manually or via the user.
- Do not click anything that raises a JS `alert`/`confirm` — a modal dialog wedges
  the extension for the rest of the session. Read `console` output with
  `read_console_messages` instead, filtered with `pattern` since the checkout is
  chatty.
- `read_network_requests` is the fastest way to see what actually went to
  `/edge/v1/checkout-intent` and to Edge.

## Cross-model review

`/code-review` and the `codex-reviewer` agent are the second opinion on payment
code, and they are worth using here: the failure modes in this plugin (double
charges, a stale idempotency key, a secret crossing into the browser) are the kind
a single pass misses. Codex reads `AGENTS.md`, so an out-of-date brief produces
confidently wrong review findings — another reason to keep it accurate.

## Environment notes

- `mise` and `studio` both work from the Bash tool as-is.
- Long PHP paths are in `AGENTS.md`. Nothing here has a system `php` or `wp`, so
  a bare `php`/`wp` in a command is always a mistake.
- `studio wp` needs `cd /Users/jdeen/Studio/my-wordpress-website` first. It fails
  with "The specified directory is not added to Studio" from the repo root, which
  looks like a Studio problem and is not.
- Use the scratchpad for throwaway scripts and captured output. This repo ships as
  a WordPress plugin ZIP; stray files in the tree end up in someone's `wp-content`.
