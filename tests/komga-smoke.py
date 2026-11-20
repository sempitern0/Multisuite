"""Real Komga smoke test with generated EPUB content and disposable Docker volumes."""
import base64
import hashlib
from http.cookies import SimpleCookie
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid
import zipfile

root = Path(__file__).resolve().parents[1]
project = "komga-ci-" + uuid.uuid4().hex[:10]
nas = project + "-nas"

def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()

with tempfile.TemporaryDirectory(prefix="komga-ci-") as directory:
    work = Path(directory)
    book = work / "sample.epub"
    with zipfile.ZipFile(book, "w") as epub:
        epub.writestr("mimetype", "application/epub+zip")
        epub.writestr("META-INF/container.xml", '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="book.opf" media-type="application/oebps-package+xml"/></rootfiles></container>')
        epub.writestr("book.opf", '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">urn:uuid:komga-ci</dc:identifier><dc:title>Integration test</dc:title><dc:language>es</dc:language><meta property="dcterms:modified">2026-11-17T00:00:00Z</meta></metadata><manifest><item id="text" href="text.xhtml" media-type="application/xhtml+xml"/><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/></manifest><spine><itemref idref="text"/></spine></package>')
        epub.writestr("text.xhtml", '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Test</title></head><body>' + '<p>Texto de prueba de lectura.</p>' * 10000 + '<p id="end">FINAL DEL CONTENIDO</p></body></html>')
        epub.writestr("nav.xhtml", '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Índice</title></head><body><nav epub:type="toc"><ol><li><a href="text.xhtml">Texto</a></li></ol></nav></body></html>')
    override = work / "override.yaml"
    override.write_text("volumes:\n  nas:\n    name: " + nas + "\nnetworks:\n  accounts:\n    name: " + project + "-accounts\n")
    compose = ["compose", "-p", project, "-f", str(root / "compose.komga.yaml"), "-f", str(override)]
    try:
        docker("volume", "create", nas)
        docker("network", "create", project + "-accounts")
        docker("run", "--rm", "--entrypoint", "sh", "-v", nas + ":/nas",
               "-v", str(work) + ":/fixture:ro", "gotson/komga:1.30.1", "-ec",
               "mkdir -p /nas/.multisuite-media/reading/reader/books/sample && cp /fixture/sample.epub /nas/.multisuite-media/reading/reader/books/sample/")
        docker(*compose, "up", "-d", "--wait", "--wait-timeout", "180")
        url = "http://127.0.0.1:" + os.environ.get("KOMGA_PORT", "25600")
        with urllib.request.urlopen(url) as response:
            assert b'/_multisuite/reading.mjs' in response.read(), "Missing shell integration"
        try:
            urllib.request.urlopen(url + "/_multisuite/revision")
            raise AssertionError("Anonymous revision access must be rejected")
        except urllib.error.HTTPError as error:
            assert error.code in (401, 403), error.code
            assert error.headers.get("WWW-Authenticate") is None, "Browser login dialog must be suppressed"
        email, password = "pilot@example.test", uuid.uuid4().hex
        auth = "Basic " + base64.b64encode((email + ":" + password).encode()).decode()

        def request(path, data=None, headers=None):
            payload = None if data is None else json.dumps(data).encode()
            req = urllib.request.Request(url + path, data=payload, headers={
                **({} if path == "/api/v1/claim" else {"Authorization": auth}), "Content-Type": "application/json", **(headers or {})
            })
            with urllib.request.urlopen(req, timeout=15) as response:
                return response.read()

        request("/api/v1/claim", {}, {"X-Komga-Email": email, "X-Komga-Password": password})
        # Real native cookie hand-off: the second request must work without Basic auth.
        login = urllib.request.Request(url + "/api/v2/users/me", headers={"Authorization": auth})
        with urllib.request.urlopen(login) as response:
            cookies = SimpleCookie()
            for header in response.headers.get_all("Set-Cookie", []):
                cookies.load(header)
        assert "KOMGA-SESSION" in cookies, "Native login did not issue a reusable session"
        session = cookies["KOMGA-SESSION"].value
        native = urllib.request.Request(url + "/api/v2/users/me",
            headers={"Cookie": "KOMGA-SESSION=" + session, "Authorization": "Basic invalid", "Sec-Fetch-Mode": "cors"})
        with urllib.request.urlopen(native) as response:
            assert json.loads(response.read())["email"] == email
        browser = urllib.request.Request(url + "/api/v2/users/me",
            headers={"Authorization": auth, "Sec-Fetch-Mode": "cors"})
        try:
            urllib.request.urlopen(browser)
            raise AssertionError("Cached browser Basic credentials must not restore another session")
        except urllib.error.HTTPError as error:
            assert error.code == 401
            assert error.headers.get("WWW-Authenticate") is None
        manual = urllib.request.Request(url + "/api/v2/users/me?remember-me=false",
            headers={"Authorization": auth, "Sec-Fetch-Mode": "cors"})
        with urllib.request.urlopen(manual) as response:
            assert json.loads(response.read())["email"] == email
        library = json.loads(request("/api/v1/libraries", {"name": "Pilot", "root": "/data", "scanOnStartup": True}))
        request("/api/v1/libraries/" + library["id"] + "/scan", {})
        found = None
        for attempt in range(60):
            books = json.loads(request("/api/v1/books"))["content"]
            if books and books[0]["media"]["status"] == "READY":
                found = books[0]
                break
            time.sleep(2)
        assert found, "Komga did not analyze the generated EPUB."
        downloaded = request("/api/v1/books/" + found["id"] + "/file")
        assert hashlib.sha256(downloaded).digest() == hashlib.sha256(book.read_bytes()).digest()
        req = urllib.request.Request(url + "/api/v1/books/" + found["id"] + "/file",
            headers={"Authorization": auth, "Range": "bytes=0-99"})
        with urllib.request.urlopen(req) as response:
            # Komga may ignore Range on the whole-file endpoint (HTTP permits 200).
            # Both responses must contain the correct, unmodified bytes.
            assert response.status in (200, 206)
            expected = book.read_bytes()[:100] if response.status == 206 else book.read_bytes()
            assert response.read() == expected
        print("Komga scanned and served the complete generated EPUB.")
    finally:
        subprocess.run(["docker", *compose, "down", "-v"], check=False)
        subprocess.run(["docker", "volume", "rm", nas], check=False)
        subprocess.run(["docker", "network", "rm", project + "-accounts"], check=False)
