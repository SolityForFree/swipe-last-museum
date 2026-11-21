#!/usr/bin/env python3
"""Build a style-balanced set of public-domain artworks from the Art Institute of Chicago API.

Output: artworks.js  (defines window.ARTWORKS = [...]) so index.html works straight from disk.

Usage:  python3 build_dataset.py [--target 1000]
Data:   https://api.artic.edu/docs/  (metadata CC0; images of public-domain works are CC0)
"""
import argparse
import html
import json
import random
import re
import sys
import time
import urllib.request
from collections import defaultdict

API = "https://api.artic.edu/api/v1/artworks/search"
HEADERS = {
    "Content-Type": "application/json",
    "AIC-User-Agent": "art-inspiration-sampler (personal project)",
    "User-Agent": "art-inspiration-sampler/1.0",
}
TYPES = ["Painting", "Print", "Drawing and Watercolor"]
FIELDS = [
    "id", "title", "artist_display", "artist_title", "date_display", "date_start",
    "style_title", "style_titles", "artwork_type_title", "medium_display", "place_of_origin",
    "description", "short_description", "image_id", "thumbnail",
]
PER_STYLE_FETCH = 100   # candidates pulled per style
PER_ERA_FETCH = 100     # unstyled candidates pulled per century
MIN_STYLE_COUNT = 2     # ignore styles with fewer works than this
MAX_PER_GROUP = 60      # cap so no single style dominates

ORDINALS = {
    "first": 1, "second": 2, "third": 3, "fourth": 4, "fifth": 5, "sixth": 6,
    "seventh": 7, "eighth": 8, "ninth": 9, "tenth": 10, "eleventh": 11,
    "twelfth": 12, "thirteenth": 13, "fourteenth": 14, "fifteenth": 15,
    "sixteenth": 16, "seventeenth": 17, "eighteenth": 18, "nineteenth": 19,
    "twentieth": 20,
}


def base_filter():
    return [
        {"term": {"is_public_domain": True}},
        {"exists": {"field": "image_id"}},
        {"terms": {"artwork_type_title.keyword": TYPES}},
    ]


def post(body, retries=4):
    data = json.dumps(body).encode()
    for attempt in range(retries):
        try:
            req = urllib.request.Request(API, data=data, headers=HEADERS, method="POST")
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.load(r)
        except Exception as e:  # network hiccup or 429
            wait = 2 ** attempt * 2
            print(f"  request failed ({e}); retrying in {wait}s", file=sys.stderr)
            time.sleep(wait)
    raise RuntimeError("API request failed repeatedly")


def ordinal(n):
    suffix = "th" if 10 <= n % 100 <= 20 else {1: "st", 2: "nd", 3: "rd"}.get(n % 10, "th")
    return f"{n}{suffix}"


def century_label(year):
    if year is None:
        return None
    if year <= 0:
        return f"{ordinal(abs(year) // 100 + 1)} century BCE"
    return f"{ordinal((year - 1) // 100 + 1)} century"


def normalize_style(raw):
    """Merge spelling variants ('nineteenth century', '19th Century') and tidy case."""
    s = raw.strip()
    m = re.fullmatch(r"(\w+)\s+century", s, re.I)
    if m:
        word = m.group(1).lower()
        num = ORDINALS.get(word)
        if num is None:
            d = re.match(r"(\d+)", word)
            num = int(d.group(1)) if d else None
        if num:
            return f"{ordinal(num)} century"
    s = re.sub(r"\s*\((culture or style|style or period|historically identified as)\)", "", s, flags=re.I)
    return s[:1].upper() + s[1:]


def clean_text(s):
    if not s:
        return ""
    s = re.sub(r"<[^>]+>", " ", s)
    s = html.unescape(s)
    return re.sub(r"\s+", " ", s).strip()


STYLE_COUNTS = {}  # raw style tag -> number of works; filled in main()


def to_record(r):
    tags = list(dict.fromkeys(([r["style_title"]] if r.get("style_title") else []) + (r.get("style_titles") or [])))
    # Label the work with its rarest tag: "Japanism" beats "19th century".
    style_raw = min(tags, key=lambda t: STYLE_COUNTS.get(t, 10**9)) if tags else None
    era = century_label(r.get("date_start"))
    style = normalize_style(style_raw) if style_raw else None
    tag_list = list(dict.fromkeys(normalize_style(t) for t in tags))
    desc = clean_text(r.get("description")) or clean_text(r.get("short_description"))
    thumb = r.get("thumbnail") or {}
    return {
        "id": r["id"],
        "title": clean_text(r.get("title")) or "Untitled",
        "artist": clean_text(r.get("artist_display")) or "Unknown artist",
        "date": r.get("date_display") or "",
        "year": r.get("date_start"),
        "era": era,
        "style": style,
        "tags": tag_list,
        "type": r.get("artwork_type_title"),
        "medium": clean_text(r.get("medium_display")),
        "origin": r.get("place_of_origin") or "",
        "description": desc,
        "alt": clean_text(thumb.get("alt_text")),
        "w": thumb.get("width"),
        "h": thumb.get("height"),
        "image_id": r["image_id"],
        "url": f"https://www.artic.edu/artworks/{r['id']}",
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--target", type=int, default=1000)
    ap.add_argument("--seed", type=int, default=7)
    ap.add_argument("--out", default="artworks.js")
    args = ap.parse_args()
    random.seed(args.seed)

    print("Listing styles…")
    agg = post({
        "query": {"bool": {"filter": base_filter()}},
        "limit": 0,
        "aggs": {"styles": {"terms": {"field": "style_titles.keyword", "size": 400}}},
    })
    total = agg["pagination"]["total"]
    STYLE_COUNTS.update({b["key"]: b["doc_count"] for b in agg["aggregations"]["styles"]["buckets"]})
    styles = [(b["key"], b["doc_count"]) for b in agg["aggregations"]["styles"]["buckets"]
              if b["doc_count"] >= MIN_STYLE_COUNT]
    print(f"  {total} public-domain paintings/prints/drawings; {len(styles)} styles with ≥{MIN_STYLE_COUNT} works")

    pool = {}
    for i, (style, count) in enumerate(styles, 1):
        print(f"[{i}/{len(styles)}] {style} ({count})")
        res = post({
            "query": {"bool": {
                "filter": base_filter() + [{"term": {"style_titles.keyword": style}}],
                # rank works with a curator description first
                "should": [{"exists": {"field": "description"}}],
            }},
            "fields": FIELDS,
            "limit": min(PER_STYLE_FETCH, count),
        })
        for r in res["data"]:
            if r.get("image_id"):
                pool[r["id"]] = to_record(r)
        time.sleep(1.1)  # AIC asks for ≤60 requests/minute

    print("Adding unstyled works by century…")
    for c in range(-6, 21):
        lo, hi = (c - 1) * 100 + 1, c * 100
        res = post({
            "query": {"bool": {
                "filter": base_filter() + [{"range": {"date_start": {"gte": lo, "lte": hi}}}],
                "must_not": [{"exists": {"field": "style_titles"}}],
                "should": [{"exists": {"field": "description"}}],
            }},
            "fields": FIELDS,
            "limit": PER_ERA_FETCH,
        })
        print(f"  {century_label(hi)}: {res['pagination']['total']} unstyled")
        for r in res["data"]:
            if r.get("image_id") and r["id"] not in pool:
                pool[r["id"]] = to_record(r)
        time.sleep(1.1)

    # Group by normalized style, best candidates (with description) first, then shuffled.
    groups = defaultdict(list)
    for rec in pool.values():
        groups[rec["style"] or f"era:{rec['era']}"].append(rec)
    for recs in groups.values():
        random.shuffle(recs)
        recs.sort(key=lambda x: 0 if x["description"] else 1)
        del recs[MAX_PER_GROUP:]

    # Round-robin across styles so rare styles get represented, big ones fill the rest.
    chosen, order = [], list(groups)
    random.shuffle(order)
    while len(chosen) < args.target and any(groups.values()):
        for s in order:
            if groups[s] and len(chosen) < args.target:
                chosen.append(groups[s].pop(0))

    random.shuffle(chosen)
    counts = defaultdict(int)
    for c in chosen:
        counts[c["style"] or f"(no style) {c['era']}"] += 1

    with open(args.out, "w", encoding="utf-8") as f:
        f.write("// Generated by build_dataset.py from the Art Institute of Chicago API (CC0).\n")
        f.write("window.ARTWORKS = ")
        json.dump(chosen, f, ensure_ascii=False, separators=(",", ":"))
        f.write(";\n")

    with_desc = sum(1 for c in chosen if c["description"])
    print(f"\nWrote {len(chosen)} works to {args.out} — {len(counts)} styles, {with_desc} with descriptions")
    for s, n in sorted(counts.items(), key=lambda kv: -kv[1]):
        print(f"  {n:4d}  {s}")


if __name__ == "__main__":
    main()
