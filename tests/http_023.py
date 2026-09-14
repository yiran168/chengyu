"""Catalog authorization and selection through actual POST/CSRF endpoints."""
expect_post(guest,'media_catalog',401)
bad23=admin.post(base+'/action.php',data={'action':'media_catalog','csrf':'invalid'},headers={'Accept':'application/json'})
check('023 catalog requires a valid CSRF token',bad23.status_code==419,bad23.text[:180])
expect_post(user,'media_catalog',403,scope='all')
expect_post(admin,'media_catalog',400,scope='private')
save_group('community',user_uploads='1')
def upload23(session,name,private=False):
    with (ROOT/'site/assets/art/icon-gallery.webp').open('rb') as picture:
        response=session.post(base+'/action.php',data={'action':'upload','csrf':csrf(session),'private':'1' if private else '0'},files={'file':(name,picture,'image/webp')},headers={'Accept':'application/json'})
    check('023 upload fixture '+name,response.status_code==200,response.text[:180])
    return response.json()['media_id']
own23=upload23(user,'member%_!23.webp')
hidden23=upload23(admin,'catalog-private23.webp',True)
ids23=[upload23(admin,'catalog-page23-'+str(n)+'.webp') for n in range(13)]
mine23=expect_post(user,'media_catalog',q='%_!23').json()['catalog']
check('023 literal filename search finds only owned public image',[row['id'] for row in mine23['items']]==[own23])
check('023 catalog returns no storage keys or owner details',set(mine23['items'][0])=={'id','name','bytes','mime'})
expect_post(user,'media_select',404,media_id=ids23[0])
expect_post(admin,'media_select',404,media_id=hidden23)
expect_post(admin,'media_select',404,media_id=2147483647)
first23=expect_post(admin,'media_catalog',q='catalog-page23-').json()['catalog']
second23=expect_post(admin,'media_catalog',q='catalog-page23-',before=first23['next_before']).json()['catalog']
check('023 real keyset pages contain twelve then one without duplicates',len(first23['items'])==12 and len(second23['items'])==1 and len({row['id'] for row in first23['items']+second23['items']})==13 and not second23['has_more'])
all23=expect_post(admin,'media_catalog',scope='all',q='member%_!23').json()['catalog']
check('023 staff can find member public images',all23['items'][0]['id']==own23)
before_count23=db_one('SELECT COUNT(*) AS n FROM cy_media')['n']
expect_post(user,'media_select',media_id=own23)
expect_post(admin,'media_select',media_id=own23)
check('023 repeated selection creates no extra media record',db_one('SELECT COUNT(*) AS n FROM cy_media')['n']==before_count23)
conn.execute('UPDATE cy_media SET is_private=1 WHERE id=?',(own23,));conn.commit()
expect_post(user,'media_select',404,media_id=own23)
check('023 revoked images disappear from subsequent catalogs',expect_post(user,'media_catalog',q='%_!23').json()['catalog']['items']==[])
conn.execute('UPDATE cy_media SET is_private=0 WHERE id=?',(own23,));conn.commit()
edit23=html(admin,'/admin/index.php?tab=edit_content&id='+str(article_id22))
check('023 each public upload field gets a reusable image picker',edit23.select_one('[name=gallery_image_1]').find_parent(class_='upload-field').select_one('[data-media-open]') is not None)
check('023 picker is a single shared dialog with hidden progressive controls',len(edit23.select('#media-picker'))==1 and all(button.has_attr('hidden') for button in edit23.select('[data-media-open]')))
