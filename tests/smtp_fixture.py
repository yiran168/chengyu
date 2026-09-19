"""Local TLS SMTP receiver for tests. Never use its private key on a deployed site."""
from pathlib import Path
import base64, email, socketserver, ssl, subprocess, threading
from email import policy

class SmtpFixture:
    def __init__(self, directory: Path):
        self.messages=[]
        self.credentials=None
        self.auth_successes=0
        self.cert=directory/'smtp-test.crt'; key=directory/'smtp-test.key'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(self.cert),'-subj','/CN=localhost','-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        key.chmod(0o600)
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(str(self.cert),str(key))
        owner=self
        class Handler(socketserver.BaseRequestHandler):
            def handle(self):
                try:
                    with context.wrap_socket(self.request,server_side=True) as conn:
                        conn.settimeout(10)
                        stream=conn.makefile('rwb',buffering=0)
                        def send(s):stream.write(s.encode()+b'\r\n')
                        authenticated=False
                        send('220 localhost isolated SMTP fixture')
                        while True:
                            line=stream.readline(4096)
                            if not line:break
                            command=line.decode(errors='replace').strip()
                            if command.startswith('EHLO'):send('250 localhost')
                            elif command=='AUTH LOGIN':
                                send('334 VXNlcm5hbWU6')
                                username=base64.b64decode(stream.readline(4096).rstrip(b'\r\n'),validate=True)
                                send('334 UGFzc3dvcmQ6')
                                password=base64.b64decode(stream.readline(16384).rstrip(b'\r\n'),validate=True)
                                authenticated=owner.credentials is not None and (username,password)==tuple(value.encode('utf-8') for value in owner.credentials)
                                if authenticated:owner.auth_successes+=1
                                send('235 Authenticated' if authenticated else '535 Authentication failed')
                            elif command.startswith(('MAIL FROM:','RCPT TO:')):
                                send('250 OK' if owner.credentials is None or authenticated else '530 Authentication required')
                            elif command=='DATA':
                                if owner.credentials is not None and not authenticated:
                                    send('530 Authentication required');continue
                                send('354 End with dot')
                                chunks=[]
                                while True:
                                    chunk=stream.readline(65536)
                                    if not chunk or chunk==b'.\r\n':break
                                    chunks.append(chunk)
                                message=email.message_from_bytes(b''.join(chunks),policy=policy.default)
                                owner.messages.append({'to':str(message['To']).strip('<>'),'subject':str(message['Subject']),'body':message.get_content()})
                                send('250 accepted')
                            elif command=='QUIT':send('221 bye');break
                            else:send('500 unsupported fixture command')
                except (OSError,ssl.SSLError,ValueError):pass
        class Server(socketserver.ThreadingTCPServer):
            allow_reuse_address=True
            daemon_threads=True
        self.server=Server(('127.0.0.1',0),Handler);self.port=self.server.server_address[1]
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
    def close(self):
        self.server.shutdown();self.server.server_close();self.thread.join(timeout=2)
