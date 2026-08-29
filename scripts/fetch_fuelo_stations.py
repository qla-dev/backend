#!/usr/bin/env python3
"""Fetch Fuelo stations as NDJSON for the Laravel import command."""

import argparse
import json
import sys
import time

import cloudscraper


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument("--south", type=float, required=True)
    parser.add_argument("--west", type=float, required=True)
    parser.add_argument("--north", type=float, required=True)
    parser.add_argument("--east", type=float, required=True)
    parser.add_argument("--zoom", type=int, default=14)
    parser.add_argument("--step", type=float, default=0.3)
    parser.add_argument("--delay", type=float, default=1.5)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--endpoint", required=True)
    return parser.parse_args()


def tiles(args):
    latitude = args.south
    while latitude < args.north:
        longitude = args.west
        while longitude < args.east:
            yield (
                min(latitude + args.step, args.north),
                min(longitude + args.step, args.east),
                latitude,
                longitude,
            )
            longitude += args.step
        latitude += args.step


def records(payload):
    if isinstance(payload, list):
        return payload
    if isinstance(payload, dict):
        for key in ("data", "items", "markers", "gasstations", "stations"):
            if isinstance(payload.get(key), list):
                return payload[key]
    return []


def main():
    args = parse_args()
    scraper = cloudscraper.create_scraper(
        browser={"browser": "chrome", "platform": "windows", "desktop": True}
    )
    headers = {
        "Accept": "application/json, text/javascript, */*; q=0.01",
        "X-Requested-With": "XMLHttpRequest",
        "Referer": args.base_url,
    }

    print("Initializing Fuelo session...", file=sys.stderr, flush=True)
    scraper.get(args.base_url, timeout=30)
    time.sleep(min(args.delay, 2))

    tile_list = list(tiles(args))
    emitted = set()
    successful_tiles = 0

    for index, (nelat, nelng, swlat, swlng) in enumerate(tile_list, start=1):
        params = {
            "nelat": nelat,
            "nelng": nelng,
            "swlat": swlat,
            "swlng": swlng,
            "zoom": args.zoom,
        }

        response = None
        for _attempt in range(2):
            try:
                response = scraper.get(args.endpoint, params=params, headers=headers, timeout=30)
            except Exception as error:
                print(f"[{index}/{len(tile_list)}] Fetch error: {error}", file=sys.stderr, flush=True)
                response = None
                break

            if response.status_code != 403:
                break

            print(
                f"[{index}/{len(tile_list)}] Blocked (403); refreshing session before retry.",
                file=sys.stderr,
                flush=True,
            )
            time.sleep(10)
            scraper.get(args.base_url, timeout=30)

        if response is not None and response.status_code == 200:
            try:
                items = records(response.json())
            except ValueError:
                items = []
                print(f"[{index}/{len(tile_list)}] Invalid JSON response.", file=sys.stderr, flush=True)

            successful_tiles += 1
            emitted_now = 0
            for item in items:
                if not isinstance(item, dict):
                    continue
                station_id = item.get("id") or item.get("gasstation_id")
                if station_id is None or str(station_id) in emitted:
                    continue
                emitted.add(str(station_id))
                print(json.dumps(item, ensure_ascii=False, separators=(",", ":")), flush=True)
                emitted_now += 1
            print(
                f"[{index}/{len(tile_list)}] Received {len(items)} records; emitted {emitted_now} new stations.",
                file=sys.stderr,
                flush=True,
            )
        elif response is not None:
            print(f"[{index}/{len(tile_list)}] HTTP {response.status_code}", file=sys.stderr, flush=True)

        if args.delay:
            time.sleep(args.delay)

    if successful_tiles == 0:
        print("No Fuelo tile request succeeded.", file=sys.stderr, flush=True)
        return 1

    print(
        f"FUELO_FETCH_COMPLETE successful_tiles={successful_tiles} emitted={len(emitted)}",
        file=sys.stderr,
        flush=True,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
