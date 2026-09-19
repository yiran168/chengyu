"""Real multipart/chunk requests, with no client MIME or filename trust."""
fixtures232={key:base64.b64decode(value) for key,value in json.loads((ROOT/'tests/fixtures/file-types.json').read_text()).items()}
fixtures232['txt']='UTF-8 澄屿 upload\n'.encode()
fixtures232['mp3']=(b'\xff\xfb\x90\x00'+bytes(413))*2
fixtures232['mp4']=(24).to_bytes(4,'big')+b'ftypisom'+(512).to_bytes(4,'big')+b'isommp42'+(12).to_bytes(4,'big')+b'mdatDATA'
types232={'png':'image/png','jpg':'image/jpeg','gif':'image/gif','webp':'image/webp','pdf':'application/pdf','zip':'application/zip','txt':'text/plain','mp3':'audio/mpeg','mp4':'video/mp4'}
for ext232,data232 in fixtures232.items():
    response232=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin),'private':'0'},files={'file':('wrong.exe',data232,'application/x-php')},headers={'Accept':'application/json'})
    check('0232 real multipart detects '+ext232,response232.status_code==200,response232.text)
    if response232.status_code==200:
        id232=response232.json()['media_id'];download232=admin.get(base+'/media.php',params={'id':id232})
        check('0232 served MIME and bytes '+ext232,download232.status_code==200 and download232.content==data232 and download232.headers.get('Content-Type','').split(';')[0]==types232[ext232])
        check('0232 nosniff on '+ext232,download232.headers.get('X-Content-Type-Options')=='nosniff')
        if ext232 not in ['png','jpg','gif','webp']:
            check('0232 non-image forced private '+ext232,guest.get(base+'/media.php',params={'id':id232}).status_code in [401,403])
for name232,data232 in {'php':b'<?php echo 1;','svg':b'<svg><script>1</script></svg>','html':b'<!doctype html><html>test</html>','truncated-png':fixtures232['png'][:-4]}.items():
    response232=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin)},files={'file':('image.png',data232,'image/png')},headers={'Accept':'application/json'})
    check('0232 multipart rejects forged '+name232,response232.status_code==400,response232.text)

# A real PNG with a large ancillary chunk forces the guarded merge to seek beyond its prefix.
import struct, zlib
extra232=b'Note\0'+b'A'*300000
chunk232=struct.pack('>I',len(extra232))+b'tEXt'+extra232+struct.pack('>I',zlib.crc32(b'tEXt'+extra232))
image232=fixtures232['png'][:33]+chunk232+fixtures232['png'][33:]
start232=post(admin,'upload_start',name='large.png',bytes=len(image232),sha256=hashlib.sha256(image232).hexdigest())
check('0232 image chunk upload starts',start232.status_code==200,start232.text)
if start232.status_code==200:
    manifest232=start232.json()['upload'];key232=manifest232['upload_key'];chunk_size232=int(manifest232['chunk_bytes'])
    for index232,offset232 in enumerate(range(0,len(image232),chunk_size232)):
        part232=admin.post(base+'/action.php',data={'action':'upload_chunk','csrf':csrf(admin),'upload_key':key232,'part':index232},files={'file':('piece.bin',image232[offset232:offset232+chunk_size232],'application/octet-stream')},headers={'Accept':'application/json'})
        check('0232 binary image chunk accepted',part232.status_code==200,part232.text)
    finish232=post(admin,'upload_finish',upload_key=key232)
    check('0232 image chunk merge verifies actual content',finish232.status_code==200,finish232.text)
    if finish232.status_code==200:
        download232=admin.get(base+'/media.php',params={'id':finish232.json()['media_id']})
        check('0232 merged image downloads without guard bytes',download232.content==image232 and download232.headers.get('Content-Type')=='image/png')

system232=admin.get(base+'/admin/index.php?tab=system')
check('0232 backend diagnoses built-in MIME checks',system232.status_code==200 and ('无需 Fileinfo' in system232.text or 'Fileinfo is not required' in system232.text))
