#!/usr/bin/env python3
"""Simulated click-stream generator for nrwbank.de and instagram.com/nrw.bank.

All metrics are SIMULATED. Page URLs and Instagram post shortcodes were taken
from the live site/profile on 2026-11-17; publish dates marked "estimated"
are assumptions.

Usage: python3 generate.py --end 2026-11-16 --days 1 --out clickstream_1day.json
"""
import argparse, json, math, random
from datetime import date, datetime, timedelta, timezone

BASE = "https://www.nrwbank.de"

# (path, title, section, relative daily weight)
PAGES = [
    ("/de/", "Startseite", "home", 100),
    ("/de/foerderung/foerderprodukte/", "Förderprodukte", "foerderung", 60),
    ("/de/privatpersonen/wohneigentum/", "Wohneigentum fördern", "privatpersonen", 45),
    ("/de/privatpersonen/", "Privatpersonen", "privatpersonen", 30),
    ("/de/gruender/", "Gründer", "gruender", 28),
    ("/de/unternehmen/", "Unternehmen", "unternehmen", 26),
    ("/de/foerderung/konditionen/", "Konditionen", "foerderung", 24),
    ("/de/info-und-service/tools-und-rechner/", "Tools und Rechner", "service", 18),
    ("/de/info-und-service/tools-und-rechner/chancenpruefer/", "Chancenprüfer", "service", 14),
    ("/de/privatpersonen/wohneigentum-gestalten/wohneigentum-modernisieren/", "Wohneigentum modernisieren", "privatpersonen", 16),
    ("/de/unternehmen/investitionen-im-mittelstand/digitalisierung/", "Digitalisierung im Mittelstand", "unternehmen", 10),
    ("/de/unternehmen/wohnwirtschaft/wohnungen-bauen/", "Wohnungen bauen", "unternehmen", 12),
    ("/de/oeffentliche-kunden/", "Öffentliche Kunden", "oeffentliche-kunden", 9),
    ("/de/foerderung/formulare/", "Formulare", "foerderung", 15),
    ("/de/die-nrw-bank/karriere/", "Karriere", "nrw-bank", 17),
    ("/de/die-nrw-bank/investor-relations/", "Investor Relations", "nrw-bank", 6),
    ("/de/info-und-service/veranstaltungen/", "Veranstaltungen", "service", 8),
    ("/de/info-und-service/presseinformationen/", "Presseinformationen", "presse", 5),
    ("/de/info-und-service/presseinformationen/2026/261112_PI_deepinvoice.html", "PI: deepinvoice", "presse", 3),
    ("/de/info-und-service/presseinformationen/2026/261109_PI_SRTD-Biotech.html", "PI: SRTD Biotech", "presse", 2),
    ("/de/mut-der-gruendungspreis-nrw/", "MUT – Der Gründungspreis NRW", "gruender", 5),
    ("/de/info-und-service/kontakt/", "Kontakt", "service", 11),
]

# Clickable elements tracked per page (CTA / link categories)
CLICK_TARGETS = ["navigation", "cta_foerderprodukt", "download_pdf", "rechner_start", "kontakt", "external_link"]

# Instagram posts seen on the profile (newest first, pinned ones flagged)
IG_POSTS = [
    ("DaR5gYaEVSL", "reel", "2026-07-01", False, True),
    ("DTM0fmrDFsv", "photo", "2026-01-06", False, True),
    ("DfrGS2mkw__", "reel", "2026-11-12", False, False),
    ("DfpPZlqikRE", "photo", "2026-11-12", False, False),
    ("DfoqtT8Co8c", "photo", "2026-11-12", False, False),
    ("Dfmc9d9jfZY", "photo", "2026-11-11", False, False),
    ("DfkFuWoj1Aj", "photo", "2026-11-10", True, False),
    ("DfhVe-2DzAB", "photo", "2026-11-07", True, False),
    ("Dfg8-q6EUli", "photo", "2026-11-06", True, False),
    ("Dfbpn_BFB4d", "reel", "2026-11-05", True, False),
    ("DfZ6qRCiPIM", "reel", "2026-11-04", True, False),
    ("DfUKviTACn1", "photo", "2026-11-03", True, False),
]
FOLLOWERS_START = 5309

# Hourly traffic profile (CET), sums ~1
HOURLY = [0.6,0.4,0.3,0.3,0.4,0.8,1.6,3.0,5.2,6.8,7.4,7.2,6.0,6.4,6.9,6.6,6.0,5.0,4.2,3.8,3.6,3.1,2.2,1.2]
HOURLY = [h / sum(HOURLY) for h in HOURLY]
IG_HOURLY = [0.5,0.3,0.2,0.2,0.3,0.8,2.5,4.5,5.0,4.2,4.0,4.6,6.5,5.5,4.4,4.2,4.8,6.0,7.4,8.2,8.6,7.5,5.5,2.8]
IG_HOURLY = [h / sum(IG_HOURLY) for h in IG_HOURLY]


def split(total, weights, rng):
    """Split integer total over weights with noise; result sums to total."""
    noisy = [w * rng.uniform(0.85, 1.15) for w in weights]
    s = sum(noisy)
    raw = [total * n / s for n in noisy]
    out = [int(r) for r in raw]
    rest = total - sum(out)
    for i in sorted(range(len(raw)), key=lambda i: raw[i] - out[i], reverse=True)[:rest]:
        out[i] += 1
    return out


def day_factor(d):
    wd = d.weekday()
    return [1.10, 1.05, 1.0, 0.98, 0.85, 0.42, 0.38][wd]


def website_day(d, rng):
    base_pv = 9500
    pageviews = int(base_pv * day_factor(d) * rng.uniform(0.9, 1.1))
    sessions = int(pageviews / rng.uniform(2.6, 3.1))
    visitors = int(sessions * rng.uniform(0.80, 0.88))
    page_pv = split(pageviews, [p[3] for p in PAGES], rng)
    pages, total_clicks = [], 0
    for (path, title, section, _), pv in zip(PAGES, page_pv):
        clicks = int(pv * rng.uniform(0.9, 1.6))
        total_clicks += clicks
        tgt_w = [5, 3 if section in ("foerderung", "privatpersonen", "unternehmen", "gruender") else 0.5,
                 2 if section in ("foerderung", "presse", "nrw-bank") else 0.6,
                 2 if "rechner" in path or "chancen" in path else 0.3,
                 1.2, 0.5]
        pages.append({
            "url": BASE + path,
            "title": title,
            "section": section,
            "pageviews": pv,
            "unique_pageviews": int(pv * rng.uniform(0.72, 0.86)),
            "clicks": clicks,
            "clicks_by_target": dict(zip(CLICK_TARGETS, split(clicks, tgt_w, rng))),
            "avg_time_on_page_s": round(rng.uniform(25, 140), 1),
            "exit_rate": round(rng.uniform(0.15, 0.55), 3),
        })
    pages.sort(key=lambda p: p["pageviews"], reverse=True)
    hourly = split(total_clicks, HOURLY, rng)
    src = split(sessions, [52, 21, 14, 6, 4, 3], rng)
    dev = split(sessions, [58, 37, 5], rng)
    return {
        "totals": {
            "pageviews": pageviews,
            "sessions": sessions,
            "unique_visitors": visitors,
            "clicks": total_clicks,
            "bounce_rate": round(rng.uniform(0.38, 0.48), 3),
            "avg_session_duration_s": round(rng.uniform(140, 210), 1),
        },
        "traffic_sources": dict(zip(["organic_search", "direct", "referral", "social", "paid_search", "email"], src)),
        "social_referrals": {"instagram": int(src[3] * rng.uniform(0.35, 0.5))},
        "devices": dict(zip(["desktop", "mobile", "tablet"], dev)),
        "clicks_by_hour": [{"hour": h, "clicks": c} for h, c in enumerate(hourly)],
        "pages": pages,
    }


TRACK_DAYS = 30  # non-pinned posts are tracked for 30 days after publishing
TOPICS = ["Förderprogramm vorgestellt", "Gründer:innen-Porträt", "Veranstaltungshinweis", "Karriere bei der NRW.BANK",
          "Finanzwissen-Tipp", "Wohneigentum fördern", "Team & Kultur", "Pressemitteilung", "Kunst bei der NRW.BANK",
          "Nachhaltigkeit", "Innovationspartner", "Event-Recap"]


def build_posts(start, rng):
    """Observed posts plus simulated posts filling the window before the oldest observed one."""
    posts = [{"shortcode": c, "media_type": t, "published": d, "pinned": pin,
              "source": "observed" + ("_date_estimated" if est else "")}
             for c, t, d, est, pin in IG_POSTS]
    first_observed = min(date.fromisoformat(p["published"]) for p in posts if not p["pinned"])
    d, n = start - timedelta(days=TRACK_DAYS), 0
    while d < first_observed:
        # ~4 posts/week on weekdays, occasionally 2 per day
        if d.weekday() < 5 and rng.random() < 0.8:
            for _ in range(2 if rng.random() < 0.12 else 1):
                n += 1
                posts.append({"shortcode": f"SIM{d.strftime('%Y%m%d')}{n:03d}",
                              "media_type": "reel" if rng.random() < 0.3 else ("carousel" if rng.random() < 0.3 else "photo"),
                              "published": d.isoformat(), "pinned": False, "source": "simulated",
                              "topic": rng.choice(TOPICS)})
        d += timedelta(days=1)
    posts.sort(key=lambda p: p["published"], reverse=True)
    for p in posts:
        p["url"] = (None if p["source"] == "simulated" else
                    f"https://www.instagram.com/nrw.bank/{'reel' if p['media_type'] == 'reel' else 'p'}/{p['shortcode']}/")
    return posts


def ig_post_day(post, d, rng, state):
    code, ptype, pub, pinned = post["shortcode"], post["media_type"], post["published"], post["pinned"]
    age = (d - date.fromisoformat(pub)).days
    if age < 0 or (age > TRACK_DAYS and not pinned):
        return None
    reach_mult = {"reel": 1.8, "carousel": 1.25}.get(ptype, 1.0) * state.setdefault("q_" + code, rng.uniform(0.6, 1.6))
    # engagement decays with post age; pinned posts keep a small baseline
    decay = math.exp(-age / 2.5) * 70 + (2.0 if pinned else 0.4)
    likes = max(0, int(decay * reach_mult * rng.uniform(0.6, 1.4)))
    c = likes * rng.uniform(0.03, 0.10); comments = int(c) + (rng.random() < c - int(c))
    st = state.setdefault(code, {"likes": 0, "comments": 0, "seeded": False})
    if not st["seeded"]:
        st["seeded"] = True  # seed lifetime totals for posts older than the window
        for a in range(age):
            dd = math.exp(-a / 2.5) * 70 + (2.0 if pinned else 0.4)
            st["likes"] += int(dd * reach_mult)
        st["comments"] = int(st["likes"] * 0.05)
    st["likes"] += likes
    st["comments"] += comments
    impressions = int((likes * rng.uniform(9, 14)) + rng.randint(5, 40))
    return {
        "shortcode": code,
        "url": post["url"],
        "media_type": ptype,
        "source": post["source"],
        "post_age_days": age,
        "likes": likes,
        "comments": comments,
        "likes_total": st["likes"],
        "comments_total": st["comments"],
        "saves": int(likes * rng.uniform(0.03, 0.12)),
        "shares": int(likes * rng.uniform(0.02, 0.10)),
        "impressions": impressions,
        "reach": int(impressions * rng.uniform(0.7, 0.85)),
        "clicks": {
            "profile_visits": int(likes * rng.uniform(0.15, 0.35)),
            "link_in_bio": int(likes * rng.uniform(0.03, 0.08)),
            "tagged_account": int(likes * rng.uniform(0.0, 0.05)),
        },
        **({"plays": int(impressions * rng.uniform(1.1, 1.6))} if ptype == "reel" else {}),
    }


def instagram_day(d, rng, state, catalog):
    posts = [p for p in (ig_post_day(x, d, rng, state) for x in catalog) if p]
    likes = sum(p["likes"] for p in posts)
    comments = sum(p["comments"] for p in posts)
    clicks = sum(sum(p["clicks"].values()) for p in posts)
    state["followers"] = state.get("followers", state["followers_start"]) + rng.randint(-2, 6)
    return {
        "totals": {
            "followers": state["followers"],
            "posts_published": sum(1 for p in posts if p["post_age_days"] == 0),
            "likes": likes,
            "comments": comments,
            "clicks": clicks,
            "impressions": sum(p["impressions"] for p in posts),
            "reach": int(sum(p["reach"] for p in posts) * 0.75),
            "website_clicks": sum(p["clicks"]["link_in_bio"] for p in posts),
        },
        "interactions_by_hour": [{"hour": h, "likes": l} for h, l in enumerate(split(likes, IG_HOURLY, rng))],
        "posts": posts,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--end", default="2026-11-16")
    ap.add_argument("--days", type=int, default=1)
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--out", default="clickstream.json")
    a = ap.parse_args()
    rng = random.Random(a.seed)
    end = date.fromisoformat(a.end)
    start = end - timedelta(days=a.days - 1)
    catalog = build_posts(start, random.Random(a.seed + 1))
    state, days = {}, []
    for i in range(a.days):
        d = start + timedelta(days=i)
        state.setdefault("followers_start", FOLLOWERS_START - 2 * a.days)
        days.append({
            "date": d.isoformat(),
            "weekday": d.strftime("%A"),
            "website": website_day(d, rng),
            "instagram": instagram_day(d, rng, state, catalog),
        })
    doc = {
        "meta": {
            "stream": "nrwbank_clickstream",
            "version": "1.0",
            "simulated": True,
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "timezone": "Europe/Berlin",
            "period": {"start": start.isoformat(), "end": end.isoformat(), "days": a.days},
            "sources": {
                "website": {"domain": "www.nrwbank.de", "tracked_pages": len(PAGES)},
                "instagram": {"account": "@nrw.bank", "profile_url": "https://www.instagram.com/nrw.bank/",
                               "posts": [p for p in catalog
                                         if p["pinned"] or date.fromisoformat(p["published"]) >= start - timedelta(days=TRACK_DAYS)]},
            },
            "notes": "All metrics are simulated. Page URLs and post shortcodes are real as of 2026-11-17; "
                     "publish dates for older posts are estimated. Posts with source 'simulated' (shortcode SIM…) "
                     "are invented to fill the period before the oldest observed post and have no URL.",
        },
        "days": days,
    }
    with open(a.out, "w", encoding="utf-8") as f:
        json.dump(doc, f, ensure_ascii=False, indent=2)
    print(f"wrote {a.out}: {a.days} day(s)")


if __name__ == "__main__":
    main()
