#!/usr/bin/env python3
"""
Produces docs/public/openapi.docs.yaml: the canonical spec with the narrative
integration guide appended to info.description.

Why a separate file rather than editing openapi.yaml: the canonical spec is a
machine contract (codegen, validators, vendor tooling) and must stay lean.
The docs build wants one page where a developer reads the guide and the
endpoint reference together, which is exactly what Scalar renders from
info.description plus the paths.

The guide's own H1 and its duplicated intro sections are dropped — the spec's
description already covers money, rates and idempotency, and Scalar lists every
heading in the sidebar, so repeating them would produce two of each.

    python3 scripts/merge-guide-into-spec.py
"""
from pathlib import Path
import re
import sys

import yaml

ROOT = Path(__file__).resolve().parent.parent
SPEC = ROOT / "docs" / "openapi.yaml"
GUIDE = ROOT / "docs" / "integration-guide.md"
OUT = ROOT / "docs" / "public" / "openapi.docs.yaml"

# Guide sections the spec description already covers in full, where the titles
# differ enough that exact matching misses them. Normalised form; keep this
# list short — anything here is content the reader still gets, just once.
SKIP_TITLES = {
    "idempotency required on every write",
}

def guide_sections(markdown: str) -> list[tuple[str, str]]:
    """Split the guide into (heading, body) pairs on H2 boundaries."""
    parts = re.split(r"^## (.+)$", markdown, flags=re.MULTILINE)
    # parts[0] is the preamble (H1 + intro); pairs follow.
    return [(parts[i].strip(), parts[i + 1]) for i in range(1, len(parts) - 1, 2)]


def normalise(title: str) -> str:
    """De-numbered, punctuation-free title used for duplicate detection."""
    title = re.sub(r"^\d+\.\s*", "", title)
    return re.sub(r"[^a-z0-9]+", " ", title.lower()).strip()


def denumber(title: str) -> str:
    """Sidebar titles read better without the guide's own section numbers."""
    return re.sub(r"^\d+\.\s*", "", title)


def main() -> int:
    spec = yaml.safe_load(SPEC.read_text(encoding="utf-8"))
    guide = GUIDE.read_text(encoding="utf-8")

    description = spec["info"].get("description", "").rstrip()

    # Whatever the spec description already explains (money, rates,
    # idempotency, errors, sandbox) must not appear twice in the sidebar.
    existing = {
        normalise(h) for h in re.findall(r"^## (.+)$", description, flags=re.MULTILINE)
    }

    kept = []
    skipped = []
    for heading, body in guide_sections(guide):
        key = normalise(heading)
        if key in existing or key in SKIP_TITLES:
            skipped.append(heading)
            continue
        existing.add(key)
        kept.append(f"## {denumber(heading)}\n{body.rstrip()}")
    if not kept:
        print("no guide sections found — refusing to write", file=sys.stderr)
        return 1

    spec["info"]["description"] = description + "\n\n" + "\n\n".join(kept) + "\n"

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(
        yaml.safe_dump(spec, sort_keys=False, allow_unicode=True, width=100),
        encoding="utf-8",
    )
    print(f"merged {len(kept)} guide sections -> {OUT}")
    if skipped:
        print("  skipped (already in the spec description): " + ", ".join(skipped))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
