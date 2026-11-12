#!/usr/bin/env python3
"""Native libcurl-Gegenversuche im vorhandenen bwrap-Netznamespace, nur Loopback.
Keine Pakete nachladen. TLS und minimale HTTP/2-Frames aus der Standardbibliothek.
Testthreads gehören zum Prüfprozess und werden vor dessen Ende beendet.
"""
import concurrent.futures
import json
import os
from pathlib import Path
import socket
import ssl
import struct
import subprocess
import tempfile
import threading

ROOT = Path(__file__).resolve().parents[2]
BODY = b'{"data":"AA0CB800000000000003320106"}'
RESPONSE = b'{"errorCode":0}'


def exact(conn, size):
    data = b''
    while len(data) < size:
        block = conn.recv(size - len(data))
        if not block:
            raise EOFError()
        data += block
    return data


def frame(kind, flags, stream, payload=b''):
    return len(payload).to_bytes(3, 'big') + bytes([kind, flags]) + stream.to_bytes(4, 'big') + payload


class Fixture:
    def __init__(self, cert, key, fault):
        self.fault = fault
        self.posts = self.connections = self.headers = 0
        self.alpn, self.versions, self.errors, self.clients = [], [], [], []
        self.lock = threading.Lock()
        self.stop = threading.Event()
        self.context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        self.context.load_cert_chain(cert, key)
        self.context.set_alpn_protocols(['h2', 'http/1.1'])
        self.sock = socket.socket()
        self.sock.bind(('127.0.0.1', 0))
        self.sock.listen(8)
        self.sock.settimeout(0.1)
        self.port = self.sock.getsockname()[1]
        self.pool = concurrent.futures.ThreadPoolExecutor(max_workers=4)
        self.worker = threading.Thread(target=self.serve)
        self.worker.start()

    def serve(self):
        while not self.stop.is_set():
            try:
                raw, _ = self.sock.accept()
            except TimeoutError:
                continue
            with self.lock:
                self.connections += 1
            self.pool.submit(self.handle, raw)

    def handle(self, raw):
        try:
            raw.settimeout(18)
            with self.context.wrap_socket(raw, server_side=True) as conn:
                with self.lock:
                    self.alpn.append(conn.selected_alpn_protocol())
                    self.clients.append(conn)
                if self.fault == 'drop-tls':
                    return
                if conn.selected_alpn_protocol() == 'h2':
                    self.h2(conn)
                else:
                    self.http1(conn)
        except (EOFError, ConnectionError, TimeoutError, ssl.SSLError):
            pass  # Erwartete Transportabbrüche; kein Parser-/Assertion-Fehler.
        except Exception as exc:
            with self.lock:
                self.errors.append(type(exc).__name__ + ': ' + str(exc))
        finally:
            raw.close()

    def complete(self, body):
        assert body == BODY, 'Unerwarteter POST-Body'
        with self.lock:
            self.posts += 1
            return self.posts

    def h2(self, conn):
        assert exact(conn, 24) == b'PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n'
        conn.sendall(frame(4, 0, 0))  # SETTINGS
        streams = {}
        while not self.stop.is_set():
            head = exact(conn, 9)
            size, kind, flags, stream = int.from_bytes(head[:3], 'big'), head[3], head[4], int.from_bytes(head[5:], 'big')
            payload = exact(conn, size)
            if kind == 4 and not flags & 1:
                conn.sendall(frame(4, 1, 0))
            elif kind == 1:
                # nghttp2 setzt :method POST als erstes indiziertes HPACK-Feld (Index 3).
                assert flags & 4 and payload.startswith(b'\x83'), 'POST-HEADERS fehlen'
                streams[stream] = bytearray()
                with self.lock:
                    self.headers += 1
                    self.versions.append('HTTP/2')
            elif kind == 0:
                streams[stream].extend(payload)
                if flags & 1:
                    number = self.complete(bytes(streams.pop(stream)))
                    if number == 1:
                        conn.sendall(frame(3, 0, stream, struct.pack('!I', 7)))  # REFUSED_STREAM
                    else:
                        conn.sendall(frame(1, 4, stream, b'\x88') + frame(0, 1, stream, RESPONSE))

    def http1(self, conn):
        while not self.stop.is_set():
            header = b''
            while not header.endswith(b'\r\n\r\n'):
                header += exact(conn, 1)
                assert len(header) < 16384
            lines = header.decode('ascii').split('\r\n')
            method, path, version = lines[0].split(' ')
            if method == 'GET' and path == '/prime':
                conn.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nprime')
                continue
            assert (method, path, version) == ('POST', '/devices/commands/offline-e20', 'HTTP/1.1')
            fields = dict(line.lower().split(':', 1) for line in lines[1:] if ':' in line)
            size = int(fields['content-length'])
            assert size == len(BODY)
            with self.lock:
                self.headers += 1
                self.versions.append(version)
            if self.fault == 'expect' and 'expect' in fields and self.headers == 1:
                conn.sendall(b'HTTP/1.1 417 Offline\r\nContent-Length: 0\r\n\r\n')
                continue
            if 'expect' in fields:
                conn.sendall(b'HTTP/1.1 100 Continue\r\n\r\n')
            if self.fault == 'drop-partial':
                exact(conn, size // 2)
                return
            number = self.complete(exact(conn, size))
            if self.fault in ('drop-full', 'reused') and number == 1:
                return
            if self.fault == 'timeout':
                self.stop.wait(17)
                return
            if self.fault == 'truncated':
                conn.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n{')
                return
            status = int(self.fault) if self.fault.isdigit() else 200
            if self.fault in ('expect', 'expect-after-continue') and self.headers == 1:
                status = 417
            extra = b'WWW-Authenticate: Basic realm="offline"\r\n' if status == 401 else b''
            conn.sendall(f'HTTP/1.1 {status} Offline\r\nContent-Length: {len(RESPONSE)}\r\n'.encode() + extra + b'\r\n' + RESPONSE)
            if self.fault not in ('expect',):
                return

    def close(self):
        self.stop.set()
        self.worker.join()
        for conn in self.clients:
            try:
                conn.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
        self.sock.close()
        self.pool.shutdown(wait=True)
        assert not self.errors, self.errors


def main():
    # Kein unsicherer Fallback: nur eigener Netzwerknamespace mit ausschließlich lo.
    outer = os.environ.get('ROBOHUB_TEST_OUTER_NETNS')
    if not outer or os.readlink('/proc/self/ns/net') == outer:
        raise RuntimeError('bwrap --unshare-net erforderlich')
    if [name for _, name in socket.if_nameindex()] != ['lo']:
        raise RuntimeError('Nur isoliertes Loopback erlaubt')
    with tempfile.TemporaryDirectory(prefix='robohub-b8-tls-') as work:
        ca, key = str(Path(work) / 'cert.pem'), str(Path(work) / 'key.pem')
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-subj', '/CN=euprod.mzrobo.com', '-addext', 'subjectAltName=DNS:euprod.mzrobo.com',
                        '-keyout', key, '-out', ca], check=True, capture_output=True)
        cases = [
            ('control-h2', 'refused', 2, 1, 3),
            ('fixed', 'refused', 1, 1, 2),
            ('control-expect', 'expect', 1, 1, 2),
            ('control-expect-bounded', 'expect', 0, 1, 2),
            ('control-expect', 'expect-after-continue', 1, 1, 2),
            ('fixed', 'expect', 1, 1, 2),
            ('control-reused', 'reused', 2, 1, 2),
            ('fixed-reused', 'reused', 1, 1, 0),
            ('fixed', 'drop-tls', 0, 1, 0),
            ('fixed', 'drop-partial', 0, 1, 0),
            ('fixed', 'drop-full', 1, 1, 0),
            ('fixed', 'timeout', 1, 1, 0),
            ('fixed', 'truncated', 1, 1, 2),
            ('setopt-false', '200', 0, 0, 0),
            *[('fixed', str(code), 1, 1, 2) for code in (200, 401, 429, 500, 302, 307, 417)],
        ]
        for mode, fault, posts, execs, version in cases:
            fixture = Fixture(ca, key, fault)
            try:
                result = subprocess.run(['php', str(ROOT / 'tests/eureka/diagnostic-native-client.php'),
                                         str(fixture.port), ca, mode], capture_output=True, text=True, timeout=22)
                assert result.returncode == 0, result.stderr
                client = json.loads(result.stdout)
            finally:
                fixture.close()
            summary = dict(mode=mode, fault=fault, posts=fixture.posts, connections=fixture.connections,
                           alpn=fixture.alpn, receivedVersions=fixture.versions, requestHeaders=fixture.headers, **client)
            print(json.dumps(summary), flush=True)
            assert fixture.posts == posts, summary
            assert client['execs'] == execs and client['closes'] == 1 and client['consumedWrites'] == 1, summary
            assert client['httpVersion'] == version, summary
            assert client['transportMarked'] == (execs > 0), summary
            assert client['secondBlocked'] and client['newBlocked'], summary
            if mode == 'control-expect' and fault == 'expect':
                assert fixture.headers == 2, summary
            if mode.startswith('fixed'):
                assert 'h2' not in fixture.alpn, summary
                assert fixture.connections == (2 if mode == 'fixed-reused' else 1), summary
            if mode == 'setopt-false':
                assert fixture.connections == 0, summary
            if fault == 'timeout':
                assert client['errno'] == 28, summary
            if fault == 'truncated':
                assert client['errno'] == 18, summary
        print(f'{len(cases)} native Gegenversuche, 0 Fehler', flush=True)


if __name__ == '__main__':
    main()
