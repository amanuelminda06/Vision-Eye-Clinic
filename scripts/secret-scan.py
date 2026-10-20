#!/usr/bin/env python3
"""
Scan a git repository (history + working tree) for committed secrets.

REPORTS LOCATIONS ONLY — never the matched value. Every hit is printed as
`<location>: <kind> (redacted)`.

Usage: python3 scripts/secret-scan.py [repo-path]
"""

import os
import re
import subprocess
import sys

REPO = sys.argv[1] if len(sys.argv) > 1 else "."

# Each pattern: (kind, compiled regex). The regex must contain a group that
# matches the *value* to be redacted; only the location is ever printed.
PATTERNS = [
    ("github-token", re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,})\b")),
    ("gitlab-token", re.compile(r"\bglpat-[A-Za-z0-9_-]{20,}\b")),
    ("slack-token", re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}\b")),
    ("aws-access-key-id", re.compile(r"\b(?:AKIA|ASIA)[0-9A-Z]{16}\b")),
    ("private-key-block", re.compile(r"-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----")),
    ("bearer-authorization", re.compile(r"(?i)\bauthorization:\s*bearer\s+[A-Za-z0-9._\-]{20,}")),
    ("basic-auth-url", re.compile(r"\bhttps?://[^/\s:@]+:[^/\s:@]{6,}@[^\s/]+")),
    ("postgres-url-with-password", re.compile(r"\bpostgres(?:ql)?://[^/\s:@]+:[^/\s:@]+@")),
    ("mysql-url-with-password", re.compile(r"\bmysql://[^/\s:@]+:[^/\s:@]+@")),
    ("generic-api-key-assignment", re.compile(
        r"(?i)\b(?:api[_-]?key|secret[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|app[_-]?secret|turnstile[_-]?secret[_-]?key|db[_-]?password|sms[_-]?api[_-]?key)\b\s*[:=]\s*[\"']?([^\s\"',;#]{8,})")),
    ("laravel-app-key-assignment", re.compile(
        r"(?i)\bAPP_KEY\s*=\s*base64:([A-Za-z0-9+/=]{20,})")),
    ("assigned-credential-line", re.compile(
        r"(?i)^\s*(?:export\s+)?[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY)[A-Z0-9_]*\s*=\s*[\"']?([^\s\"',#]{8,})")),
]

# Files that legitimately contain the *shape* of a secret (placeholders,
# example keys, the password policy list) — flagged but reported as
# "placeholder" so they are easy to triage.
PLACEHOLDER_MARKERS = (
    "example", "placeholder", "changeme", "your-", "change-me", "redacted",
    "xxxx", "not-a-real", "dummy", "fake", "test-only", "no-secret",
    "replace", "todo", "null", "none", "inactive",
    # Local development defaults (the embedded database's own credentials).
    "postgres", "root", "password", "secret", "admin", "mysql",
)

# A value that is really a code expression or a variable reference, not a
# literal secret.
CODE_VALUE_MARKERS = (
    "${", "$(", "process.env", "env(", "z.", "required", "optional",
    "string(", "new ", "require(", "import ", "=>", "::", "function",
)

TEXT_SUFFIXES = (
    ".php", ".js", ".ts", ".tsx", ".jsx", ".json", ".md", ".txt", ".yml", ".yaml",
    ".env", ".example", ".sh", ".sql", ".mjs", ".cjs", ".css", ".html", ".xml",
    ".ini", ".conf", ".htaccess", "",
)


def is_placeholder(value: str) -> bool:
    low = value.lower()
    return any(marker in low for marker in PLACEHOLDER_MARKERS)


def is_code_expression(value: str) -> bool:
    return any(marker in value for marker in CODE_VALUE_MARKERS)


def scan_text(text: str, location: str, findings: list) -> None:
    for kind, pattern in PATTERNS:
        for match in pattern.finditer(text):
            value = match.group(match.lastindex or 0) or ""
            value = value.strip().strip("\"'")
            if len(value) < 8:
                continue
            if is_code_expression(value):
                findings.append((location, kind, "code-reference"))
            elif is_placeholder(value):
                findings.append((location, kind, "placeholder"))
            else:
                findings.append((location, kind, "SECRET"))


def run(*args) -> str:
    return subprocess.run(
        args, cwd=REPO, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False
    ).stdout.decode("utf-8", "replace")


def scan_history(findings: list) -> None:
    """Every blob ever committed, scanned once, with the commit that added it.

    `git rev-list --objects --all` yields (sha, path) for every object that
    has ever existed in the history. Scanning each unique blob once is far
    faster than walking every commit tree.
    """
    entries = run("git", "rev-list", "--objects", "--all").splitlines()

    seen: set = set()
    for line in entries:
        parts = line.split(" ", 1)
        if len(parts) != 2:
            continue
        blob, path = parts[0], parts[1].strip()

        if blob in seen:
            continue
        seen.add(blob)

        if not path.endswith(TEXT_SUFFIXES) and "." not in os.path.basename(path):
            continue

        content = run("git", "cat-file", "blob", blob)
        if not content:
            continue

        before = len(findings)
        scan_text(content, "", findings)
        if len(findings) == before:
            continue

        # The commit that introduced the path (the first that added it).
        intro = run(
            "git", "log", "--all", "--diff-filter=A", "--reverse",
            "--format=%H", "--", path,
        ).split()
        commit = intro[0][:12] if intro else "unknown"

        for index in range(before, len(findings)):
            location, kind, level = findings[index]
            findings[index] = (f"commit {commit}:{path}", kind, level)


def scan_worktree(findings: list) -> None:
    for root, dirs, files in os.walk(REPO):
        dirs[:] = [
            d for d in dirs
            if d not in {".git", "node_modules", "vendor", "storage", ".next", ".pgdata"}
        ]
        for name in files:
            path = os.path.join(root, name)
            rel = os.path.relpath(path, REPO)
            try:
                if os.path.getsize(path) > 4_000_000:
                    continue
                with open(path, "r", encoding="utf-8", errors="ignore") as handle:
                    content = handle.read()
            except OSError:
                continue
            scan_text(content, f"worktree:{rel}", findings)


def main() -> int:
    findings: list = []
    scan_history(findings)
    scan_worktree(findings)

    secrets = [f for f in findings if f[2] == "SECRET"]
    placeholders = [f for f in findings if f[2] == "placeholder"]
    references = [f for f in findings if f[2] == "code-reference"]

    print("=== LOCATIONS ONLY — no values are printed ===\n")
    print(f"=== potential secrets: {len(secrets)} ===")
    for location, kind, _ in sorted(set(secrets)):
        print(f"  {location}  [{kind}]")

    print(f"\n=== placeholders / local dev defaults: {len(placeholders)} ===")
    for location, kind, _ in sorted(set(placeholders)):
        print(f"  {location}  [{kind}]")

    print(f"\n=== code references (variable / expression, not literals): {len(references)} ===")
    for location, kind, _ in sorted(set(references)):
        print(f"  {location}  [{kind}]")

    return 1 if secrets else 0


if __name__ == "__main__":
    sys.exit(main())
