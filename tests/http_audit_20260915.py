"""Credential byte preservation and missing editor targets over real HTTP/TLS."""
save_group('community',submissions_enabled='1')
for session,label in [(user,'member'),(admin,'staff')]:
    response=session.get(base+'/index.php?r=write&id=2147483647')
    check('audit missing editor target returns 404 for '+label,response.status_code==404)
    check('audit missing editor does not become a new article for '+label,
          not BeautifulSoup(response.text,'html.parser').select('input[name=action][value=content_save]'))
    for suffix in ['', '&id=0']:
        response=session.get(base+'/index.php?r=write'+suffix)
        form=BeautifulSoup(response.text,'html.parser').select_one('input[name=action][value=content_save]')
        check('audit new editor remains available for '+label+suffix,response.status_code==200 and form is not None)

owner_id_audit=db_one("SELECT id FROM cy_users WHERE username='alice'")['id']
owned_audit=db_one('SELECT id FROM cy_contents WHERE author_id=? ORDER BY id LIMIT 1',(owner_id_audit,))['id']
for session,label in [(user,'owner'),(admin,'staff')]:
    response=session.get(base+'/index.php?r=write&id='+str(owned_audit))
    item_field=BeautifulSoup(response.text,'html.parser').select_one('input[name=id]')
    check('audit existing editor remains available for '+label,response.status_code==200 and item_field is not None and item_field.get('value')==str(owned_audit))
check('audit editor still rejects another member',other.get(base+'/index.php?r=write&id='+str(owned_audit)).status_code==403)

smtp.credentials=('audit_user','  Isolated-Smtp-123  ')
try:
    save_group('email',smtp_enabled='1',smtp_host='127.0.0.1',smtp_port=str(smtp.port),smtp_security='ssl',smtp_user=smtp.credentials[0],smtp_password=smtp.credentials[1],smtp_from='sender@example.test',email_registration_required='0')
    for label in ['initial save','blank secret update']:
        if label=='blank secret update':save_group('email',smtp_password='')
        accepted=smtp.auth_successes; delivered=len(smtp.messages)
        expect_post(admin,'admin_mail_test')
        check('audit exact SMTP credential bytes authenticate after '+label,smtp.auth_successes==accepted+1)
        check('audit authenticated SMTP message is delivered after '+label,len(smtp.messages)==delivered+1)
finally:
    smtp.credentials=None
    save_group('email',smtp_enabled='0',smtp_user='',clear_smtp_password='1',email_registration_required='0')
