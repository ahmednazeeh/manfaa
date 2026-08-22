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
import hashlib
from pathlib import Path
import re
import sys

import yaml

ROOT = Path(__file__).resolve().parent.parent
SPEC = ROOT / "docs" / "openapi.yaml"
GUIDE = ROOT / "docs" / "integration-guide.md"
OUT = ROOT / "docs" / "public" / "openapi.docs.yaml"
PAGE = ROOT / "docs" / "public" / "index.html"

# Guide sections the spec description already covers in full, where the titles
# differ enough that exact matching misses them. Normalised form; keep this
# list short — anything here is content the reader still gets, just once.
SKIP_TITLES = {
    "idempotency required on every write",
}

# Only these guide sections belong on the REFERENCE page — the things you
# cannot make a single call without.
#
# Everything else (money law, rates, timestamps, webhooks, retry policy,
# go-live checklist, and the guide's own walk through each endpoint) stays in
# the guide and is linked instead. The reference page had grown to ~5,600
# words of prose before the first endpoint — about half an hour's reading to
# reach a five-endpoint API — and its longest section re-documented the very
# endpoints Scalar already renders from `paths`, so a developer met each one
# twice and could not tell which was authoritative.
MERGE_ONLY = {
    "authentication",
    "idempotency",
    "errors",
    "sandbox",
    # Webhooks joined on 2026-08-22: Scalar renders a tag section only for
    # tags that own path operations, so "Webhooks — POS vendors" (which owns
    # none — the event callbacks are filed under Scalar's own Webhooks
    # group) never showed its description, and a vendor reading the
    # reference found no instructions at all. The guide's chapter is the one
    # source; it renders here with its own sidebar entries.
    "webhooks",
}

GUIDE_LINK = (
    "\n## The full integration guide\n\n"
    "Everything above is what you need to make a call. The rest — how money "
    "and rounding work, how rates and promotions interact, the settlement "
    "clock, webhooks and their signatures, retry expectations and the go-live "
    "checklist — is in the [integration guide](/docs/integration-guide).\n\n"
    "The endpoint reference below is authoritative: where the guide and this "
    "page ever disagree, believe this page.\n"
)

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

    # Before anything is written: a published example that its own schema
    # refuses is worse than no example at all.
    check_examples(spec)

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
        if key in existing or key in SKIP_TITLES or key not in MERGE_ONLY:
            skipped.append(heading)
            continue
        existing.add(key)
        kept.append(f"## {denumber(heading)}\n{body.rstrip()}")

    kept.append(GUIDE_LINK)
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

    stamp_cache_buster()

    return 0


def _paired_examples(node, path=""):
    """Every example in the spec, paired with the schema it sits next to."""
    found = []

    if isinstance(node, dict):
        # A media-type object: `schema` and `example` are siblings.
        schema = node.get("schema")
        if isinstance(schema, dict) and "example" in node:
            found += _walk_example(node["example"], schema, path)

        # A schema property carrying its own `example`.
        for name, prop in (node.get("properties") or {}).items():
            if isinstance(prop, dict) and "example" in prop:
                found.append((f"{path}.{name}", prop["example"], prop))

        for key, child in node.items():
            found += _paired_examples(child, f"{path}.{key}")

    elif isinstance(node, list):
        for index, child in enumerate(node):
            found += _paired_examples(child, f"{path}[{index}]")

    return found


def _walk_example(example, schema, path):
    """Match an example object's keys against the schema's properties."""
    if not isinstance(example, dict):
        return []

    found = []

    for key, value in example.items():
        prop = (schema.get("properties") or {}).get(key)

        if not isinstance(prop, dict):
            continue

        found.append((f"{path}.{key}", value, prop))

        if isinstance(value, dict):
            found += _walk_example(value, prop, f"{path}.{key}")

    return found


def check_examples(spec):
    """
    Refuse to publish an example its own schema would reject.

    An example is a promise that this exact value works. That promise was
    broken once already: the connect `code_verifier` sample was 42 characters
    against a declared minLength of 43, so the first thing a vendor did with
    the docs was copy it, get a 422, and read a message blaming their input.
    """
    broken = []

    for path, value, schema in _paired_examples(spec):
        # Placeholders like '<your secret>' stand in for a value the reader
        # supplies; they are not claims that the string itself works.
        if isinstance(value, str) and value.startswith("<"):
            continue

        if isinstance(value, str):
            length = len(value)
            low, high = schema.get("minLength"), schema.get("maxLength")

            if low is not None and length < low:
                broken.append(f"{path}: {length} characters, below minLength {low}")
            if high is not None and length > high:
                broken.append(f"{path}: {length} characters, above maxLength {high}")

        if "enum" in schema and value not in schema["enum"]:
            broken.append(f"{path}: {value!r} is not one of {schema['enum']}")

    if broken:
        print("REFUSING TO PUBLISH — examples a reader cannot use:", file=sys.stderr)
        for line in broken:
            print(f"  {line}", file=sys.stderr)
        sys.exit(1)


def stamp_cache_buster() -> None:
    """Point the reference page at THIS build of the spec.

    The page loads `openapi.docs.yaml?v=<hash>`, and nothing ever updated
    that hash — so every rebuild published a corrected spec at a URL the
    browser already had cached, and readers kept seeing the old contract
    with no way to tell. A stale cache-buster is worse than none: it looks
    deliberate.
    """
    digest = hashlib.md5(OUT.read_bytes()).hexdigest()[:12]
    page = PAGE.read_text(encoding="utf-8")
    updated = re.sub(
        r'(openapi\.docs\.yaml\?v=)[0-9a-f]+',
        lambda m: m.group(1) + digest,
        page,
    )

    if updated != page:
        PAGE.write_text(updated, encoding="utf-8")
        print(f"  cache-buster -> {digest}")


if __name__ == "__main__":
    raise SystemExit(main())
