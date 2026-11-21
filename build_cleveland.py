#!/usr/bin/env python3
"""Build a balanced set of public-domain (CC0) works from the Cleveland Museum of Art
Open Access API and download their images.

Output:
  artworks_cma.js          window.ARTWORKS_CMA = [...]   (same record shape as artworks.js)
  images/cma-<id>.jpg      resized local copies (resumable: existing files are skipped)

Usage:  python3 build_cleveland.py [--target 1000] [--skip-images]
Data:   https://openaccess-api.clevelandart.org/  (CC0)
"""
import argparse
import html
import io
import json
import os
import random
import re
import sys
import time
import urllib.parse
import urllib.request
from collections import defaultdict

API = "https://openaccess-api.clevelandart.org/api/artworks/"
UA = {"User-Agent": "Mozilla/5.0 (art-inspiration-sampler; personal project)"}
FIELDS = ("id,title,creation_date,creation_date_earliest,culture,technique,department,type,"
          "description,did_you_know,creators,images,url,external_resources,tombstone")
TYPES = {"Painting": 120, "Print": 50, "Drawing": 50}  # candidates per type per era bucket
ERAS = [(-3000, -1), (0, 999), (1000, 1299), (1300, 1399), (1400, 1499), (1500, 1599),
        (1600, 1699), (1700, 1799), (1800, 1849), (1850, 1899), (1900, 1930)]
MAX_PER_GROUP = 70

# Department -> culture label, named like the Chicago styles so the page filter merges them.
DEPT_STYLE = {
    "Japanese Art": "Japanese", "Chinese Art": "Chinese", "Korean Art": "Korean",
    "Indian and Southeast Asian Art": "South Asian", "Islamic Art": "Islamic",
    "Medieval Art": "Medieval", "African Art": "African", "Art of the Americas": "Arts of the Americas",
    "Egyptian and Ancient Near Eastern Art": "Egyptian", "Greek and Roman Art": "Greek and Roman",
    "Oceania": "Oceanic",
}


def get_json(url, retries=4):
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=90) as r:
                return json.load(r)
        except Exception as e:
            wait = 3 * 2 ** attempt
            print(f"  request failed ({e}); retrying in {wait}s", file=sys.stderr, flush=True)
            time.sleep(wait)
    raise RuntimeError(f"failed: {url}")


def ordinal(n):
    return f"{n}{'th' if 10 <= n % 100 <= 20 else {1: 'st', 2: 'nd', 3: 'rd'}.get(n % 10, 'th')}"


def century_label(year):
    if year is None:
        return None
    if year <= 0:
        return f"{ordinal(abs(year) // 100 + 1)} century BCE"
    return f"{ordinal((year - 1) // 100 + 1)} century"


def clean(s):
    if not s:
        return ""
    s = html.unescape(re.sub(r"<[^>]+>", " ", s))
    return re.sub(r"\s+", " ", s).strip()


def wikidata_movements(qids):
    """Return {qid: [movement labels]} using the Wikidata 'movement' property (P135)."""
    out = defaultdict(list)
    qids = sorted(set(qids))
    for i in range(0, len(qids), 200):
        values = " ".join(f"wd:{q}" for q in qids[i:i + 200])
        q = f"""SELECT ?item ?mLabel WHERE {{ VALUES ?item {{ {values} }}
                 ?item wdt:P135 ?m. SERVICE wikibase:label {{ bd:serviceParam wikibase:language "en". }} }}"""
        url = "https://query.wikidata.org/sparql?" + urllib.parse.urlencode({"query": q, "format": "json"})
        try:
            data = get_json(url)
        except RuntimeError:
            print("  Wikidata lookup failed; continuing without movements", file=sys.stderr)
            continue
        for b in data["results"]["bindings"]:
            qid = b["item"]["value"].rsplit("/", 1)[1]
            label = b["mLabel"]["value"]
            if not re.fullmatch(r"Q\d+", label):
                out[qid].append(label[:1].upper() + label[1:])
        time.sleep(1)
    return out


def to_record(r, movements):
    qid = next((u.rsplit("/", 1)[1] for u in (r.get("external_resources") or {}).get("wikidata", [])), None)
    moves = movements.get(qid, []) if qid else []
    dept_style = DEPT_STYLE.get(r.get("department"))
    tags = list(dict.fromkeys(moves + ([dept_style] if dept_style else [])))
    year = r.get("creation_date_earliest")
    creators = [clean(c.get("description")) for c in (r.get("creators") or []) if c.get("description")]
    culture = ", ".join(r.get("culture") or [])
    desc = clean(r.get("description"))
    fact = clean(r.get("did_you_know"))
    web = (r.get("images") or {}).get("web") or {}
    return {
        "id": f"cma-{r['id']}",
        "source": "cma",
        "title": clean(r.get("title")) or "Untitled",
        "artist": "; ".join(creators) or (culture or "Unknown artist"),
        "date": r.get("creation_date") or "",
        "year": year,
        "era": century_label(year),
        "style": tags[0] if tags else None,
        "tags": tags,
        "type": {"Drawing": "Drawing and Watercolor"}.get(r.get("type"), r.get("type")),
        "medium": clean(r.get("technique")),
        "origin": culture,
        "description": desc,
        "fact": fact if fact and fact != desc else "",
        "alt": clean(r.get("title")),
        "w": int(web.get("width") or 0) or None,
        "h": int(web.get("height") or 0) or None,
        "remote": web.get("url"),
        "url": r.get("url"),
        "_dept": r.get("department"),
    }


def download_images(works, out_dir, max_side=900, delay=0.5):
    from PIL import Image  # Pillow
    os.makedirs(out_dir, exist_ok=True)
    todo = [w for w in works if not os.path.exists(os.path.join(out_dir, f"{w['id']}.jpg"))]
    print(f"Images: {len(works) - len(todo)} present, {len(todo)} to download", flush=True)
    failed = []
    for i, w in enumerate(todo, 1):
        dest = os.path.join(out_dir, f"{w['id']}.jpg")
        try:
            with urllib.request.urlopen(urllib.request.Request(w["remote"], headers=UA), timeout=90) as r:
                raw = r.read()
            im = Image.open(io.BytesIO(raw))
            im = im.convert("RGB")
            im.thumbnail((max_side, max_side))
            tmp = dest + ".part"
            im.save(tmp, "JPEG", quality=82, optimize=True, progressive=True)
            os.replace(tmp, dest)
        except Exception as e:
            print(f"  skip {w['id']}: {e}", flush=True)
            failed.append(w["id"])
            time.sleep(delay * 4)
            continue
        if i % 50 == 0 or i == len(todo):
            print(f"  {i}/{len(todo)} images", flush=True)
        time.sleep(delay)
    return failed


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--target", type=int, default=1000)
    ap.add_argument("--seed", type=int, default=11)
    ap.add_argument("--out", default="artworks_cma.js")
    ap.add_argument("--images", default="images")
    ap.add_argument("--skip-images", action="store_true")
    args = ap.parse_args()
    random.seed(args.seed)

    pool = {}
    for lo, hi in ERAS:
        for t, n in TYPES.items():
            base = {"cc0": 1, "has_image": 1, "type": t, "created_after": lo, "created_before": hi}
            total = get_json(API + "?" + urllib.parse.urlencode({**base, "limit": 1, "fields": "id"}))["info"]["total"]
            skip = random.randint(0, max(0, total - n))  # random window, not always the first page
            q = urllib.parse.urlencode({**base, "limit": n, "skip": skip, "fields": FIELDS})
            data = get_json(API + "?" + q)
            got = [r for r in data["data"] if ((r.get("images") or {}).get("web") or {}).get("url")]
            print(f"{lo:>5}–{hi:<5} {t:<9} {data['info']['total']:>5} available, took {len(got)}", flush=True)
            for r in got:
                pool[r["id"]] = r
            time.sleep(0.5)

    qids = [u.rsplit("/", 1)[1] for r in pool.values()
            for u in (r.get("external_resources") or {}).get("wikidata", [])]
    print(f"Looking up art movements for {len(qids)} works on Wikidata…", flush=True)
    movements = wikidata_movements(qids)
    records = [to_record(r, movements) for r in pool.values()]

    # Group by style if known, otherwise department + century; round-robin for variety.
    groups = defaultdict(list)
    for rec in records:
        groups[rec["style"] or f"{rec['_dept']}|{rec['era']}"].append(rec)
    for g in groups.values():
        random.shuffle(g)
        g.sort(key=lambda x: 0 if x["description"] else 1)
        del g[MAX_PER_GROUP:]
    chosen, order = [], list(groups)
    random.shuffle(order)
    while len(chosen) < args.target and any(groups.values()):
        for k in order:
            if groups[k] and len(chosen) < args.target:
                chosen.append(groups[k].pop(0))
    for c in chosen:
        c.pop("_dept", None)
    random.shuffle(chosen)

    with open(args.out, "w", encoding="utf-8") as f:
        f.write("// Generated by build_cleveland.py from the Cleveland Museum of Art Open Access API (CC0).\n")
        f.write("window.ARTWORKS_CMA = ")
        json.dump(chosen, f, ensure_ascii=False, separators=(",", ":"))
        f.write(";\n")

    styles = defaultdict(int)
    for c in chosen:
        styles[c["style"] or f"(no style) {c['era']}"] += 1
    print(f"\nWrote {len(chosen)} works to {args.out}; {sum(1 for c in chosen if c['description'])} with descriptions; "
          f"{sum(1 for c in chosen if c['style'])} with a style label")
    for s, n in sorted(styles.items(), key=lambda kv: -kv[1])[:30]:
        print(f"  {n:4d}  {s}")

    if not args.skip_images:
        failed = download_images(chosen, args.images)
        print(f"Images done; {len(failed)} failed" + (f": {failed}" if failed else ""))


if __name__ == "__main__":
    main()
