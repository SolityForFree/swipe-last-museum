#!/usr/bin/env python3
"""Download the images listed in artworks.js into ./images/<id>.jpg.

- 843px wide: the size the Art Institute of Chicago caches and recommends.
- Resumable: files that already exist are skipped, so you can stop and rerun.
- Polite: about one request per second. If the server starts blocking
  (403 / 429 / 5xx), it waits and retries instead of hammering it.

Usage:  python3 download_images.py [--width 843] [--delay 1.0]
"""
import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

HEADERS = {
    "User-Agent": "Mozilla/5.0 (art-inspiration-sampler; personal project)",
    "AIC-User-Agent": "art-inspiration-sampler (personal project)",
}
BLOCK_WAIT = [60, 120, 300, 600, 900]  # seconds to wait after repeated blocks


def load_artworks(path):
    text = open(path, encoding="utf-8").read()
    return json.loads(text.split("=", 1)[1].strip().rstrip(";"))


def fetch(url):
    req = urllib.request.Request(url, headers=HEADERS)
    with urllib.request.urlopen(req, timeout=60) as r:
        if not r.headers.get("Content-Type", "").startswith("image/"):
            raise urllib.error.HTTPError(url, 403, "not an image (challenge page?)", r.headers, None)
        return r.read()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", default="artworks.js")
    ap.add_argument("--out", default="images")
    ap.add_argument("--width", type=int, default=843)
    ap.add_argument("--delay", type=float, default=1.0)
    args = ap.parse_args()

    works = load_artworks(args.data)
    os.makedirs(args.out, exist_ok=True)
    todo = [w for w in works if not os.path.exists(os.path.join(args.out, f"{w['id']}.jpg"))]
    print(f"{len(works)} works, {len(works) - len(todo)} already downloaded, {len(todo)} to go", flush=True)

    blocks, failed = 0, []
    i = 0
    while i < len(todo):
        w = todo[i]
        url = f"https://www.artic.edu/iiif/2/{w['image_id']}/full/{args.width},/0/default.jpg"
        dest = os.path.join(args.out, f"{w['id']}.jpg")
        try:
            data = fetch(url)
        except urllib.error.HTTPError as e:
            if e.code in (403, 429) or e.code >= 500:
                if blocks >= len(BLOCK_WAIT):
                    print(f"Still blocked after {len(BLOCK_WAIT)} waits; stopping. Rerun later to resume.", flush=True)
                    break
                wait = BLOCK_WAIT[blocks]
                blocks += 1
                print(f"  HTTP {e.code} — server is blocking; waiting {wait}s (attempt {blocks}/{len(BLOCK_WAIT)})", flush=True)
                time.sleep(wait)
                continue  # retry same image
            print(f"  skip {w['id']}: HTTP {e.code}", flush=True)
            failed.append(w["id"])
            i += 1
            continue
        except Exception as e:  # timeouts etc.
            print(f"  skip {w['id']}: {e}", flush=True)
            failed.append(w["id"])
            i += 1
            time.sleep(args.delay * 3)
            continue

        tmp = dest + ".part"
        with open(tmp, "wb") as f:
            f.write(data)
        os.replace(tmp, dest)
        blocks = 0
        i += 1
        if i % 25 == 0 or i == len(todo):
            print(f"  {i}/{len(todo)} downloaded", flush=True)
        time.sleep(args.delay)

    have = sum(1 for w in works if os.path.exists(os.path.join(args.out, f"{w['id']}.jpg")))
    print(f"Done: {have}/{len(works)} images in {args.out}/" + (f"; {len(failed)} failed: {failed}" if failed else ""), flush=True)
    sys.exit(0 if have == len(works) else 1)


if __name__ == "__main__":
    main()
