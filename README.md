# nawasara/cctv

Public CCTV monitoring for the Nawasara superapp framework. It supports **Dahua** cameras over RTSP, shown in the browser through the **go2rtc** sidecar (RTSP to WebRTC/HLS), with health monitoring and a framework for recording playback.

## Why a sidecar is needed

Browsers cannot play RTSP directly. `go2rtc` is a separate service (a container) that takes RTSP from the camera and republishes it as WebRTC/HLS/MSE, which the browser can consume. Laravel **never** touches RTSP; it only talks to the go2rtc HTTP API.

```
Kamera Dahua  --RTSP-->  go2rtc (sidecar)  --WebRTC/HLS-->  Browser
                              ▲
                              │ HTTP API (register stream, query status)
                         Laravel (paket ini)
```

## Status v0.2.6

| Feature | Status |
|---|---|
| Camera registry + CRUD (encrypted credentials) | ready |
| Live view (grid + single focus) via go2rtc | ready |
| Stream sync to go2rtc | ready |
| Camera health monitoring (TCP probe) | ready |
| **Stream health monitoring** (grab a frame through go2rtc) | ready, v0.2.1 |
| Preview / thumbnail per camera | ready, v0.2.2 to v0.2.4 |
| Live viewer count | ready, v0.2.5 to v0.2.6 |
| Citizen API (`/citizen/cctv/*`) | ready |
| Recording playback table + UI | ready (UI only) |
| **Recording engine** (record RTSP to disk) | not built yet, needs a retention/storage decision |

The Recordings UI is complete. Once the recording engine is enabled in a future version, that page works without changes.

## Design notes

### 1. There are two health probes, and the one shown to citizens is not the TCP one

`CameraHealthProbe` does a TCP connect to the RTSP port. `StreamHealthProbe` takes the citizen's path and asks go2rtc for a single frame. Merging them looks like reasonable cleanup, and doing so brings the original bug back.

The two really do give different results. A camera can answer TCP perfectly while its stream is unwatchable: credentials changed, the stream not yet registered in go2rtc, or an HEVC codec that cannot be transcoded. Before v0.2.1, a camera like that showed as **"Aktif"** to citizens, who tapped it and got a black screen. The stream probe found two cameras in exactly that state (channel-11 and channel-12) on the day it was switched on.

`Camera::getPublicStatusAttribute()` therefore prefers the stream status and falls back to the camera status only when the stream has never been probed.

### 2. Frames below 20 KB are discarded

A go2rtc stream only starts flowing when something requests it, and the first frame before the keyframe arrives is a **grey field**: a valid JPEG, answered with 200, indistinguishable except by its size.

Measured in production: grey frames are 6.5 to 10.4 KB, real frames 42 to 70 KB. The 20 KB threshold sits in the gap between them, not against either edge. A dark night scene produces a small file, and a threshold set too high would strip previews from healthy cameras.

This is also why the **cache lifetime (660 s) must exceed the probe interval (600 s)**. If it is shorter, there is a window where the cache is already empty while the next probe has not run yet, and a citizen request that lands in that window triggers a cold capture, which returns the grey field. Shortening the cache "to keep it fresher" brings the grey image back.

### 3. Warm-up is for the probe only, never for citizens

`frame(..., warmUp: true)` waits a few seconds for the stream to start flowing. That is right for the probe that runs in the background every ten minutes, and wrong for a citizen request, which would wait six seconds for a preview image. Citizens read the cache; the probe pays for the warm-up.

### Viewer count counts CONNECTIONS, not people

It is taken from `consumers[]` in go2rtc's `/api/streams`, refreshed every ten seconds. One citizen with two tabs counts as two, and viewers from the Nawasara and Gasta panels are included.

For the "busy right now" badge that is exactly the number being asked for. What it must **not** be used for is app usage statistics.

In the API, `viewers` is `null` when go2rtc is unreachable, and `null` is different from `0`. The viewer map is set through a static property on the Resource, not through `additional()`: Laravel does not pass `additional()` from a collection down to its members, so `viewers` would always be null while `meta.viewers_total` reported the correct number. That property **must be cleared in a `finally` block**. On a long-lived queue worker, a leftover map would attribute one request's viewer counts to the next.

## Setup

### 1. go2rtc sidecar (docker-compose)

Already added to `docker-compose.dev.yml` as the `go2rtc` service (image `alexxit/go2rtc`), on the same `nawasara-dev` network as the app. The container reaches cameras on the LAN through Docker host routing; no special `network_mode` is needed as long as the host can route to the camera subnet.

The reverse proxy `/go2rtc/` to `go2rtc:1984` is already set up in `docker/nginx.conf` (using `resolver` plus a `proxy_pass` variable so nginx does not fail to boot if the sidecar is not up yet).

### 2. Environment

```dotenv
CCTV_GO2RTC_API_URL=http://go2rtc:1984      # internal, dipakai Laravel
CCTV_GO2RTC_PUBLIC_URL=/go2rtc              # dipakai browser (via proxy)
CCTV_GO2RTC_MODE=webrtc
```

### 3. Migrations + permissions

```bash
php artisan migrate
php artisan db:seed --class="Nawasara\\Cctv\\Database\\Seeders\\PermissionSeeder"
```

## Camera credential security

Camera usernames and passwords are stored **encrypted at rest** (the `encrypted` cast on the `Camera` model), hidden from serialisation (`$hidden`), and never written to logs. The full RTSP URL (with credentials) is built only briefly to send to go2rtc, and never shown to the user.

> Note: when adding a camera, enter the credentials through the CRUD form. **Do not** hardcode them in config or the repo.

## Console commands

| Command | Purpose | Schedule |
|---|---|---|
| `cctv:probe` | TCP-probe active cameras, the raw material for **operator diagnosis** | every 5 minutes |
| `cctv:probe-streams` | Pull one frame through go2rtc: **this is what citizens see**, and it also fills the preview cache | every 10 minutes |
| `cctv:sync-go2rtc` | Re-register all cameras with go2rtc (a safety net if the sidecar restarts) | hourly |
| `cctv:sync-titles` | Fetch camera names from the Dahua device ChannelTitle (only those with `sync_title` enabled) | 03:00 WIB |

The `cctv:probe-streams` interval is **tied** to the preview cache lifetime. See design note 2 above before changing it.

## Permissions

| Permission | For |
|---|---|
| `cctv.camera.view` | View live view + camera list |
| `cctv.camera.create` | Add a camera |
| `cctv.camera.update` | Edit a camera |
| `cctv.camera.delete` | Delete a camera |
| `cctv.recording.view` | View + play recordings |
| `cctv.recording.delete` | Delete recordings |
