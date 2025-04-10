# PMP API Key Manager

A WordPress plugin that integrates Paid Memberships Pro (PMP) with FastAPI-based applications to manage API keys, enabling tiered access control and rate limiting for subscription-based services.

## Overview

The PMP API Key Manager plugin provides a seamless way to tie API key generation, management, and validation to PMP membership levels. Designed for developers and site owners running FastAPI applications, this plugin allows you to:

- Generate and distribute API keys to users based on their membership tier.
- Define custom permissions (e.g., rate limits, feature flags) per tier.
- Sync keys and permissions with a FastAPI backend for runtime validation.
- Display and manage keys on the WordPress frontend for users.

This plugin is ideal for SaaS platforms, API-driven apps, or any service where access is gated by subscription tiers.

## Features

- **Dynamic Tier Integration**: Automatically pulls PMP membership levels and maps them to API permissions.
- **Granular Rate Limiting**: Supports per-hour and per-day request limits per feature, synced to FastAPI.
- **Multi-App Support**: Manage keys for multiple FastAPI applications from a single WordPress instance.
- **Secure Key Management**: Stores key metadata in WordPress, syncs securely with FastAPI, and uses UUIDs for unique identification.
- **User-Friendly**: Includes a shortcode to display and manage API keys on the frontend.
- **Customizable Permissions**: Define limits (e.g., requests/hour) and flags (e.g., feature access) via an admin interface.
- **Scalable**: Built to handle multiple apps and users, with options to scale storage (e.g., Redis) as needed.

## Requirements

- **WordPress**: 5.0 or higher
- **Paid Memberships Pro**: Latest version
- **PHP**: 7.4 or higher
- **FastAPI Backend**: A compatible FastAPI application (see "FastAPI Setup" below)
- **Redis** (optional): For FastAPI rate limiting and key storage

## Installation

1. **Download**: Clone or download this repository.
2. **Upload**: Upload the `pmpro-api-key-manager` folder to `/wp-content/plugins/` on your WordPress server.
3. **Activate**: Go to the WordPress admin dashboard, navigate to Plugins, and activate "PMP API Key Manager".
4. **Configure**: Visit `Settings > API Key Manager` to set up your FastAPI applications and membership mappings.

## Configuration

### WordPress Setup

1. **Admin Settings**:

   - Navigate to `Settings > API Key Manager` in your WordPress dashboard.
   - Add each FastAPI application:
     - **App ID**: A unique identifier (e.g., `myapp`).
     - **FastAPI URL**: The base URL of your FastAPI app (e.g., `https://api.myapp.com`).
     - **Membership Levels**: Map PMP levels to permissions (e.g., limits and flags).
   - Example:
     ```
     App ID: myapp
     URL: https://api.myapp.com
     Level 1 (Basic):
       Limits: { "endpoint1": { "hour": 100, "day": 1000 } }
       Flags: { "feature1": true }
     Level 2 (Pro):
       Limits: { "endpoint1": { "hour": 500, "day": 5000 } }
       Flags: { "feature1": true, "feature2": true }
     ```

2. **Shortcode**:
   - Add `[pmpro_api_keys]` to any page or post to let users view and manage their API keys.

### FastAPI Setup

Your FastAPI application must implement the following endpoints:

- **`POST /keys/create`**:
  - Input: `{ "key_id": "uuid", "tier": "level_name", "permissions": { "limits": {}, "flags": {} } }`
  - Output: `{ "api_key": "generated_key" }`
- **`PUT /keys/update`**:
  - Input: Same as `/keys/create`
  - Output: `{ "status": "updated" }`
- **`GET /keys/validate`**:
  - Input: `?api_key=generated_key`
  - Output: `{ "key_id": "uuid", "permissions": { "limits": {}, "flags": {} } }`

**Rate Limiting**: Use [SlowAPI](https://slowapi.readthedocs.io/) with a Sliding Window Counter strategy to enforce `hour` and `day` limits per endpoint. See the example below.

#### Example FastAPI Template

```
from fastapi import FastAPI, HTTPException, Request
from slowapi import Limiter
from slowapi.util import get_remote_address
from pydantic import BaseModel
import redis
import json
import time
app = FastAPI()
limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
redis_client = redis.Redis(host='localhost', port=6379, db=0)
class KeyCreate(BaseModel):
    key_id: str
    tier: str
    permissions: dict
def sliding_window_limit(key: str, flag: str, period: str, limit: int):
    current_time = int(time.time())
    bucket_size = 3600 if period == "hour" else 86400
    current_bucket = current_time // bucket_size
    prev_bucket = current_bucket - 1
    curr_key = f"{key}:{flag}:{period}:{current_bucket}"
    prev_key = f"{key}:{flag}:{period}:{prev_bucket}"
    redis_client.incr(curr_key)
    redis_client.expire(curr_key, bucket_size * 2)
    curr_count = int(redis_client.get(curr_key) or 0)
    prev_count = int(redis_client.get(prev_key) or 0)
    elapsed = current_time % bucket_size
    weight = 1 - (elapsed / bucket_size)
    effective_count = curr_count + (prev_count * weight)
    return effective_count <= limit
@app
.post("/keys/create")
async def create_key(data: KeyCreate):
    api_key = "xyz_" + data.key_id  # Replace with secure key generation
    redis_client.set(data.key_id, api_key)
    redis_client.set(f"{data.key_id}:permissions", json.dumps(data.permissions))
    return {"api_key": api_key}
@app
.put("/keys/update")
async def update_key(data: KeyCreate):
    if redis_client.get(data.key_id):
        redis_client.set(f"{data.key_id}:permissions", json.dumps(data.permissions))
        return {"status": "updated"}
    raise HTTPException(404, "Key not found")
@app
.get("/keys/validate")
async def validate_key(api_key: str):
    key_id = redis_client.get(api_key)
    if not key_id:
        raise HTTPException(403, "Invalid key")
    perms = json.loads(redis_client.get(f"{key_id.decode()}:permissions"))
    return {"key_id": key_id.decode(), "permissions": perms}
def get_limit(request: Request, flag: str, period: str):
    api_key = request.headers.get("x-api-key")
    key_id = redis_client.get(api_key)
    if not key_id:
        raise HTTPException(403, "Invalid key")
    perms = json.loads(redis_client.get(f"{key_id.decode()}:permissions"))
    limit = perms["limits"].get(flag, {}).get(period, 0)
    if not sliding_window_limit(key_id.decode(), flag, period, limit):
        raise HTTPException(429, f"{flag} {period} limit exceeded")
    return True
@app
.get("/endpoint1")
async def endpoint1(request: Request):
    get_limit(request, "endpoint1", "hour")
    get_limit(request, "endpoint1", "day")
    return {"result": "Success"}
```

## Usage

1. **User Subscribes**: A user purchases a PMP membership level (e.g., "Basic").
2. **Key Generation**: The plugin generates a `key_id`, syncs it with your FastAPI app, and stores the returned `api_key`.
3. **User Access**: The user retrieves their API key via the `[pmpro_api_keys]` shortcode and uses it in requests to your FastAPI app.
4. **Validation**: FastAPI validates the key and enforces rate limits based on the permissions.

## Development

- **Hooks**: Uses `pmpro_after_change_membership_level` to trigger key actions.
- **Database**: Creates a `wp_pmpro_api_keys` table for key metadata.
- **Extensibility**: Add custom endpoints or permissions by updating the FastAPI template and admin settings.

## Contributing

Contributions are welcome! Fork the repository, make your changes, and submit a pull request. Please include tests and update this README if needed.

## License

This plugin is licensed under the [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html), in line with WordPress standards.

## Support

For issues or questions, open a ticket on the [GitHub Issues page](https://github.com/unclemusclez/pmpro-api-key-manager/issues).
Visit the Water Pistol Discord for community Assistianance [Water Pistol Discord](https://discord.waterpistol.co)
