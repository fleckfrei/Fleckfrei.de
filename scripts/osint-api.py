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
os.makedirs(CACHE_DIR, exist_ok=True)

class Handler(BaseHTTPRequestHandler):
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
            elif path == "/health":
                result = {"status": "ok", "tools": ["maigret", "phoneinfoga", "holehe"]}
            else:
                result = {"error": "Use /maigret, /phoneinfoga, /holehe, /health"}
        except subprocess.TimeoutExpired:
            result = {"error": "Timeout"}
        except Exception as e:
            result = {"error": str(e)}
        self._respond(200, result)

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
        out = subprocess.run(
            ["docker", "run", "--rm", "ghcr.io/soxoj/maigret:latest", username, "--json", "notype", "--timeout", "5", "--no-color"],
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

    def log_message(self, format, *args): pass

print("OSINT API Proxy starting on :8900...")
HTTPServer(("0.0.0.0", 8900), Handler).serve_forever()
