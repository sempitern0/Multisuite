"""Disposable S01 lab. Python standard library + Docker; never targets an existing server."""
import argparse
import hashlib
import json
import math
import pathlib
import secrets
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

IMAGE = 'navidrome/navidrome:0.64.2'


def docker(*args):
    result = subprocess.run(['docker', *args], capture_output=True, text=True, timeout=180)
    if result.returncode:
        raise RuntimeError('Docker operation failed: ' + args[0])
    return result.stdout.strip()


def fixtures(root):
    root.chmod(0o755)
    for index, label in enumerate(('A', 'B', 'G')):
        folder = root / label
        folder.mkdir()
        samples = b''.join(struct.pack('<h', int(4000 * math.sin(2 * math.pi * (220 + index * 110) * i / 8000))) for i in range(8000))
        def chunk(key, value):
            return key + struct.pack('<I', len(value)) + value + (b'\0' if len(value) % 2 else b'')
        info = b'INFO' + b''.join(chunk(k, v.encode() + b'\0') for k, v in [(b'INAM', 'S01-' + label), (b'IART', 'S01-' + label), (b'IPRD', 'Album-' + label)])
        wav = b'WAVE' + chunk(b'fmt ', struct.pack('<HHIIHH', 1, 1, 8000, 16000, 2, 16)) + chunk(b'LIST', info) + chunk(b'data', samples)
        (folder / ('S01-' + label + '.wav')).write_bytes(b'RIFF' + struct.pack('<I', len(wav)) + wav)
        def png_chunk(key, value):
            return struct.pack('>I', len(value)) + key + value + struct.pack('>I', zlib.crc32(key + value))
        pixels = (b'\0' + bytes((60 + index * 60, 50, 150)) * 32) * 32
        png = b'\x89PNG\r\n\x1a\n' + png_chunk(b'IHDR', struct.pack('>IIBBBBB', 32, 32, 8, 2, 0, 0, 0)) + png_chunk(b'IDAT', zlib.compress(pixels)) + png_chunk(b'IEND', b'')
        (folder / 'cover.png').write_bytes(png)
    (root / 'empty').mkdir()
    (root / 'data').mkdir()
    (root / 'data').chmod(0o777)


class Lab:
    def __init__(self, url):
        self.url = url
        self.password = secrets.token_hex(20)
        self.tokens = {}
        self.results = []

    def request(self, path, method='GET', body=None, token=None, form=False):
        headers = {}
        if token:
            headers['X-ND-Authorization'] = 'Bearer ' + token
        if body is not None:
            headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
            body = (urllib.parse.urlencode(body, doseq=True) if form else json.dumps(body)).encode()
        request = urllib.request.Request(self.url + path, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()

    def native(self, path, method='GET', body=None, who='admin'):
        status, raw = self.request('/api/' + path, method, body, self.tokens[who])
        if not 200 <= status < 300:
            raise RuntimeError('Native setup request rejected: ' + path.split('/')[0] + ' HTTP ' + str(status))
        return json.loads(raw) if raw else None

    def login(self, who):
        for _ in range(3):
            status, raw = self.request('/auth/login', 'POST', {'username': who, 'password': self.password})
            if status == 429:
                time.sleep(21)
                continue
            if status != 200:
                raise RuntimeError('Lab login failed')
            self.tokens[who] = json.loads(raw)['token']
            return
        raise RuntimeError('Lab login rate limit exhausted')

    def sub(self, who, endpoint, **params):
        salt = 's01-lab'
        auth = {'u': who, 't': hashlib.md5((self.password + salt).encode()).hexdigest(), 's': salt, 'v': '1.16.1', 'c': 'multisuite-s01', 'f': 'json'}
        return self.request('/rest/' + endpoint, 'POST', {**auth, **params}, form=True)

    def subjson(self, who, endpoint, **params):
        status, raw = self.sub(who, endpoint, **params)
        if status != 200:
            raise RuntimeError('Subsonic setup HTTP ' + str(status))
        return json.loads(raw)['subsonic-response']

    def check(self, name, passed):
        self.results.append({'case': name, 'passed': bool(passed)})
        print(('PASS ' if passed else 'FAIL ') + name, flush=True)

    def denied(self, who, endpoint, **params):
        status, raw = self.sub(who, endpoint, **params)
        if status in (401, 403, 404):
            return True
        try:
            return json.loads(raw)['subsonic-response']['status'] == 'failed'
        except (ValueError, KeyError, TypeError):
            return False

    def assign(self, who, libraries):
        self.native('user/' + self.users[who] + '/library', 'PUT', {'libraryIds': libraries})

    def exercise(self, container):
        status, _ = self.request('/auth/createAdmin', 'POST', {'username': 'admin', 'password': self.password})
        if status not in (200, 201):
            raise RuntimeError('Fresh lab admin creation failed')
        self.login('admin')
        libs = {}
        for label in ('A', 'B', 'G'):
            lib = self.native('library', 'POST', {'name': 'S01-' + label, 'path': '/fixtures/' + label, 'defaultNewUsers': False})
            libs[label] = int(lib['id'])
        self.users = {}
        for who, label in [('alice', 'A'), ('bob', 'B')]:
            user_id = str(uuid.uuid4())
            self.native('user', 'POST', {'id': user_id, 'userName': who, 'name': who, 'email': who + '@example.invalid', 'isAdmin': False, 'password': self.password})
            self.users[who] = user_id
            self.assign(who, [libs[label], libs['G']])
            self.login(who)
        # Wait for real scanning; never edit the database to manufacture results.
        for _ in range(60):
            self.subjson('admin', 'startScan', fullScan='true') if _ == 0 else None
            songs = self.native('song')
            if len(songs) == 3:
                break
            time.sleep(2)
        else:
            raise RuntimeError('Expected three synthetic tracks after scan')
        tracks = {label: next(s for s in songs if int(s['libraryId']) == libs[label]) for label in libs}
        for who, own, other in [('alice', 'A', 'B'), ('bob', 'B', 'A')]:
            visible = self.native('song', who=who)
            self.check(who + ': native listing isolated', {s['id'] for s in visible} == {tracks[own]['id'], tracks['G']['id']})
            status, _ = self.request('/api/song/' + tracks[other]['id'], token=self.tokens[who])
            self.check(who + ': native known ID denied', status in (403, 404))
            folders = self.subjson(who, 'getMusicFolders')['musicFolders']['musicFolder']
            self.check(who + ': Subsonic libraries isolated', {int(f['id']) for f in folders} == {libs[own], libs['G']})
            search = self.subjson(who, 'search3', query='S01-' + other)['searchResult3']
            self.check(who + ': private search empty', not any(search.get(k) for k in ('song', 'album', 'artist')))
            for endpoint in ('getSong', 'stream', 'download'):
                self.check(who + ': cross-library ' + endpoint + ' denied', self.denied(who, endpoint, id=tracks[other]['id'], format='raw'))
            status, audio = self.sub(who, 'download', id=tracks[own]['id'])
            self.check(who + ': own download positive control', status == 200 and audio.startswith(b'RIFF'))
            cover = self.subjson(who, 'getSong', id=tracks[other]['id'])
            # Use the admin-visible cover ID, not a guessed URL.
            cover_id = self.subjson('admin', 'getSong', id=tracks[other]['id'])['song'].get('coverArt')
            if not cover_id:
                raise RuntimeError('Synthetic cover was not indexed')
            status, art = self.sub('admin', 'getCoverArt', id=cover_id)
            self.check(who + ': cover positive control', status == 200 and (art.startswith(b'\x89PNG') or art.startswith(b'\xff\xd8')))
            self.check(who + ': foreign cover denied', self.denied(who, 'getCoverArt', id=cover_id))
            status, _ = self.request('/api/user/' + self.users[who] + '/library', 'PUT', {'libraryIds': list(libs.values())}, self.tokens[who])
            self.check(who + ': cannot grant self access', status == 403)
        # Publication at library granularity; reuse Bob's existing JWT and credentials.
        self.assign('bob', [libs['B'], libs['G'], libs['A']])
        self.check('grant: existing token sees A', tracks['A']['id'] in {s['id'] for s in self.native('song', who='bob')})
        playlist = self.subjson('bob', 'createPlaylist', name='S01-revocation', songId=tracks['A']['id'])['playlist']['id']
        self.assign('bob', [libs['B'], libs['G']])
        self.check('revoke: existing token loses A', tracks['A']['id'] not in {s['id'] for s in self.native('song', who='bob')})
        for endpoint in ('getSong', 'download', 'stream'):
            self.check('revoke: ' + endpoint, self.denied('bob', endpoint, id=tracks['A']['id'], format='raw'))
        entries = self.subjson('bob', 'getPlaylist', id=playlist)['playlist'].get('entry', [])
        self.check('revoke: playlist cannot expose A', all(s['id'] != tracks['A']['id'] for s in entries))
        docker('restart', container)
        wait_server(self)
        self.check('restart: IDs stable', {s['id'] for s in self.native('song')} == {s['id'] for s in songs})
        self.check('restart: permissions persist', tracks['A']['id'] not in {s['id'] for s in self.native('song', who='bob')})


def wait_server(lab):
    for _ in range(60):
        try:
            if lab.request('/ping')[0] == 200:
                return
        except (OSError, urllib.error.URLError):
            pass
        time.sleep(1)
    raise RuntimeError('Lab server startup timeout')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', default='s01-report.json')
    args = parser.parse_args()
    name = 'multisuite-s01-' + uuid.uuid4().hex[:12]
    report = {'image': IMAGE, 'checks': [], 'complete': False}
    lab = None
    try:
        with tempfile.TemporaryDirectory(prefix='multisuite-s01-') as tmp:
            root = pathlib.Path(tmp)
            fixtures(root)
            try:
                docker('run', '-d', '--name', name, '--label', 'multisuite.lab=s01', '--user', '1000:1000', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '-p', '127.0.0.1::4533', '--mount', 'type=bind,src=' + str(root) + ',dst=/fixtures,readonly', '--mount', 'type=bind,src=' + str(root / 'data') + ',dst=/data', '-e', 'ND_MUSICFOLDER=/fixtures/empty', '-e', 'ND_ENABLEEXTERNALSERVICES=false', '-e', 'ND_ENABLESHARING=false', IMAGE)
                report['image_id'] = docker('inspect', '--format', '{{.Image}}', name)
                port = docker('port', name, '4533/tcp').split(':')[-1]
                lab = Lab('http://127.0.0.1:' + port)
                wait_server(lab)
                lab.exercise(name)
                report['complete'] = True
            finally:
                # Only this invocation's random container, never compose down or shared volumes.
                subprocess.run(['docker', 'rm', '-f', name], capture_output=True, timeout=30)
    except Exception as error:
        report['setup_error'] = str(error) if isinstance(error, RuntimeError) else type(error).__name__
    finally:
        if lab:
            report['checks'] = lab.results
        pathlib.Path(args.report).write_text(json.dumps(report, indent=2) + '\n')
    if not report['complete'] or not all(c['passed'] for c in report['checks']):
        print('S01 failed; see sanitized report.')
        return 1
    print('S01 API checks passed; browser/mobile and file-level publication remain separate.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
