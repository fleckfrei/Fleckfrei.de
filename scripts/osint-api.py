#!/usr/bin/env python3
"""
Fleckfrei OSINT API Proxy — runs on VPS (89.116.22.185:8900)
Exposes Maigret, PhoneInfoga, Holehe as REST API endpoints.
Called from app.fleckfrei.de/api/osint-deep.php

Start: nohup python3 /opt/osint-api.py > /tmp/osint-api.log 2>&1 &
Test:  curl -X POST http://localhost:8900/holehe -H "X-API-Key: flk_api..." -H "Content-Type: application/json" -d '{"email":"test@gmail.com"}'
"""
from http.server import HTTPServer, BaseHTTPRequestHandler
import json, subprocess, os, hashlib, time

API_KEY = "***REDACTED***"
CACHE_DIR = "/tmp/osint-cache"
TOR_PROXY = "socks5://127.0.0.1:9050"
os.makedirs(CACHE_DIR, exist_ok=True)

def tor_rotate():
    """Request new Tor circuit for fresh IP"""
    try:
        import socket
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect(("127.0.0.1", 9051))
        s.send(b'AUTHENTICATE ""\r\nSIGNAL NEWNYM\r\nQUIT\r\n')
        s.close()
    except: pass

class Handler(BaseHTTPRequestHandler):

    def _respond(self, code, data):
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Access-Control-Allow-Origin", "https://app.fleckfrei.de")
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def _cached(self, prefix, key):
        path = f"{CACHE_DIR}/{prefix}_{hashlib.md5(key.encode()).hexdigest()}.json"
        if os.path.exists(path) and time.time() - os.path.getmtime(path) < 86400:
            return json.load(open(path)), path
        return None, path

    def _maigret(self, username):
        if not username: return {"error": "username required"}
        cached, path = self._cached("maigret", username)
        if cached: return {**cached, "_cache": True}
        tor_rotate()  # Fresh IP for each scan
        out = subprocess.run(
            ["docker", "run", "--rm", "--network=host", "ghcr.io/soxoj/maigret:latest", username,
             "--json", "notype", "--timeout", "5", "--no-color", "--proxy", TOR_PROXY],
            capture_output=True, text=True, timeout=45
        )
        found = []
        for line in out.stdout.strip().split("\n"):
            try:
                d = json.loads(line)
                if d.get("status") == "Claimed":
                    found.append({"site": d.get("sitename", ""), "url": d.get("url_user", ""), "tags": d.get("tags", [])})
            except: pass
        result = {"username": username, "found": len(found), "profiles": found[:50]}
        json.dump(result, open(path, "w"))
        return result

    def _phoneinfoga(self, phone):
        if not phone: return {"error": "phone required"}
        out = subprocess.run(
            ["docker", "run", "--rm", "sundowndev/phoneinfoga:latest", "scan", "-n", phone],
            capture_output=True, text=True, timeout=20
        )
        # Parse phoneinfoga text output
        lines = out.stdout.strip().split("\n")
        info = {"phone": phone, "raw_lines": len(lines)}
        for line in lines:
            if "Country:" in line: info["country"] = line.split(":", 1)[1].strip()
            elif "Carrier:" in line: info["carrier"] = line.split(":", 1)[1].strip()
            elif "Line type:" in line: info["type"] = line.split(":", 1)[1].strip()
            elif "International:" in line: info["international"] = line.split(":", 1)[1].strip()
            elif "Local:" in line: info["local"] = line.split(":", 1)[1].strip()
        return info

    def _holehe(self, email):
        if not email: return {"error": "email required"}
        cached, path = self._cached("holehe", email)
        if cached: return {**cached, "_cache": True}
        out = subprocess.run(
            ["holehe", email, "--no-color", "--only-used"],
            capture_output=True, text=True, timeout=60
        )
        sites = []
        for line in out.stdout.split("\n"):
            line = line.strip()
            if "[+]" in line:
                parts = line.split("]", 1)
                if len(parts) > 1:
                    site = parts[1].strip().split(" ")[0]
                    sites.append(site)
        result = {"email": email, "registered_on": sites, "count": len(sites)}
        json.dump(result, open(path, "w"))
        return result

    def _whois(self, domain):
        if not domain: return {"error": "domain required"}
        cached, path = self._cached("whois", domain)
        if cached: return {**cached, "_cache": True}
        out = subprocess.run(["whois", domain], capture_output=True, text=True, timeout=10)
        data = {"domain": domain, "raw": out.stdout[:2000]}
        # Parse key fields
        for line in out.stdout.split("\n"):
            line = line.strip()
            if ":" not in line: continue
            k, v = line.split(":", 1)
            k, v = k.strip().lower(), v.strip()
            if "registrar" in k and "registrar" not in data: data["registrar"] = v
            elif "creation" in k or "created" in k: data["created"] = v
            elif "expir" in k: data["expires"] = v
            elif "registrant name" in k: data["registrant"] = v
            elif "registrant org" in k: data["org"] = v
            elif "registrant country" in k: data["country"] = v
        # Remove raw if parsed ok
        if len(data) > 3: del data["raw"]
        json.dump(data, open(path, "w"))
        return data

    def _socialscan(self, query):
        if not query: return {"error": "email or username required"}
        cached, path = self._cached("socialscan", query)
        if cached: return {**cached, "_cache": True}
        out = subprocess.run(
            ["socialscan", query, "--json"],
            capture_output=True, text=True, timeout=30
        )
        results = []
        for line in out.stdout.strip().split("\n"):
            try:
                d = json.loads(line)
                if d.get("available") == False:  # Account EXISTS
                    results.append({"platform": d.get("platform", ""), "username": d.get("query", ""), "available": False})
            except: pass
        result = {"query": query, "taken_on": len(results), "platforms": results[:30]}
        json.dump(result, open(path, "w"))
        return result

    def do_POST(self):
        key = self.headers.get("X-API-Key", "")
        if key != API_KEY:
            self._respond(401, {"error": "Unauthorized"})
            return
        length = int(self.headers.get("Content-Length", 0))
        body = json.loads(self.rfile.read(length)) if length else {}
        path = self.path
        result = {}
        try:
            if path == "/maigret":
                result = self._maigret(body.get("username", ""))
            elif path == "/phoneinfoga":
                result = self._phoneinfoga(body.get("phone", ""))
            elif path == "/holehe":
                result = self._holehe(body.get("email", ""))
            elif path == "/whois":
                result = self._whois(body.get("domain", ""))
            elif path == "/socialscan":
                result = self._socialscan(body.get("query", ""))
            elif path == "/scan-all":
                # Combined scan — runs all applicable tools
                import concurrent.futures
                results = {}
                with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
                    futures = {}
                    if body.get("email"):
                        futures["holehe"] = pool.submit(self._holehe, body["email"])
                        futures["socialscan_email"] = pool.submit(self._socialscan, body["email"])
                    if body.get("username"):
                        futures["maigret"] = pool.submit(self._maigret, body["username"])
                    elif body.get("name"):
                        uname = body["name"].lower().replace(" ", "")
                        futures["maigret"] = pool.submit(self._maigret, uname)
                    if body.get("phone"):
                        futures["phoneinfoga"] = pool.submit(self._phoneinfoga, body["phone"])
                    if body.get("domain"):
                        futures["whois"] = pool.submit(self._whois, body["domain"])
                    for key, future in futures.items():
                        try: results[key] = future.result(timeout=30)
                        except: results[key] = {"error": "timeout"}
                result = {"scan_all": True, "tools_run": len(results), "results": results}
            elif path == "/health":
                result = {"status": "ok", "tools": ["maigret", "phoneinfoga", "holehe", "whois", "socialscan", "scan-all"], "tor": True}
            else:
                result = {"error": "Use /maigret, /phoneinfoga, /holehe, /whois, /socialscan"}
        except subprocess.TimeoutExpired:
            result = {"error": "Timeout"}
        except Exception as e:
            result = {"error": str(e)}
        self._respond(200, result)

    def log_message(self, format, *args): pass

print("OSINT API Proxy v2 starting on :8900 — 5 tools active")
HTTPServer(("0.0.0.0", 8900), Handler).serve_forever()
