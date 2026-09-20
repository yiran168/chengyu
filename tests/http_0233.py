"""Additional real uploads for the Fileinfo-free compatibility review."""
# Isolate this group from earlier synthetic uploads; never alter production limits.
conn.execute('DELETE FROM cy_rate_limits');conn.commit()
def upload233(name,data,mime):
    response=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin),'private':'0'},files={'file':(name,data,'application/octet-stream')},headers={'Accept':'application/json'})
    check('0233 multipart '+name,response.status_code==200,response.text)
    if response.status_code!=200:return None
    mid=response.json()['media_id'];served=admin.get(base+'/media.php',params={'id':mid})
    check('0233 actual MIME and bytes '+name,served.status_code==200 and served.content==data and served.headers.get('Content-Type','').split(';')[0]==mime)
    return mid
variants233=json.loads((ROOT/'tests/fixtures/file-types-variants.json').read_text())
for name233,encoded233 in variants233.items():
    mime233='image/jpeg' if name233.startswith('jpeg-') else ('image/webp' if name233.startswith('webp-') else 'image/png')
    upload233(name233,base64.b64decode(encoded233),mime233)
for name233,data233 in [('gbk.txt','中文内容'.encode('gbk')),('gb18030.txt','中文🌸'.encode('gb18030')),('pk.txt',b'PK competition notes')]:
    mid233=upload233(name233,data233,'text/plain')
    if mid233:check('0233 legacy text remains private '+name233,guest.get(base+'/media.php',params={'id':mid233}).status_code in [401,403])

comment233=b'PK\x05\x06'+struct.pack('<HHHHIIH',0,0,65535,65535,4294967295,4294967295,0)
zip233=fixtures232['zip'][:-2]+struct.pack('<H',len(comment233))+comment233
upload233('zip-comment.zip',zip233,'application/zip')
jpg233=fixtures232['jpg'][:2]+(b'\xff\xfe'+struct.pack('>H',60002)+b'A'*60000)*5+fixtures232['jpg'][2:]
upload233('large-metadata.jpg',jpg233,'image/jpeg')
start233=post(admin,'upload_start',name='large-metadata.jpg',bytes=len(jpg233),sha256=hashlib.sha256(jpg233).hexdigest(),private='0')
check('0233 JPEG resumable start',start233.status_code==200,start233.text)
if start233.status_code==200:
    manifest233=start233.json()['upload'];key233=manifest233['upload_key'];step233=int(manifest233['chunk_bytes'])
    for part233,at233 in enumerate(range(0,len(jpg233),step233)):
        response233=admin.post(base+'/action.php',data={'action':'upload_chunk','csrf':csrf(admin),'upload_key':key233,'part':part233},files={'file':('chunk.bin',jpg233[at233:at233+step233],'application/octet-stream')},headers={'Accept':'application/json'})
        check('0233 JPEG chunk '+str(part233),response233.status_code==200,response233.text)
    result233=post(admin,'upload_finish',upload_key=key233)
    check('0233 JPEG merge skips metadata',result233.status_code==200,result233.text)
    if result233.status_code==200:
        served233=guest.get(base+'/media.php',params={'id':result233.json()['media_id']})
        check('0233 merged JPEG public bytes',served233.status_code==200 and served233.content==jpg233 and served233.headers.get('Content-Type')=='image/jpeg')

for data233 in ['中文'.encode('gbk')+b'<script>1</script>',b'\xef\xbb\xbf\xc0\xaf',b'\x81\x30\x81']:
    response233=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin)},files={'file':('bad.txt',data233,'text/plain')},headers={'Accept':'application/json'})
    check('0233 invalid legacy text or markup rejected',response233.status_code==400,response233.text)
