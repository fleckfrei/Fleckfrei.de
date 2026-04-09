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
             "--json", "notype", "--timeout", "3", "--no-color", "--top-sites", "100"],
            capture_output=True, text=True, timeout=15
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
            capture_output=True, text=True, timeout=15
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
            elif path == "/intelx":
                result = self._intelx(body.get("query", ""))
            elif path == "/perplexity":
                result = self._perplexity(body.get("query", ""))
            elif path == "/websearch":
                # Scrape DuckDuckGo from VPS (not blocked here!)
                query = body.get("query", "")
                if query:
                    result = self._websearch(query)
                else:
                    result = {"error": "query required"}
            elif path == "/vulture":
                # THE VULTURE — deep multi-source scan
                result = self._vulture(body)
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

    def _intelx(self, query):
        """Intelligence X — search leaked data, dark web, pastes"""
        import urllib.request
        if not query: return {"error": "query required"}
        cached, path = self._cached("intelx", query)
        if cached: return {**cached, "_cache": True}
        INTELX_KEY = os.environ.get("INTELX_KEY", "3101fc86-9b9d-4bd3-96b2-e457e8e222f4")
        # Step 1: Start search
        data = json.dumps({"term": query, "buckets": [], "lookuplevel": 0, "maxresults": 20, "timeout": 10, "sort": 2, "media": 0, "terminate": []}).encode()
        req = urllib.request.Request("https://2.intelx.io/phonebook/search", data=data, headers={"x-key": INTELX_KEY, "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                search = json.loads(resp.read().decode())
            search_id = search.get("id", "")
            if not search_id: return {"query": query, "results": [], "count": 0}
            # Step 2: Get results
            import time; time.sleep(2)
            req2 = urllib.request.Request(f"https://2.intelx.io/phonebook/search/result?id={search_id}&limit=20", headers={"x-key": INTELX_KEY})
            with urllib.request.urlopen(req2, timeout=10) as resp2:
                results = json.loads(resp2.read().decode())
            selectors = results.get("selectors", [])
            found = []
            for s in selectors[:30]:
                found.append({"value": s.get("selectorvalue", ""), "type": s.get("selectortypeh", ""), "source": s.get("mediah", "")})
            output = {"query": query, "count": len(found), "results": found, "total": results.get("statistics", {}).get("total", 0)}
            json.dump(output, open(path, "w"))
            return output
        except Exception as e:
            return {"query": query, "error": str(e), "results": []}

    def _perplexity(self, query):
        """Search via Perplexity Sonar AI — real web search with citations"""
        import urllib.request, urllib.parse
        cached, path = self._cached("perplexity", query)
        if cached: return {**cached, "_cache": True}
        data = json.dumps({
            "model": "sonar",
            "messages": [{"role": "user", "content": f"Search the web for: {query}. Return detailed findings including names, addresses, companies, reviews, social media profiles. Be thorough and specific."}],
            "max_tokens": 500
        }).encode()
        req = urllib.request.Request("https://api.perplexity.ai/chat/completions", data=data, headers={
            "Authorization": f"Bearer {os.environ.get('PERPLEXITY_KEY', '')}",
            "Content-Type": "application/json"
        })
        try:
            with urllib.request.urlopen(req, timeout=12) as resp:
                result = json.loads(resp.read().decode())
            content = result["choices"][0]["message"]["content"]
            citations = result.get("citations", [])
            output = {"query": query, "answer": content, "citations": citations, "model": "sonar"}
            json.dump(output, open(path, "w"))
            return output
        except Exception as e:
            return {"query": query, "error": str(e)}

    def _websearch(self, query, limit=5):
        """Scrape DuckDuckGo from VPS (not blocked here)"""
        import urllib.request, urllib.parse, re
        url = "https://html.duckduckgo.com/html/?q=" + urllib.parse.quote(query)
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36"})
        try:
            with urllib.request.urlopen(req, timeout=8) as resp:
                html = resp.read().decode("utf-8", errors="ignore")
            results = []
            for m in re.finditer(r'class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)</a>.*?class="result__snippet"[^>]*>(.*?)</span>', html, re.DOTALL):
                rurl = m.group(1)
                um = re.search(r'uddg=([^&]+)', rurl)
                if um: rurl = urllib.parse.unquote(um.group(1))
                title = re.sub(r'<[^>]+>', '', m.group(2)).strip()
                snippet = re.sub(r'<[^>]+>', '', m.group(3)).strip()
                if title: results.append({"title": title, "url": rurl, "snippet": snippet})
                if len(results) >= limit: break
            return {"query": query, "count": len(results), "results": results}
        except Exception as e:
            return {"query": query, "count": 0, "results": [], "error": str(e)}

    def _vulture(self, body):
        """THE VULTURE — Deep multi-source intelligence scan via Perplexity AI"""
        import concurrent.futures
        name = body.get("name", "")
        email = body.get("email", "")
        phone = body.get("phone", "")
        address = body.get("address", "")
        plate = body.get("plate", "")
        domain = body.get("domain", "")

        results = {}
        searches = {}

        # PERPLEXITY AI SEARCH — real web search, not scraping
        pplx_queries = []
        if name: pplx_queries.append(f'"{name}" — find company, address, reviews, social media, Impressum, Handelsregister')
        if email: pplx_queries.append(f'"{email}" — find registered accounts, websites, social media profiles, data breaches')
        if phone: pplx_queries.append(f'"{phone}" — find owner name, carrier, location, associated businesses')
        if plate: pplx_queries.append(f'license plate "{plate}" — find vehicle owner, make, model, listings')
        if address: pplx_queries.append(f'"{address}" — find property owner, Airbnb listings, real estate, businesses at this address')

        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            pplx_futures = {q: pool.submit(self._perplexity, q) for q in pplx_queries}
            for q, future in pplx_futures.items():
                try:
                    res = future.result(timeout=15)
                    if res.get("answer"):
                        key = q[:30].replace('"','').strip()
                        results[key] = res
                except: pass

        # Build search queries
        if name:
            ne = f'"{name}"'
            searches["gelbe_seiten"] = f'site:gelbeseiten.de {ne}'
            searches["das_oertliche"] = f'site:dasoertliche.de {ne}'
            searches["pagini_aurii"] = f'site:paginiaurii.ro {ne}'
            searches["11880"] = f'site:11880.com {ne}'
            searches["northdata"] = f'site:northdata.de {ne}'
            searches["bundesanzeiger"] = f'site:bundesanzeiger.de {ne}'
            searches["firmenwissen"] = f'site:firmenwissen.de {ne}'
            searches["insolvenz"] = f'{ne} Insolvenz OR insolvent OR Vollstreckung'
            searches["impressum"] = f'{ne} Impressum Geschäftsführer OR Inhaber'
            searches["airbnb"] = f'site:airbnb.de OR site:airbnb.com {ne}'
            searches["booking"] = f'site:booking.com {ne}'
            searches["kleinanzeigen"] = f'site:kleinanzeigen.de {ne}'
            searches["social"] = f'{ne} site:instagram.com OR site:linkedin.com OR site:facebook.com'
            searches["bewertungen"] = f'{ne} Bewertung OR Review site:google.com OR site:trustpilot.com'
            searches["gericht"] = f'{ne} Gericht OR Urteil OR Klage OR Polizei'
            searches["dokumente"] = f'{ne} filetype:pdf OR filetype:xlsx OR filetype:doc'
        if email:
            searches["email_trace"] = f'"{email}"'
            searches["email_social"] = f'"{email}" site:instagram.com OR site:linkedin.com OR site:facebook.com'
            searches["email_paste"] = f'"{email}" site:pastebin.com OR site:gist.github.com'
        if phone:
            ph = ''.join(c for c in phone if c in '+0123456789')
            searches["phone_trace"] = f'"{ph}"'
            searches["phone_tellows"] = f'site:tellows.de "{ph}"'
        if plate:
            searches["plate_exact"] = f'"{plate}"'
            searches["plate_auto"] = f'"{plate}" auto OR Werkstatt OR Unfall'
        if address:
            searches["address_immo"] = f'"{address}" site:immobilienscout24.de OR site:immowelt.de'

        # Run ALL searches in parallel via ThreadPool
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            futures = {key: pool.submit(self._websearch, query, 5) for key, query in searches.items()}
            for key, future in futures.items():
                try:
                    res = future.result(timeout=10)
                    if res.get("count", 0) > 0:
                        results[key] = res
                except: pass

        # IntelX — leaked data search
        intelx_queries = []
        if email: intelx_queries.append(email)
        if phone: intelx_queries.append(phone)
        if name: intelx_queries.append(name)
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            ix_futures = {q: pool.submit(self._intelx, q) for q in intelx_queries[:3]}
            for q, future in ix_futures.items():
                try:
                    res = future.result(timeout=15)
                    if res.get("count", 0) > 0:
                        results[f"intelx_{q[:20]}"] = res
                except: pass

        # Also run tool scans
        tool_results = {}
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            tool_futures = {}
            if email:
                tool_futures["holehe"] = pool.submit(self._holehe, email)
            if name:
                tool_futures["maigret"] = pool.submit(self._maigret, name.lower().replace(" ", ""))
            if phone:
                tool_futures["phoneinfoga"] = pool.submit(self._phoneinfoga, phone)
            if domain:
                tool_futures["whois"] = pool.submit(self._whois, domain)
            for key, future in tool_futures.items():
                try: tool_results[key] = future.result(timeout=20)
                except: pass

        return {
            "vulture": True,
            "target": name or email or phone or plate,
            "web_searches": results,
            "web_search_count": len(results),
            "tool_results": tool_results,
            "total_hits": sum(r.get("count", 0) for r in results.values()),
        }

    def log_message(self, format, *args): pass

print("OSINT API Proxy v2 starting on :8900 — 5 tools active")
HTTPServer(("0.0.0.0", 8900), Handler).serve_forever()
