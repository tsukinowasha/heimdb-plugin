# Contributing to the HeimDB plugin

Thank you for your interest in the HeimDB plugin. Bug reports and pull requests are welcome.

## Scope

This plugin is a WordPress front end (wp-admin and wp-cli) for [HeimDB](https://github.com/tsukinowasha/heimdb)'s procedures. Branching, diffs, merges and time travel themselves are done by HeimDB: if HeimDB returns a wrong result, report it to the [HeimDB repository](https://github.com/tsukinowasha/heimdb/issues) instead.

## Reporting issues

- **Security issues**: do not open a public issue. See [`SECURITY.md`](SECURITY.md).
- **Bugs**: open an issue with the plugin version, the HeimDB version (the Docker image tag), the WordPress and PHP versions, what you did (the screen or the `wp heimdb` command), what you expected and what happened (the notice or the error with its number, such as `[51004]`).

## Pull requests

For anything larger than a small fix, open an issue first so we can agree on the approach.

- Keep the plugin a thin layer over HeimDB's procedures: the plugin keeps no state of its own in the database (a merge or restore would replace or roll it back).
- The plugin supports HeimDB 0.3.0 and later. A feature that needs a newer HeimDB tells the user which version it needs.
- Add a check to `tests/run.php` for logic that does not need WordPress, and to `tests/e2e.sh` for anything that goes through HeimDB, wp-admin or wp-cli.
- Changes to user-facing strings need the Japanese translation updated (see "Development" in [`README.md`](README.md)).
- Update both [`README.md`](README.md) and [`README.ja.md`](README.ja.md) when behavior changes.
- Before pushing:

  ```sh
  docker compose -f dev/compose.yaml up -d
  php tests/run.php
  tests/e2e.sh
  docker run --rm -v "$PWD:/app:ro" wordpress:cli-php8.4 sh /app/tests/i18n-check.sh
  ```

## License

By contributing, you agree that your contributions are licensed under the [Apache License 2.0](LICENSE).
