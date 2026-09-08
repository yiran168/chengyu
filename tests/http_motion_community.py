"""Version 0.11 actual HTTP / TLS SMTP regressions, run by http_smoke.py."""
import re
if 'conn' in globals(): conn.close()
conn=sqlite3.connect(work/'private/site.sqlite');conn.row_factory=sqlite3.Row
for tab in ['motion','threads']:
    r=admin.get(base+'/admin/index.php',params={'tab':tab});check('new admin screen '+tab,r.status_code==200,r.text[-250:])
    r=user.get(base+'/admin/index.php',params={'tab':tab});check('new admin screen denies user '+tab,r.status_code==403)
for asset in ['motion.js','motion.css','features.js','motion-studio.js']:
    r=guest.get(base+'/assets/'+asset);check('motion resource available '+asset,r.status_code==200 and len(r.content)>500)
page=html(admin,'/admin/index.php?tab=motion')
form=page.select_one('input[name=action][value=admin_motion]').find_parent('form')
values={}
for node in form.select('input[name],select[name],textarea[name]'):
    if node.name=='select':values[node['name']]=(node.select_one('option[selected]') or node.select_one('option'))['value']
    elif node.get('type')!='checkbox' or node.has_attr('checked'):values[node['name']]=node.get('value','')
before=html(guest).html.get('data-theme')
values.update(motion_curve='custom',curve_x1='18',curve_y1='142',curve_x2='62',curve_y2='100',motion_stagger='77')
r=admin.post(base+'/action.php',data=values,headers={'Accept':'application/json'});check('motion inspector saves shared schema',r.status_code==200,r.text)
root=html(guest).html;config=json.loads(root['data-motion-settings']);check('motion configuration rendered into actual frontend',config['motion_curve']=='custom' and config['motion_stagger']==77)
check('motion save does not reset unrelated theme',root['data-theme']==before)
expect_post(user,'admin_motion',403,motion_curve='linear')
# Protected comments must not leak material posted in a locked discussion.
locked=create_content(kind='thread',thread_mode='discussion',access_level='password',content_password='protected-secret',title='Private comment gate')
conn.execute("INSERT INTO cy_comments(content_id,user_id,body,status,created_at) VALUES(?,?,?,'approved',?)",(locked,uid,'COMMENT-MUST-STAY-PRIVATE',int(time.time())));conn.commit()
check('locked content does not leak approved comments','COMMENT-MUST-STAY-PRIVATE' not in guest.get(base+f'/index.php?r=article&id={locked}').text)
expect_post(user,'content_unlock',content_id=locked,content_password='protected-secret')
check('authorized comment can be read','COMMENT-MUST-STAY-PRIVATE' in user.get(base+f'/index.php?r=article&id={locked}').text)
expect_post(user,'logout')
check('logout revokes password-based comment grant','COMMENT-MUST-STAY-PRIVATE' not in user.get(base+f'/index.php?r=article&id={locked}').text)
expect_post(user,'login',identifier='alice',password='TestPassword!2026')
# Poll with server-owned options, hidden results and immutable vote.
poll=create_content(kind='thread',thread_mode='poll',title='HTTP poll',poll_question='Which path?',poll_options='Path A\nPath B',poll_results='after_vote')
opts=conn.execute('SELECT id FROM cy_poll_options WHERE content_id=? ORDER BY id',(poll,)).fetchall()
page=html(user,f'/index.php?r=article&id={poll}');check('poll has real POST form and no private counts',bool(page.select_one('input[name=action][value=poll_vote]')) and not page.select('.poll-results'))
expect_post(guest,'poll_vote',401,content_id=poll,option_id=opts[0]['id'])
expect_post(user,'poll_vote',content_id=poll,option_id=opts[0]['id']);expect_post(user,'poll_vote',content_id=poll,option_id=opts[0]['id'])
expect_post(user,'poll_vote',409,content_id=poll,option_id=opts[1]['id'])
check('duplicate HTTP votes create one row',db_one('SELECT COUNT(*) n FROM cy_poll_votes WHERE content_id=?',(poll,))['n']==1)
check('voter can see results',bool(html(user,f'/index.php?r=article&id={poll}').select('.poll-results')))
check('other account cannot see private results',not html(other,f'/index.php?r=article&id={poll}').select('.poll-results'))
# Question answers use the same moderation boundary.
question=create_content(kind='thread',thread_mode='question',title='HTTP question')
expect_post(user,'comment',content_id=question,body='An answer from the HTTP user')
answer=db_one('SELECT id FROM cy_comments WHERE content_id=? ORDER BY id DESC',(question,))['id']
expect_post(admin,'answer_accept',400,content_id=question,comment_id=answer)
expect_post(admin,'admin_moderate',entity='comments',id=answer,status='approved')
expect_post(other,'answer_accept',403,content_id=question,comment_id=answer)
expect_post(admin,'answer_accept',content_id=question,comment_id=answer)
check('accepted answer rendered and anchored',f'comment-{answer}' in user.get(base+f'/index.php?r=article&id={question}').text)
expect_post(admin,'admin_moderate',entity='comments',id=answer,status='rejected')
check('rejecting an answer clears accepted state',db_one('SELECT accepted_comment_id FROM cy_threads WHERE content_id=?',(question,))['accepted_comment_id']==0)
# Genuine local TLS SMTP exchange and registration; no production mail service.
save_group('email',smtp_enabled='1',smtp_host='127.0.0.1',smtp_port=str(smtp.port),smtp_security='ssl',smtp_from='sender@example.test',email_registration_required='1')
reg=requests.Session();reg_mail='verified-http@example.test'
check('mandatory code visible in registration form',bool(html(reg,'/index.php?r=register').select_one('input[name=email_code]')))
expect_post(reg,'email_code_request',email=reg_mail,purpose='register')
message=next((m for m in smtp.messages if m['to']==reg_mail),None)
check('SMTP TLS receiver got the real verification message',bool(message),smtp.messages)
code=re.search(r'\b[0-9]{6}\b',message['body']).group()
expect_post(reg,'email_code_request',429,email=reg_mail,purpose='register')
expect_post(reg,'register',403,username='verified_http',display_name='Verified HTTP',email=reg_mail,password='TestPassword!2026',agree='1',email_code='000000' if code!='000000' else '111111')
expect_post(reg,'register',username='verified_http',display_name='Verified HTTP',email=reg_mail,password='TestPassword!2026',agree='1',email_code=code)
check('verified registration persisted proof timestamp',db_one('SELECT email_verified_at FROM cy_users WHERE username=?',('verified_http',))['email_verified_at']>0)
changed_mail='changed-http@example.test'
expect_post(user,'email_code_request',email=changed_mail,purpose='email')
message=next((m for m in smtp.messages if m['to']==changed_mail),None)
check('email change message traversed local TLS SMTP',bool(message))
code=re.search(r'\b[0-9]{6}\b',message['body']).group()
expect_post(user,'email_change',403,email=changed_mail,email_code=code,current_password='WrongPassword!2026')
expect_post(user,'email_change',email=changed_mail,email_code=code,current_password='TestPassword!2026')
check('email change saved and current session still works',db_one('SELECT email FROM cy_users WHERE id=?',(uid,))['email']==changed_mail and 'r=profile' in user.get(base+'/index.php?r=profile').url)
check('standalone no-script verification route works',user.get(base+'/index.php?r=verify&purpose=email').status_code==200)
save_group('email',email_registration_required='0',smtp_enabled='0')
check('disabled verification page is blocked',guest.get(base+'/index.php?r=verify').status_code==403)
