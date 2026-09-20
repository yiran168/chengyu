"""Complete real HTTP paths for strengthened content checks and resumable recovery."""
conn.execute('DELETE FROM cy_rate_limits');conn.commit()
def upload234(name,data,mime):
    response=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin),'private':'0'},files={'file':(name,data,'image/jpeg')},headers={'Accept':'application/json'})
    check('0234 ordinary upload '+name,response.status_code==200,response.text)
    if response.status_code!=200:return None
    mid=response.json()['media_id'];served=admin.get(base+'/media.php',params={'id':mid})
    check('0234 served content '+name,served.status_code==200 and served.content==data and served.headers.get('Content-Type','').split(';')[0]==mime)
    check('0234 download protections '+name,served.headers.get('X-Content-Type-Options')=='nosniff' and 'sandbox' in served.headers.get('Content-Security-Policy',''))
    return mid
def box234(kind,body,wide=False):
    return (struct.pack('>I',1)+kind+struct.pack('>Q',16+len(body)) if wide else struct.pack('>I',8+len(body))+kind)+body
brands234=b'isom'+struct.pack('>I',512)+b'isommp42'
ftyp234=box234(b'ftyp',brands234);mdat234=box234(b'mdat',b'DATA')
videos234={'large-padding':box234(b'free',b'P'*300000)+ftyp234+mdat234,'extended-ftyp':box234(b'ftyp',brands234,True)+mdat234,
    'extended-data':ftyp234+box234(b'mdat',b'DATA',True),'to-eof-data':ftyp234+struct.pack('>I',0)+b'mdatDATA'}
for name234,data234 in videos234.items():
    mid234=upload234(name234+'.mp4',data234,'video/mp4')
    if mid234:
        check('0234 private video '+name234,guest.get(base+'/media.php',params={'id':mid234}).status_code in [401,403])
        response234=admin.get(base+'/media.php',params={'id':mid234},headers={'Range':'bytes=4-11'})
        check('0234 video range '+name234,response234.status_code==206 and response234.content==data234[4:12] and response234.headers.get('Content-Range')==f'bytes 4-11/{len(data234)}')
pngs234=json.loads((ROOT/'tests/fixtures/png-formats.json').read_text())
for name234 in ['color-0-depth-16-interlace-0','color-2-depth-16-interlace-1','color-3-depth-1-interlace-0','color-4-depth-8-interlace-1','color-6-depth-16-interlace-0']:
    upload234(name234+'.png',base64.b64decode(pngs234[name234]),'image/png')
for name234,data234 in [('fake.mp4',ftyp234+b'X'*20),('broken.png',fixtures232['png'][:29]+b'\0'*4+fixtures232['png'][33:])]:
    response234=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin)},files={'file':(name234,data234,'image/png')},headers={'Accept':'application/json'})
    check('0234 malformed content rejected '+name234,response234.status_code==400,response234.text)
gif234=fixtures232['gif'];at234=gif234.index(b',')
badframes234={'oversized.gif':gif234[:at234+5]+struct.pack('<HH',65535,65535)+gif234[at234+9:]}
for name234 in ['webp-alpha','webp-animation']:
    data234=base64.b64decode(variants233[name234]);badframes234[name234+'.webp']=data234[:24]+b'\0'*6+data234[30:]
for name234,data234 in badframes234.items():
    response234=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin)},files={'file':(name234,data234,'image/gif')},headers={'Accept':'application/json'})
    check('0234 inconsistent first frame blocked '+name234,response234.status_code==400,response234.text)

data234=videos234['large-padding']
start234=post(admin,'upload_start',name='resume.mp4',bytes=len(data234),sha256=hashlib.sha256(data234).hexdigest(),private='0')
check('0234 start resumable MP4',start234.status_code==200,start234.text)
if start234.status_code==200:
    up234=start234.json()['upload'];key234=up234['upload_key'];step234=int(up234['chunk_bytes'])
    def put234(i):
        return admin.post(base+'/action.php',data={'action':'upload_chunk','csrf':csrf(admin),'upload_key':key234,'part':i},files={'file':('chunk.bin',data234[i*step234:(i+1)*step234],'application/octet-stream')},headers={'Accept':'application/json'})
    for i234 in range(int(up234['chunk_count'])):
        response234=put234(i234);check('0234 video chunk '+str(i234),response234.status_code==200,response234.text)
    # Deliberately corrupt only this disposable local test site's stored first part.
    dir234=site/'storage/chunks'/key234;part234=dir234/'0.php';original234=part234.read_bytes();part234.write_bytes(original234[:-1]+bytes([original234[-1]^1]))
    failed234=post(admin,'upload_finish',upload_key=key234)
    check('0234 damaged merge rejected with resend advice',failed234.status_code==409 and ('分片' in failed234.text or 'chunks' in failed234.text),failed234.text)
    check('0234 rejected assembly removed',not(dir234/'assembled.php').exists())
    state234=post(admin,'upload_status',upload_key=key234)
    check('0234 resume status requests only damaged part',state234.status_code==200 and state234.json()['upload']['parts']==[1],state234.text)
    response234=put234(0);check('0234 resending damaged part succeeds',response234.status_code==200,response234.text)
    done234=post(admin,'upload_finish',upload_key=key234);check('0234 repaired MP4 merges',done234.status_code==200,done234.text)
    if done234.status_code==200:
        mid234=done234.json()['media_id'];served234=admin.get(base+'/media.php',params={'id':mid234})
        check('0234 merged video matches original',served234.status_code==200 and served234.content==data234 and served234.headers.get('Content-Type')=='video/mp4')
        retry234=post(admin,'upload_finish',upload_key=key234);check('0234 repeated finish returns one media',retry234.status_code==200 and retry234.json()['media_id']==mid234)
        check('0234 merged video remains private',guest.get(base+'/media.php',params={'id':mid234}).status_code in [401,403])
        check('0234 completed chunks cleaned',not(dir234/'0.php').exists() and not(dir234/'1.php').exists() and not(dir234/'assembled.php').exists())
