#!/usr/bin/env python3
"""Collect recent (2000+) paintings from Wikimedia Commons under free licenses
(CC0 / public domain / CC BY / CC BY-SA), with artist attribution, and download images.

Output:
  artworks_commons.js       window.ARTWORKS_COMMONS = [...]
  images/wc-<pageid>.jpg    resized local copies (resumable)

Usage:  python3 build_commons.py [--target 500] [--per-artist 4] [--skip-images]

Each record keeps: artist, license name + URL, the Commons file page, and a ready-made
attribution line. CC BY / CC BY-SA require that credit wherever the image is shown.
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
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict

API = "https://commons.wikimedia.org/w/api.php"
UA = {"User-Agent": "art-inspiration-sampler/1.0 (personal, non-commercial project)"}
QUERIES = [
    'deepcat:"2020s paintings" filetype:bitmap',
    'deepcat:"2010s paintings" filetype:bitmap',
    'deepcat:"2000s paintings" filetype:bitmap',
]
THUMB_WIDTH = 960      # a standard Wikimedia thumbnail size
MIN_SIDE = 700         # skip tiny images
LICENSE_OK = re.compile(r"^(CC0|Public domain|PD\b|CC BY(-SA)? \d(\.\d)?( [a-z]{2,3})?$)", re.I)
# Photos of walls/streets (the photographer, not the painter, holds the license) and other non-paintings.
EXCLUDE = re.compile(
    r"mural|graffiti|street.?art|wall.?painting|wandbild|murales|fresco|facade|fassade|painted (door|wall|house|bus|car)|"
    r"building|house|station|bridge|tunnel|underpass|exhibition|installation|ausstellung|museum|gallery|"
    r"vernissage|sculpture|statue|model of|ai[- ]generated|midjourney|stable diffusion|dall-?e|"
    r"body ?paint|tattoo|nail art|face ?paint|sign|logo|poster|screenshot",
    re.I)
STYLE_WORDS = [
    ("Pop art", r"\bpop.?art\b"), ("Street-influenced", r"\burban art\b|\blowbrow\b"),
    ("Abstract", r"\babstract"), ("Expressionism", r"expressionis"), ("Surrealism", r"surreal"),
    ("Cubism", r"cubis"), ("Impressionism", r"impressionis"), ("Hyperrealism", r"hyperreal|photoreal"),
    ("Realism", r"\brealis"), ("Naïve art", r"na[iï]ve art|\bnaive\b"), ("Minimalism", r"minimalis"),
    ("Symbolism", r"symbolis"), ("Fantasy art", r"fantas"), ("Digital painting", r"digital (art|painting)"),
    ("Portrait", r"portrait"), ("Landscape", r"landscape|seascape|cityscape"), ("Still life", r"still.?life"),
    ("Figurative", r"figurative|\bnudes?\b"), ("Watercolor", r"watercolou?r|aquarel"),
]


def get_json(params, retries=4):
    url = API + "?" + urllib.parse.urlencode({**params, "format": "json", "maxlag": 5})
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=90) as r:
                data = json.load(r)
            if "error" in data and data["error"].get("code") == "maxlag":
                raise RuntimeError("maxlag")
            return data
        except Exception as e:
            wait = 5 * 2 ** attempt
            print(f"  request failed ({e}); retrying in {wait}s", file=sys.stderr, flush=True)
            time.sleep(wait)
    raise RuntimeError("Commons API failed repeatedly")


def clean(s):
    if not s:
        return ""
    s = html.unescape(re.sub(r"<[^>]+>", " ", s))
    return re.sub(r"\s+", " ", s).strip()


def meta(m, key):
    return clean((m.get(key) or {}).get("value", ""))


def find_year(m, cats):
    for c in cats:
        mm = re.match(r"^(20[0-2]\d) (paintings|works|in art)", c)
        if mm:
            return int(mm.group(1))
    for key in ("DateTimeOriginal", "ObjectName", "ImageDescription"):
        mm = re.search(r"\b(20[0-2]\d)\b", meta(m, key))
        if mm:
            return int(mm.group(1))
    return None


def title_from(page_title, m):
    name = meta(m, "ObjectName")
    if not name or re.fullmatch(r"[\w\- .()]+\.(jpe?g|png|tiff?)", name, re.I):
        name = re.sub(r"\.(jpe?g|png|tiff?|webp)$", "", page_title.split(":", 1)[1], flags=re.I)
        name = name.replace("_", " ")
    return name[:140]


def standard_thumb(ii):
    """Wikimedia rate-limits originals and non-standard sizes; use a standard thumbnail width."""
    url = ii["thumburl"]
    if "/thumb/" in url:
        return url
    m = re.match(r"https://upload\.wikimedia\.org(/wikipedia/commons)/(\w/\w\w)/([^?]+)", ii.get("url", ""))
    if m and max(ii.get("width", 0), ii.get("height", 0)) >= 500:
        return f"https://upload.wikimedia.org{m.group(1)}/thumb/{m.group(2)}/{m.group(3)}/500px-{m.group(3)}"
    return url


def to_record(p):
    ii = (p.get("imageinfo") or [{}])[0]
    m = ii.get("extmetadata") or {}
    lic = meta(m, "LicenseShortName")
    if not LICENSE_OK.match(lic):
        return None, "license"
    w, h = ii.get("width") or 0, ii.get("height") or 0
    if max(w, h) < MIN_SIDE or not ii.get("thumburl"):
        return None, "size"
    cats = [c.strip() for c in meta(m, "Categories").split("|") if c.strip()]
    artist = meta(m, "Artist")
    hay = " ".join([p["title"], meta(m, "ObjectName"), meta(m, "ImageDescription")[:300], " ".join(cats)])
    if EXCLUDE.search(hay):
        return None, "not a painting"
    # "Painter: X; photo: Y" style credits mean someone else photographed the work: skip.
    if re.search(r"\b(photo|photograph|image|foto|fotograf)\w*\s*[:by]", artist, re.I):
        return None, "photographer credit"
    if not artist or artist.lower() in ("unknown", "anonymous") or len(artist) > 120:
        return None, "no artist"
    year = find_year(m, cats)
    if not year or year < 2000:
        return None, "date"
    artist = re.sub(r"^(painter|painting|artist|author)\s*:\s*", "", artist, flags=re.I)
    tags = []
    for label, pat in STYLE_WORDS:
        if re.search(pat, " ".join(cats) + " " + p["title"], re.I) and label not in tags:
            tags.append(label)
    title = title_from(p["title"], m)
    lic_url = meta(m, "LicenseUrl") or ("https://creativecommons.org/publicdomain/zero/1.0/" if "CC0" in lic else "")
    page = ii.get("descriptionurl") or f"https://commons.wikimedia.org/wiki/{urllib.parse.quote(p['title'].replace(' ', '_'))}"
    desc = meta(m, "ImageDescription")
    if desc and (desc.lower() == title.lower() or len(desc) < 25):
        desc = ""
    return {
        "id": f"wc-{p['pageid']}",
        "source": "commons",
        "title": title,
        "artist": artist,
        "date": str(year),
        "year": year,
        "era": "21st century",
        "style": tags[0] if tags else "Contemporary",
        "tags": tags or ["Contemporary"],
        "type": "Painting",
        "medium": "",
        "origin": "",
        "description": desc[:1200],
        "alt": title,
        "w": w, "h": h,
        "remote": standard_thumb(ii),
        "url": page,
        "license": lic,
        "license_url": lic_url,
        "attribution": f"“{title}” by {artist}, {lic}, via Wikimedia Commons",
    }, None


def collect(per_query_max):
    pool, reasons = {}, defaultdict(int)
    for q in QUERIES:
        offset, seen = 0, 0
        print(f"Searching: {q}", flush=True)
        while offset is not None and seen < per_query_max:
            data = get_json({
                "action": "query", "generator": "search", "gsrnamespace": 6, "gsrlimit": 50,
                "gsrsearch": q, "gsroffset": offset,
                "prop": "imageinfo", "iiprop": "extmetadata|url|size", "iiurlwidth": THUMB_WIDTH,
                "iiextmetadatafilter": "LicenseShortName|LicenseUrl|Artist|DateTimeOriginal|ObjectName|ImageDescription|Categories",
            })
            pages = (data.get("query") or {}).get("pages", {})
            for p in pages.values():
                seen += 1
                rec, why = to_record(p)
                if rec:
                    pool[rec["id"]] = rec
                else:
                    reasons[why] += 1
            offset = (data.get("continue") or {}).get("gsroffset")
            print(f"  scanned {seen}, kept so far {len(pool)}", flush=True)
            time.sleep(1)
    print("Rejected:", dict(reasons), flush=True)
    return list(pool.values())


def download_images(works, out_dir, max_side=900, delay=0.7):
    from PIL import Image
    os.makedirs(out_dir, exist_ok=True)
    todo = [w for w in works if not os.path.exists(os.path.join(out_dir, f"{w['id']}.jpg"))]
    print(f"Images: {len(works) - len(todo)} present, {len(todo)} to download", flush=True)
    failed = []
    for i, w in enumerate(todo, 1):
        dest = os.path.join(out_dir, f"{w['id']}.jpg")
        try:
            for attempt in range(4):
                try:
                    with urllib.request.urlopen(urllib.request.Request(w["remote"], headers=UA), timeout=90) as r:
                        raw = r.read()
                    break
                except urllib.error.HTTPError as e:
                    if e.code != 429 or attempt == 3:
                        raise
                    time.sleep(30 * (attempt + 1))  # rate limited: back off
            im = Image.open(io.BytesIO(raw)).convert("RGB")
            im.thumbnail((max_side, max_side))
            im.save(dest + ".part", "JPEG", quality=82, optimize=True, progressive=True)
            os.replace(dest + ".part", dest)
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
    ap.add_argument("--target", type=int, default=500)
    ap.add_argument("--per-artist", type=int, default=4)
    ap.add_argument("--per-query", type=int, default=3000, help="max files scanned per category query")
    ap.add_argument("--seed", type=int, default=5)
    ap.add_argument("--out", default="artworks_commons.js")
    ap.add_argument("--images", default="images")
    ap.add_argument("--skip-images", action="store_true")
    args = ap.parse_args()
    random.seed(args.seed)

    pool = collect(args.per_query)

    # Cap works per artist, then round-robin across styles for variety.
    by_artist = defaultdict(list)
    for r in pool:
        by_artist[r["artist"].lower()].append(r)
    capped = []
    for recs in by_artist.values():
        random.shuffle(recs)
        capped += recs[:args.per_artist]
    groups = defaultdict(list)
    for r in capped:
        groups[r["style"]].append(r)
    for g in groups.values():
        random.shuffle(g)
    chosen, order = [], list(groups)
    random.shuffle(order)
    while len(chosen) < args.target and any(groups.values()):
        for k in order:
            if groups[k] and len(chosen) < args.target:
                chosen.append(groups[k].pop())
    random.shuffle(chosen)

    with open(args.out, "w", encoding="utf-8") as f:
        f.write("// Generated by build_commons.py from Wikimedia Commons. Licenses vary per work; see each record.\n")
        f.write("window.ARTWORKS_COMMONS = ")
        json.dump(chosen, f, ensure_ascii=False, separators=(",", ":"))
        f.write(";\n")

    lic, sty = defaultdict(int), defaultdict(int)
    for c in chosen:
        lic[re.sub(r" \d.*", "", c["license"])] += 1
        sty[c["style"]] += 1
    print(f"\nWrote {len(chosen)} works to {args.out} from {len(pool)} candidates, "
          f"{len({c['artist'] for c in chosen})} artists")
    print("Licenses:", dict(lic))
    print("Styles:", dict(sorted(sty.items(), key=lambda kv: -kv[1])))

    if not args.skip_images:
        failed = download_images(chosen, args.images)
        print(f"Images done; {len(failed)} failed")


if __name__ == "__main__":
    main()
