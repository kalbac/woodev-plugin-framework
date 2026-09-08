# Killing a hung PHPUnit leaves its MySQL query running, and the NEXT run hangs on a lock

> Namespace: `testing/integration` — added session 128 (2026-09-09).

## The trap

An integration run hangs. You kill the PHP process, fix the test, run again — and the second run
hangs too, in a completely different and innocent-looking place:

```text
run 1:  php … phpunit --testsuite=Integration      (killed after 4 min)
run 2:  php /wordpress-phpunit/includes/install.php …   stuck 120 s on:
            DROP TABLE IF EXISTS wp_users
```

`DROP TABLE wp_users` reads as a broken test harness, a permissions problem, or a corrupted test
database. It is none of those.

## Root cause

Killing the client does not cancel the query. The original statement was still executing:

```text
Id     Command  Time  State          Info
24318  Query    516   Sending data   SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts LEFT JOIN …
```

and it holds metadata locks on the tables it reads, so the next run's schema installation blocks
behind it. The two symptoms look unrelated; they are the same runaway query.

## ✅ Correct — kill it in the database, not just the shell

The MySQL/MariaDB client is not installed in the wp-env **mysql** container under the name you
expect — it is `mariadb`, not `mysql`:

```bash
docker exec <hash>-tests-mysql-1 mariadb -uroot -ppassword -e "SHOW FULL PROCESSLIST"
docker exec <hash>-tests-mysql-1 mariadb -uroot -ppassword -e "KILL <id>"
```

Read `SHOW FULL PROCESSLIST` FIRST whenever an integration run hangs: it names the exact statement,
which is usually the fastest route to the real defect. Here it showed twelve `LEFT JOIN`s on
`wp_postmeta` and turned "the suite is slow" into a measured finding (card #839).

## Also: the kill makes the runner lie

A background task killed this way reports **`completed, exit 0`** with an empty output file,
because the pipeline's last stage exited cleanly. That is the runner, not a gate — the suite did
not pass. Same shape as the `Stop-Process` note in the Related gotcha.

## Related

- [run-a-migrating-plugin-s-phpstan-in-the-container-not-on-windows](run-a-migrating-plugin-s-phpstan-in-the-container-not-on-windows.md) — a killed background task reporting `completed, exit 0`
- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — the coordinator's integration command
- [a-negative-meta-clause-or-ed-across-providers-matches-every-order](a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — what the runaway query was actually doing
