"""Per-user IPAS session cookie, held in Redis for a limited time only."""
import json
import time

from redis import Redis

from . import config


def redis():
    return Redis.from_url(config.REDIS_URL)


def _key(user_id):
    return "daybook:ipas_session:%d" % (user_id or 0)   # 0 = the local user while login is off


def save(user_id, cookie, user_agent):
    redis().setex(_key(user_id), config.IPAS_COOKIE_TTL_SECONDS,
                  json.dumps({"cookie": cookie, "user_agent": user_agent, "saved_at": int(time.time())}))


def load(user_id):
    raw = redis().get(_key(user_id))
    return json.loads(raw) if raw else None


def clear(user_id):
    redis().delete(_key(user_id))


def info(user_id):
    """What may be shown about the session: cookie names and expiry, never values."""
    data = load(user_id)
    if not data:
        return {"set": False}
    ttl = redis().ttl(_key(user_id))
    names = [p.split("=", 1)[0].strip() for p in data["cookie"].split(";") if p.strip()]
    return {"set": True, "names": names, "saved_at": data["saved_at"], "expires_in": ttl}
