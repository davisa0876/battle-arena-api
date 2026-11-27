# Branch protection for `main` (battle-arena-api)

CI workflow: `.github/workflows/ci.yml`

## Required status checks

Configure GitHub branch protection on `main` so pull requests cannot merge unless these checks pass:

| Check name (job `name:`) | Purpose |
|---|---|
| `phpstan` | Static analysis (Larastan level 6 + baseline) |
| `unit` | Unit suite must pass |
| `unit-coverage` | Unit+Feature against `app/Domain` + `app/Application` at ≥92% line coverage |
| `feature` | Feature suite |

`pint` currently uses `continue-on-error: true` and should **not** be required until style debt is cleaned.

## Recommended settings

- Require a pull request before merging
- Require the status checks above to pass
- Require branches to be up to date before merging (optional but recommended)
- Do not allow administrators to bypass the above if the gate must be enforced for everyone

## Apply via GitHub UI

1. Repo **Settings → Branches → Add branch ruleset / Branch protection rule** for `main`
2. Enable **Require status checks to pass**
3. Search and select: `phpstan`, `unit`, `unit-coverage`, `feature`
4. Save

Checks appear in the list only after the workflow has run at least once on the default branch or a PR.

## Apply via GitHub CLI (admin)

```bash
gh api repos/davisa0876/battle-arena-api/branches/main/protection \
  --method PUT \
  --input - <<'EOF'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["phpstan", "unit", "unit-coverage", "feature"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 0
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
EOF
```

Adjust review requirements to match team policy. Re-verify context names against the Actions UI if GitHub renames checks.

## Local parity

```bash
composer phpstan
composer test:unit-coverage
php artisan test --testsuite=Feature
```
