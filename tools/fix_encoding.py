#!/usr/bin/env python3
"""
Auto-fix common UTF-8/CP1251 mojibake in project text files.

This version is intentionally conservative:
- it only rewrites files where a large portion of non-ASCII tokens are
  confidently reversible;
- it repairs token-by-token, with up to N passes per token, which handles
  single and double mojibake safely;
- it leaves normal UTF-8 files untouched.

Examples:
  python tools/fix_encoding.py
  python tools/fix_encoding.py --write
  python tools/fix_encoding.py --write index.html booking rooms api/booking.php
"""

from __future__ import annotations

import argparse
import encodings.cp1251
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


DEFAULT_EXTENSIONS = {
    ".html",
    ".css",
    ".js",
    ".php",
    ".json",
    ".xml",
    ".txt",
    ".md",
    ".yml",
    ".yaml",
}

TOKEN_RE = re.compile(r"[^\t\r\n\f\v ]+")


@dataclass(frozen=True)
class FileStats:
    non_ascii_tokens: int
    convertible_tokens: int
    ratio: float
    changed_tokens: int


def build_cp1251_reverse_table() -> dict[str, int]:
    table: dict[str, int] = {}
    for byte_value, char in enumerate(encodings.cp1251.decoding_table):
        if char != "\ufffe":
            table[char] = byte_value
    return table


CP1251_REVERSE = build_cp1251_reverse_table()


def is_probably_binary(raw: bytes) -> bool:
    if b"\x00" in raw:
        return True
    sample = raw[:2048]
    if not sample:
        return False
    non_text = sum(1 for b in sample if b < 9 or (13 < b < 32))
    return non_text > len(sample) * 0.2


def iter_files(paths: Iterable[Path], extensions: set[str]) -> Iterable[Path]:
    for p in paths:
        if p.is_file():
            if p.suffix.lower() in extensions:
                yield p
            continue
        if not p.is_dir():
            continue
        for file in p.rglob("*"):
            if not file.is_file():
                continue
            if file.suffix.lower() not in extensions:
                continue
            yield file


def has_non_ascii(token: str) -> bool:
    return any(ord(ch) > 127 for ch in token)


def token_to_cp1251_bytes(token: str) -> bytes | None:
    out = bytearray()
    for ch in token:
        # Strip BOM marker if it appears at token start.
        if ch == "\ufeff":
            continue
        code = ord(ch)
        if code <= 255:
            out.append(code)
            continue
        mapped = CP1251_REVERSE.get(ch)
        if mapped is None:
            return None
        out.append(mapped)
    return bytes(out)


def decode_token_once(token: str) -> str | None:
    if not has_non_ascii(token):
        return None
    raw = token_to_cp1251_bytes(token)
    if raw is None or raw == b"":
        return None
    try:
        candidate = raw.decode("utf-8")
    except UnicodeDecodeError:
        return None
    if candidate == token:
        return None
    return candidate


def decode_token_iterative(token: str, max_passes: int) -> tuple[str, bool]:
    out = token
    changed = False
    for _ in range(max_passes):
        candidate = decode_token_once(out)
        if candidate is None:
            break
        out = candidate
        changed = True
    return out, changed


def analyze_file_tokens(text: str) -> tuple[int, int, float]:
    non_ascii_tokens = 0
    convertible_tokens = 0

    for token in TOKEN_RE.findall(text):
        if not has_non_ascii(token):
            continue
        non_ascii_tokens += 1
        if decode_token_once(token) is not None:
            convertible_tokens += 1

    ratio = (convertible_tokens / non_ascii_tokens) if non_ascii_tokens else 0.0
    return non_ascii_tokens, convertible_tokens, ratio


def should_fix_file(non_ascii_tokens: int, convertible_tokens: int, ratio: float, min_convertible: int, min_ratio: float) -> bool:
    if non_ascii_tokens == 0:
        return False
    if convertible_tokens < min_convertible:
        return False
    return ratio >= min_ratio


def fix_text_if_confident(
    text: str,
    *,
    min_convertible: int,
    min_ratio: float,
    max_passes: int,
) -> tuple[str, bool, FileStats]:
    non_ascii_tokens, convertible_tokens, ratio = analyze_file_tokens(text)
    if not should_fix_file(non_ascii_tokens, convertible_tokens, ratio, min_convertible, min_ratio):
        stats = FileStats(non_ascii_tokens, convertible_tokens, ratio, 0)
        return text, False, stats

    changed_tokens = 0

    def repl(match: re.Match[str]) -> str:
        nonlocal changed_tokens
        token = match.group(0)
        fixed, changed = decode_token_iterative(token, max_passes=max_passes)
        if changed:
            changed_tokens += 1
        return fixed

    fixed_text = TOKEN_RE.sub(repl, text)
    changed = fixed_text != text
    stats = FileStats(non_ascii_tokens, convertible_tokens, ratio, changed_tokens)
    return fixed_text, changed, stats


def process_file(
    path: Path,
    *,
    write: bool,
    backup_suffix: str,
    min_convertible: int,
    min_ratio: float,
    max_passes: int,
) -> tuple[bool, str]:
    try:
        raw = path.read_bytes()
    except OSError as exc:
        return False, f"read-error: {exc}"

    if is_probably_binary(raw):
        return False, "binary-skip"

    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        return False, "non-utf8-skip"

    fixed_text, changed, stats = fix_text_if_confident(
        text,
        min_convertible=min_convertible,
        min_ratio=min_ratio,
        max_passes=max_passes,
    )

    if not changed:
        return False, (
            f"no-change conv={stats.convertible_tokens}/{stats.non_ascii_tokens}"
            f" ratio={stats.ratio:.3f}"
        )

    if not write:
        return True, (
            f"would-fix conv={stats.convertible_tokens}/{stats.non_ascii_tokens}"
            f" ratio={stats.ratio:.3f} changed_tokens={stats.changed_tokens}"
        )

    backup_path = path.with_suffix(path.suffix + backup_suffix)
    try:
        if not backup_path.exists():
            backup_path.write_bytes(raw)
        path.write_text(fixed_text, encoding="utf-8", newline="")
    except OSError as exc:
        return False, f"write-error: {exc}"

    return True, (
        f"fixed conv={stats.convertible_tokens}/{stats.non_ascii_tokens}"
        f" ratio={stats.ratio:.3f} changed_tokens={stats.changed_tokens}"
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fix UTF-8/CP1251 mojibake in project text files.")
    parser.add_argument("paths", nargs="*", default=["."], help="Files or folders to scan.")
    parser.add_argument("--write", action="store_true", help="Apply fixes in-place (default: dry-run).")
    parser.add_argument(
        "--backup-suffix",
        default=".bak",
        help="Backup suffix for original files when --write is used (default: .bak).",
    )
    parser.add_argument(
        "--ext",
        nargs="*",
        default=None,
        help="Custom list of file extensions to scan (example: --ext .html .js .php).",
    )
    parser.add_argument(
        "--min-convertible",
        type=int,
        default=20,
        help="Minimum number of reversible non-ASCII tokens required before rewriting a file (default: 20).",
    )
    parser.add_argument(
        "--min-ratio",
        type=float,
        default=0.70,
        help="Minimum reversible/non-ASCII token ratio required before rewriting a file (default: 0.70).",
    )
    parser.add_argument(
        "--max-passes",
        type=int,
        default=4,
        help="Max decode passes per token to recover double-mojibake (default: 4).",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    extensions = {e.lower() if e.startswith(".") else f".{e.lower()}" for e in (args.ext or DEFAULT_EXTENSIONS)}
    roots = [Path(p).resolve() for p in args.paths]

    changed_files: list[tuple[Path, str]] = []
    skipped_binary = 0
    skipped_non_utf8 = 0

    for file in iter_files(roots, extensions):
        changed, status = process_file(
            file,
            write=args.write,
            backup_suffix=args.backup_suffix,
            min_convertible=args.min_convertible,
            min_ratio=args.min_ratio,
            max_passes=max(1, args.max_passes),
        )
        if changed:
            changed_files.append((file, status))
        elif status == "binary-skip":
            skipped_binary += 1
        elif status == "non-utf8-skip":
            skipped_non_utf8 += 1

    mode = "APPLY" if args.write else "DRY-RUN"
    print(f"[{mode}] scanned roots: {', '.join(str(p) for p in roots)}")
    print(f"[{mode}] changed files: {len(changed_files)}")
    if skipped_binary:
        print(f"[{mode}] binary skipped: {skipped_binary}")
    if skipped_non_utf8:
        print(f"[{mode}] non-utf8 skipped: {skipped_non_utf8}")

    for path, status in changed_files:
        print(f" - {status}: {path}")

    if not args.write and changed_files:
        print("Use --write to apply fixes.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
