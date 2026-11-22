# Security policy

## Reporting a vulnerability

Please do not report security vulnerabilities in public issues, pull requests or discussions.

Report them privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** (GitHub's private vulnerability reporting). Include the plugin version, the HeimDB version, how to reproduce the problem, and its impact as you understand it.

We aim to acknowledge a report within 5 business days, and to agree with you on a fix and a disclosure date. Fixes are released as a new version on GitHub, with a GitHub security advisory.

## Supported versions

Only the latest release receives security fixes.

## Scope

In scope: the plugin (`heimdb.php`, `includes/`, `assets/`). Examples: a user without the required capability (`heimdb_capability`, `manage_options` by default) operating HeimDB, a branch site reaching production or other branches, missing nonce or capability checks on wp-admin operations, cross-site scripting in the screens, and leaking the administrator credentials (`HEIMDB_ADMIN_*`).

Out of scope: HeimDB itself (report it to the [HeimDB repository](https://github.com/tsukinowasha/heimdb/security)), anyone who can already run PHP on the site (they can read `HEIMDB_ADMIN_*` by design; see "Configuration" in [`README.md`](README.md)), weak passwords chosen by the operator, and the development environment and tests under `dev/` and `tests/`.
