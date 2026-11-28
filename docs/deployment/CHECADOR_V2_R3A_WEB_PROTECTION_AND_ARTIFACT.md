# R3-A — Web protection and deployment artifact

**Status:** local source protection and artifact preparation only. No Plesk or production configuration has been inspected or changed.

## Source repository versus web artifact

The Git repository retains `tests/` and its synthetic fixtures for development. The root `.htaccess` denies `/tests` before front-controller fall-through, and `tests/.htaccess` adds an Apache 2.4 `Require all denied` defense in depth. CLI execution is unaffected by either Apache rule.

There was no existing API packaging mechanism in the repository. `scripts/build_api_release_artifact.sh` creates a deterministic gzip tar artifact outside the repository using an explicit runtime allowlist:

- `index.php`, root `.htaccess`;
- all of `modelo/` (the router and model code load these runtime dependencies);
- `scripts/JourneyBatchProcessor.php`, `scripts/JourneyEnvironmentProfile.php`, `scripts/journey_batch_runtime.php`, and `scripts/journey_processor.php` (the authorized individual and batch CLI runtime).

The artifact deliberately excludes `tests/`, `.git/`, private `.env*` and QA fixture files, all other `scripts/` utilities (including SQL and provisioning tools), `migraciones/`, `docs/` and draft SQL/contracts, logs, sockets, and local checkpoints. The builder rejects symlinks and forbidden paths, writes `SHA256SUMS`, inspects the final archive listing, and never writes its output under the repository.

Build and inspect locally:

```sh
bash scripts/build_api_release_artifact.sh
tar -tzf /tmp/checador-v2-api-release-$(git rev-parse --short=12 HEAD)-working-tree.tar.gz
```

An optional first argument selects another output path outside the repository. This produces a candidate only; it does not deploy or imply release approval.

## Local HTTP verification

Run `bash tests/v2_r3a_web_protection_tests.sh`. It uses an isolated temporary DocumentRoot, local loopback HTTP, Apache rewrite/authz modules, and harmless marker files only. It verifies `/tests`, `/tests/`, a PHP marker, a static marker, a nested marker, case-insensitive path matching, and the existing `/api/v2/punch-events` method guard. The temporary server, files, and logs are deleted on exit. It does not invoke a test runner over HTTP or connect to MariaDB.

## Required Plesk checks before publication

An operator must inspect the effective target before deploying an archive:

1. Resolve the domain's actual DocumentRoot, aliases and any project subdirectory mapping.
2. Check Apache vhost includes and `AllowOverride` scope; verify the shipped root `.htaccess` is active. Confirm mod_rewrite is loaded and `Require all denied` is supported for the defense-in-depth file if `tests/` is ever present.
3. Inspect Nginx proxy/static/alias rules and confirm they do not serve a separate repository path or bypass Apache authorization for `/tests`.
4. Inspect the candidate archive listing and deployed web root; verify no `tests/`, fixtures, test coordination tools, SQL review drafts, private configuration, logs, sockets, or checkpoint files are present.
5. Verify `/api/v2/` routes and approved legacy routes using the normal authentication and method contracts. Confirm CLI files remain executable only through PHP CLI and their gates remain closed by default.
6. Repeat the harmless HTTP-denial checks against the effective PREPROD vhost before declaring server-side protection.

Plesk's effective Apache/Nginx configuration, DocumentRoot, live route behavior, and deployed artifact contents remain unverified here. Do not infer server protection from these local checks.
