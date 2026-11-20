#!/usr/bin/env python3
"""Profile-driven simulated click-stream generator (website + Instagram), etracker-style.

A profile (JSON, see profiles/) describes one brand: tracked pages, click targets, traffic
shape, seasonal events and the Instagram account with its observed posts. All metrics are
SIMULATED; only page URLs and observed post shortcodes/dates come from the live channels.

Usage:
  python3 generate.py --profile profiles/eram.json --end 2026-11-19 --days 92 --out eram_clickstream_3months.json
"""
import argparse, json, math, random
from datetime import date, datetime, timedelta, timezone

TRACK_DAYS = 30  # non-pinned posts are tracked for 30 days after publishing


def norm(weights):
    s = sum(weights)
    return [w / s for w in weights]


def split(total, weights, rng):
    """Split an integer total over weights with noise; the parts sum to total."""
    noisy = [w * rng.uniform(0.85, 1.15) for w in weights]
    s = sum(noisy) or 1
    raw = [total * n / s for n in noisy]
    out = [int(r) for r in raw]
    for i in sorted(range(len(raw)), key=lambda i: raw[i] - out[i], reverse=True)[:total - sum(out)]:
        out[i] += 1
    return out


def in_range(d, ev):
    return date.fromisoformat(ev["from"]) <= d <= date.fromisoformat(ev["to"])


# --------------------------------------------------------------------------- website

def website_day(d, p, rng):
    w = p["website"]
    factor = w["weekday_factors"][d.weekday()]
    for ev in w.get("events", []):
        if in_range(d, ev) and not ev.get("match"):
            factor *= ev["factor"]
    pageviews = int(w["base_pageviews"] * factor * rng.uniform(0.9, 1.1))
    sessions = int(pageviews / rng.uniform(*w["pages_per_session"]))
    visitors = int(sessions * rng.uniform(*w["visitors_per_session"]))

    weights = []
    for path, _, _, weight in w["pages"]:
        for ev in w.get("events", []):
            if ev.get("match") and ev["match"] in path and in_range(d, ev):
                weight *= ev["factor"]
        weights.append(weight)
    page_pv = split(pageviews, weights, rng)

    targets = w["click_targets"]
    pages, total_clicks = [], 0
    for (path, title, section, _), pv in zip(w["pages"], page_pv):
        cpp = w.get("clicks_per_pageview_by_section", {}).get(section, w["clicks_per_pageview"])
        clicks = int(pv * rng.uniform(*cpp))
        total_clicks += clicks
        tw = w["target_weights"].get(section, w["target_weights"]["default"])
        pages.append({
            "url": "https://" + w["domain"] + path,
            "title": title,
            "section": section,
            "pageviews": pv,
            "unique_pageviews": int(pv * rng.uniform(0.72, 0.86)),
            "clicks": clicks,
            "clicks_by_target": dict(zip(targets, split(clicks, tw, rng))),
            "avg_time_on_page_s": round(rng.uniform(*w["time_on_page_s"].get(section, w["time_on_page_s"]["default"])), 1),
            "exit_rate": round(rng.uniform(0.15, 0.55), 3),
        })
    pages.sort(key=lambda x: x["pageviews"], reverse=True)

    src_names = list(w["traffic_sources"].keys())
    src = split(sessions, list(w["traffic_sources"].values()), rng)
    social_idx = src_names.index("social")
    return {
        "totals": {
            "pageviews": pageviews,
            "sessions": sessions,
            "unique_visitors": visitors,
            "clicks": total_clicks,
            "bounce_rate": round(rng.uniform(*w["bounce_rate"]), 3),
            "avg_session_duration_s": round(rng.uniform(*w["session_duration_s"]), 1),
        },
        "traffic_sources": dict(zip(src_names, src)),
        "social_referrals": {"instagram": int(src[social_idx] * rng.uniform(*w["instagram_share_of_social"]))},
        "devices": dict(zip(w["devices"].keys(), split(sessions, list(w["devices"].values()), rng))),
        "clicks_by_hour": [{"hour": h, "clicks": c} for h, c in enumerate(split(total_clicks, norm(w["hourly"]), rng))],
        "pages": pages,
    }


# --------------------------------------------------------------------------- instagram

def build_posts(p, start, end, rng):
    """Observed posts plus simulated posts filling the window before the oldest observed one."""
    ig = p["instagram"]
    posts = []
    for o in ig["observed_posts"]:
        if date.fromisoformat(o["published"]) > end:
            continue  # published after the stream ends
        posts.append({"shortcode": o["shortcode"], "media_type": o["media_type"], "published": o["published"],
                      "pinned": o.get("pinned", False),
                      "source": "observed" + ("_date_estimated" if o.get("date_estimated") else ""),
                      **({"topic": o["topic"]} if o.get("topic") else {})})
    first_observed = min(date.fromisoformat(x["published"]) for x in posts if not x["pinned"])

    media, mix = list(ig["media_mix"].keys()), list(ig["media_mix"].values())
    topics = ig["topics"]
    d, n = start - timedelta(days=TRACK_DAYS), 0
    while d < first_observed:
        chance = ig["post_probability"][d.weekday()]
        count = (1 if rng.random() < chance else 0) + (1 if rng.random() < ig.get("double_post_probability", 0) else 0)
        for _ in range(count):
            n += 1
            active = [t for t in topics if t.get("from", "0000") <= d.isoformat() <= t.get("to", "9999")]
            topic = rng.choices([t["name"] for t in active], [t["weight"] for t in active])[0]
            posts.append({"shortcode": f"SIM{d.strftime('%Y%m%d')}{n:03d}",
                          "media_type": rng.choices(media, mix)[0],
                          "published": d.isoformat(), "pinned": False, "source": "simulated", "topic": topic})
        d += timedelta(days=1)

    posts.sort(key=lambda x: x["published"], reverse=True)
    for x in posts:
        x["url"] = (None if x["source"] == "simulated" else
                    f"{ig['profile_url']}{'reel' if x['media_type'] == 'reel' else 'p'}/{x['shortcode']}/")
    return posts


def expected_likes(ig, age, pinned, mult):
    e = ig["engagement"]
    return (math.exp(-age / e["decay_days"]) * e["day0_likes"] + (e["pinned_baseline"] if pinned else e["tail_baseline"])) * mult


def ig_post_day(post, d, ig, rng, state):
    age = (d - date.fromisoformat(post["published"])).days
    if age < 0 or (age > TRACK_DAYS and not post["pinned"]):
        return None
    e = ig["engagement"]
    code, ptype = post["shortcode"], post["media_type"]
    boost = next((t for t in ig["topics"] if t["name"] == post.get("topic")), {})
    mult = e["media_multiplier"].get(ptype, 1.0) * boost.get("likes", 1.0) \
        * state.setdefault("q_" + code, rng.uniform(*e["quality_range"]))

    likes = max(0, int(expected_likes(ig, age, post["pinned"], mult) * rng.uniform(0.7, 1.3)))
    c = likes * rng.uniform(*e["comment_rate"]) * boost.get("comments", 1.0)
    comments = int(c) + (rng.random() < c - int(c))

    st = state.setdefault(code, {"likes": 0, "comments": 0, "seeded": False})
    if not st["seeded"]:  # lifetime totals accumulated before the stream window
        st["seeded"] = True
        st["likes"] = sum(int(expected_likes(ig, a, post["pinned"], mult)) for a in range(age))
        st["comments"] = int(st["likes"] * sum(e["comment_rate"]) / 2 * boost.get("comments", 1.0))
    st["likes"] += likes
    st["comments"] += comments

    impressions = int(likes * rng.uniform(*e["impressions_per_like"]) + rng.randint(5, 60))
    result = {
        "shortcode": code,
        "url": post["url"],
        "media_type": ptype,
        "source": post["source"],
        "post_age_days": age,
        "likes": likes,
        "comments": comments,
        "likes_total": st["likes"],
        "comments_total": st["comments"],
        "saves": int(likes * rng.uniform(*e["save_rate"])),
        "shares": int(likes * rng.uniform(*e["share_rate"])),
        "impressions": impressions,
        "reach": int(impressions * rng.uniform(0.7, 0.85)),
        "clicks": {
            "profile_visits": int(likes * rng.uniform(*e["profile_visit_rate"])),
            "link_in_bio": int(likes * rng.uniform(*e["link_click_rate"])),
            "tagged_account": int(likes * rng.uniform(0.0, 0.04)),
        },
    }
    if ptype == "reel":
        result["plays"] = int(impressions * rng.uniform(1.1, 1.6))
    return result


def instagram_day(d, ig, catalog, rng, state):
    posts = [x for x in (ig_post_day(c, d, ig, rng, state) for c in catalog) if x]
    likes = sum(x["likes"] for x in posts)
    lo, hi = ig["daily_follower_change"]
    state["followers"] = state["followers"] + rng.randint(lo, hi)
    return {
        "totals": {
            "followers": state["followers"],
            "posts_published": sum(1 for x in posts if x["post_age_days"] == 0),
            "likes": likes,
            "comments": sum(x["comments"] for x in posts),
            "clicks": sum(sum(x["clicks"].values()) for x in posts),
            "impressions": sum(x["impressions"] for x in posts),
            "reach": int(sum(x["reach"] for x in posts) * 0.75),
            "website_clicks": sum(x["clicks"]["link_in_bio"] for x in posts),
        },
        "interactions_by_hour": [{"hour": h, "likes": v} for h, v in enumerate(split(likes, norm(ig["hourly"]), rng))],
        "posts": posts,
    }


# --------------------------------------------------------------------------- main

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--profile", required=True)
    ap.add_argument("--end", required=True, help="last day (YYYY-MM-DD)")
    ap.add_argument("--days", type=int, default=92)
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--out", required=True)
    a = ap.parse_args()

    with open(a.profile, encoding="utf-8") as f:
        p = json.load(f)
    rng = random.Random(a.seed)
    end = date.fromisoformat(a.end)
    start = end - timedelta(days=a.days - 1)
    ig = p["instagram"]
    catalog = build_posts(p, start, end, random.Random(a.seed + 1))

    # walk followers so that the last day lands near the observed follower count
    lo, hi = ig["daily_follower_change"]
    state = {"followers": ig["followers_observed"] - int((lo + hi) / 2 * a.days)}

    days = []
    for i in range(a.days):
        d = start + timedelta(days=i)
        days.append({
            "date": d.isoformat(),
            "weekday": d.strftime("%A"),
            "website": website_day(d, p, rng),
            "instagram": instagram_day(d, ig, catalog, rng, state),
        })

    doc = {
        "meta": {
            "stream": p["stream"],
            "version": "1.1",
            "simulated": True,
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "timezone": p.get("timezone", "Europe/Berlin"),
            "period": {"start": start.isoformat(), "end": end.isoformat(), "days": a.days},
            "sources": {
                "website": {"domain": p["website"]["domain"], "tracked_pages": len(p["website"]["pages"]),
                            "click_targets": p["website"]["click_targets"],
                            "events": p["website"].get("events", [])},
                "instagram": {"account": ig["account"], "profile_url": ig["profile_url"],
                              "followers_observed": ig["followers_observed"],
                              "posts": [x for x in catalog if x["pinned"]
                                        or date.fromisoformat(x["published"]) >= start - timedelta(days=TRACK_DAYS)]},
            },
            "notes": p["notes"],
        },
        "days": days,
    }
    with open(a.out, "w", encoding="utf-8") as f:
        json.dump(doc, f, ensure_ascii=False, indent=2)
    print(f"wrote {a.out}: {a.days} day(s), {len(doc['meta']['sources']['instagram']['posts'])} posts")


if __name__ == "__main__":
    main()
