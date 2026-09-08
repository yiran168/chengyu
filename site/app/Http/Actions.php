<?php
declare(strict_types=1);
namespace Chengyu\Http;
use Chengyu\App;
use Chengyu\Core\{Input, Problem, Security};
use Chengyu\Services\Wallet;
final class Actions
{
    private App $app;
    public function __construct(App $app) { $this->app = $app; }
    public function run(array $input): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new Problem('POST is required.', 405); }
        Security::verify($input['csrf'] ?? null);
        foreach ($input as $name=>$value) {
            if ($name==='option_ids' && ($input['action']??'')==='poll_vote' && is_array($value) && count($value)<=10) {
                foreach($value as $choice){if(!is_scalar($choice)){throw new Problem('Invalid input.');}}
            } elseif (!is_scalar($value) && $value!==null) { throw new Problem('Invalid input.'); }
        }
        $a = $this->app; $action = Input::required($input['action'] ?? '', 64);
        $back = $a->localTarget(Input::text($input['_back'] ?? $a->url(), 2000));
        \Chengyu\Core\Locale::context(in_array(parse_url($back,PHP_URL_PATH),[$a->basePath.'/admin/',$a->basePath.'/admin/index.php'],true)?'admin':'site');
        $response = ['ok' => true, 'message' => tr('Saved successfully.'), 'redirect' => $back];
        if ($a->settings->get('maintenance') && !$a->auth->can('manage') && !in_array($action,['login','forgot','reset'],true)) { throw new Problem('The site is under maintenance.',503); }
        $platformResponse=(new PlatformActions($a))->run($action,$input,$response);if($platformResponse!==null){return $platformResponse;}
        $mediaResponse=(new MediaActions($a))->run($action,$input,$response);if($mediaResponse!==null){return $mediaResponse;}
        $commerceResponse=(new CommerceActions($a))->run($action,$input,$response);if($commerceResponse!==null){return $commerceResponse;}
        if ($action === 'email_code_request') {
            $purpose=Input::choice($input['purpose']??'register',['register','email']);
            $uid=$purpose==='email'?(int)$a->auth->requireUser()['id']:0;
            if (!empty($input['website'])) { throw new Problem('Invalid input.'); }
            $a->verification->request(Input::required($input['email']??'',191),$purpose,$uid,[$a->mailer,'send']);
            $response['message']=tr('Verification email sent. Check your inbox and spam folder.');
            return $response;
        }
        if ($action === 'content_unlock') {
            $a->content->unlock(Input::integer($input['content_id']??0,1),Input::passwordValue($input['content_password']??''),$a->auth->user());return $response;
        }
        if ($action === 'login') {
            $a->auth->login(Input::required($input['identifier'] ?? '', 191), Input::passwordValue($input['password'] ?? ''), Input::text($input['factor_code'] ?? '',32));
            $response['redirect'] = !empty($input['admin_login']) && $a->auth->can('admin') ? $a->adminUrl() : $a->url();
            $response['message'] = tr('Welcome back.'); return $response;
        }
        if ($action === 'oauth_begin') {
            $response['redirect']=$a->oauth->begin(Input::required($input['provider']??'',20),Input::choice($input['purpose']??'login',['login','link']),Input::passwordValue($input['current_password']??''),Input::text($input['factor_code']??'',32));
            return $response;
        }
        if ($action === 'oauth_login' || $action === 'oauth_register') {
            $nonce=Input::required($input['nonce']??'',64);
            if($action==='oauth_login'){$a->oauth->login($nonce,Input::text($input['factor_code']??'',32));}
            else{$a->oauth->register($nonce,$input);}
            $response['redirect']=$a->url();$response['message']=tr('Welcome back.');return $response;
        }
        if ($action === 'register') {
            if (empty($input['agree'])) { throw new Problem('Please accept the site terms.'); }
            $a->auth->register($input); $a->auth->login((string)$input['username'], (string)$input['password']);
            $response['redirect'] = $a->url(); $response['message'] = tr('Your account is ready.'); return $response;
        }
        if ($action === 'forgot') {
            $a->auth->recovery(Input::required($input['email'] ?? '', 191), $a->mailer, $a->config['url']);
            $response['message'] = tr('If the account exists, a recovery email will be sent.'); return $response;
        }
        if ($action === 'reset') {
            $a->activity->rate('reset-submit', Security::ip(), 10, 3600);
            $a->auth->reset(Input::required($input['token'] ?? '', 64), Input::passwordValue($input['password'] ?? ''));
            $response['redirect'] = $a->url('login'); $response['message'] = tr('Password updated. Please sign in.'); return $response;
        }
        $user = $a->auth->requireUser(); $uid = (int)$user['id'];
        if ($a->settings->get('maintenance') && $user['role'] !== 'admin') { throw new Problem('The site is under maintenance.', 503); }
        if (strpos($action, 'admin_') === 0) { return $this->admin($action, $input, $response); }
        switch ($action) {
            case 'oauth_unlink':
                $a->oauth->unlink(Input::integer($input['id']??0,1),Input::passwordValue($input['current_password']??''),Input::text($input['factor_code']??'',32));$response['redirect']=$a->url('security');break;
            case 'payment_reconcile':
                $response['payment']=$a->payment->reconcile($uid,Input::integer($input['order_id']??0,1));
                $response['message']=tr($response['payment']['status']==='paid'?'Payment confirmed. The order is credited exactly once.':'No successful payment confirmed yet.');break;
            case 'factor_begin':
                $a->secondFactor->begin($uid,(int)$_SESSION['session_version'],Input::passwordValue($input['current_password']??''));
                $response['redirect']=$a->url('security');break;
            case 'factor_activate':
            case 'factor_rotate':
            case 'factor_disable':
            case 'session_revoke':
                $password=Input::passwordValue($input['current_password']??'');$code=Input::text($input['factor_code']??'',32);
                $result=$action==='factor_activate'
                    ?$a->secondFactor->activate($uid,(int)$_SESSION['session_version'],$password,$code)
                    :$a->secondFactor->manage($uid,(int)$_SESSION['session_version'],$password,$code,['factor_rotate'=>'rotate','factor_disable'=>'disable','session_revoke'=>'revoke'][$action]);
                $a->auth->syncSession($result['version']);
                if($result['codes']) {$_SESSION['factor_codes']=['user_id'=>$uid,'version'=>$result['version'],'expires_at'=>time()+600,'codes'=>$result['codes']];}
                $response['redirect']=$a->url('security');$response['message']=tr('Security updated. Other sessions were signed out.');break;
            case 'logout': $a->auth->logout(); $response['redirect'] = $a->url(); $response['message'] = tr('Signed out.'); break;
            case 'poll_vote': $a->threads->voteMany($user,Input::integer($input['content_id']??0,1),isset($input['option_ids'])?(is_array($input['option_ids'])?$input['option_ids']:[$input['option_ids']]):[$input['option_id']??0],$a->content); $response['message']=tr('Your vote was recorded.'); break;
            case 'bounty_fund': $a->bounties->fund($user,Input::integer($input['content_id']??0,1),Input::integer($input['amount']??0,1),Input::integer($input['days']??7,1,30),$a->content);break;
            case 'bounty_release': $a->bounties->release($user,Input::integer($input['content_id']??0,1));break;
            case 'review_save': $a->reviews->save($user,Input::integer($input['content_id']??0,1),Input::integer($input['rating']??0,1,5),Input::required($input['body']??'',2000),$a->content);$response['message']=tr('Your review was submitted.');break;
            case 'answer_accept': $a->threads->accept($user,Input::integer($input['content_id']??0,1),Input::integer($input['comment_id']??0,1),$a->content); break;
            case 'email_change':
                $a->activity->rate('email-change',(string)$uid,10,3600);
                $a->auth->changeEmail(Input::required($input['email']??'',191),Input::text($input['email_code']??'',6),Input::passwordValue($input['current_password']??''));
                $response['message']=tr('Email verified. Other sessions and old recovery links were invalidated.');break;
            case 'history_record':
                $a->activity->rate('history-write',(string)$uid,120,3600);
                $a->discovery->record($user,Input::integer($input['content_id']??0,1),Input::integer($input['progress']??0,0,100));$response['redirect']='';break;
            case 'history_forget': $a->discovery->forget($uid,Input::integer($input['content_id']??0));break;
            case 'history_preference': $a->discovery->preference($uid,!empty($input['enabled']));break;
            case 'creator_settle':
                $a->activity->rate('creator-settle',(string)$uid,12,3600);
                $response['settled']=$a->creator->settle($uid); $response['message']=tr('Eligible earnings settled. Pending disputes and unreceived deliveries are skipped.');break;
            case 'creator_convert':
                $a->activity->rate('creator-convert',(string)$uid,20,3600);$a->auth->verifyPassword(Input::passwordValue($input['current_password']??''));
                $a->creator->convert($uid,Input::cents($input['amount']??''),(string)($input['intent']??''));break;
            case 'profile': $a->auth->profile($input); break;
            case 'password':
                $a->activity->rate('password-change', (string)$uid, 10, 3600);
                $a->auth->changePassword(Input::passwordValue($input['current_password'] ?? ''), Input::passwordValue($input['password'] ?? '')); break;
            case 'content_save':
                $id = $a->content->save($user, $input, $a->auth->can('content'));
                $response['redirect'] = $a->url('article', ['id' => $id]);
                $response['message'] = tr($a->auth->can('content') ? 'Content saved.' : 'Submission saved for review.'); break;
            case 'comment':
                $a->content->comment($user, Input::integer($input['content_id'] ?? 0, 1), (string)($input['body'] ?? ''), Input::integer($input['parent_id'] ?? 0));
                $response['message'] = tr($a->settings->get('comments_review') ? 'Comment submitted for review.' : 'Comment published.'); break;
            case 'reaction': $a->content->reaction($user, Input::integer($input['content_id'] ?? 0, 1), (string)($input['kind'] ?? '')); break;
            case 'follow': $a->content->follow($uid, Input::integer($input['target_id'] ?? 0, 1)); break;
            case 'message': $a->content->message($uid, (string)($input['recipient'] ?? ''), (string)($input['body'] ?? '')); $response['message'] = tr('Message sent.'); break;
            case 'read_messages': $a->db->execute('UPDATE cy_messages SET read_at=? WHERE recipient_id=? AND read_at=0', [time(), $uid]); break;
            case 'read_notifications': $a->db->execute('UPDATE cy_notifications SET read_at=? WHERE user_id=? AND read_at=0', [time(), $uid]); break;
            case 'report': $a->content->report($user, Input::integer($input['content_id'] ?? 0, 1), (string)($input['reason'] ?? '')); $response['message'] = tr('Report submitted.'); break;
            case 'checkin': $reward = $a->wallet->checkin($uid); $response['message'] = tr('Check-in complete') . ' +' . $reward . ' ' . tr('Points'); break;
            case 'redeem': $a->commerce->redeem($uid, (string)($input['code'] ?? '')); $response['message'] = tr('Code redeemed.'); break;
            case 'buy':
                $order = $a->commerce->buy($uid, (string)($input['kind'] ?? ''), Input::integer($input['item_id'] ?? 0, 1), (string)($input['intent'] ?? ''), Input::text($input['coupon'] ?? '', 64), Input::integer($input['address_id'] ?? 0), Input::integer($input['variant_id'] ?? 0),isset($input['price_ceiling']) && $input['price_ceiling']!=='' ? Input::integer($input['price_ceiling'],0,1000000000) : null,isset($input['price_currency']) ? Input::choice($input['price_currency'],['balance','points','tokens']) : null);
                $response['redirect'] = $a->url('orders', ['id' => (int)$order['id']]); $response['message'] = tr('Purchase complete.'); break;
            case 'topup':
                $order = $a->payment->create($uid, Input::cents($input['amount'] ?? ''), (string)($input['gateway'] ?? ''), (string)($input['method'] ?? ''), (string)($input['intent'] ?? ''));
                $response['redirect'] = $order['status'] === 'paid' ? $a->url('orders') : $a->payment->checkout($order); $response['message'] = tr('Opening secure checkout.'); break;
            case 'exchange':
                $a->commerce->exchange($uid, (string)($input['currency'] ?? ''), Input::cents($input['amount'] ?? ''), (string)($input['intent'] ?? '')); $response['message'] = tr('Exchange complete.'); break;
            case 'transfer':
                $a->activity->rate('transfer', (string)$uid, 20, 3600); $a->auth->verifyPassword(Input::passwordValue($input['current_password'] ?? ''));
                $currency = Input::choice($input['currency'] ?? '', ['balance', 'points', 'tokens']);
                $amount = $currency === 'balance' ? Input::cents($input['amount'] ?? '') : Input::integer($input['amount'] ?? 0, 1, 100000000);
                $a->wallet->transfer($uid, Input::required($input['recipient'] ?? '', 32), $currency, $amount, (string)($input['intent'] ?? '')); $response['message'] = tr('Transfer complete.'); break;
            case 'withdraw':
                $a->activity->rate('withdraw', (string)$uid, 10, 3600); $a->auth->verifyPassword(Input::passwordValue($input['current_password'] ?? ''));
                $a->commerce->withdraw($uid, Input::cents($input['amount'] ?? ''), (string)($input['account'] ?? ''), (string)($input['intent'] ?? ''), (string)($input['currency'] ?? 'commission')); $response['message'] = tr('Withdrawal reserved for manual review.'); break;
            case 'refund': $a->commerce->requestRefund($uid, Input::integer($input['order_id'] ?? 0, 1), (string)($input['reason'] ?? '')); $response['message'] = tr('Refund requested.'); break;
            case 'upload':
                $a->activity->rate('upload', (string)$uid, 30, 3600);
                $response['media_id'] = $a->media->upload($user, $_FILES['file'] ?? [], !empty($input['private']));
                $response['message'] = tr('Uploaded file') . ' #' . $response['media_id']; break;
            case 'address_save':
                $id = Input::integer($input['id'] ?? 0);
                $data = ['recipient' => Input::required($input['recipient'] ?? '', 100), 'phone' => Input::required($input['phone'] ?? '', 40), 'address' => Input::required($input['address'] ?? '', 1000)];
                if ($id) {
                    if (!$a->db->one('SELECT id FROM cy_addresses WHERE id=? AND user_id=?', [$id, $uid])) { throw new Problem('Access denied.', 403); }
                    $a->db->update('cy_addresses', $id, $data);
                } else {
                    if ((int)$a->db->value('SELECT COUNT(*) FROM cy_addresses WHERE user_id=?', [$uid]) >= 20) { throw new Problem('Address limit reached.'); }
                    $a->db->insert('cy_addresses', array_merge($data, ['user_id' => $uid, 'created_at' => time()]));
                } break;
            case 'address_delete': $a->db->execute('DELETE FROM cy_addresses WHERE id=? AND user_id=?', [Input::integer($input['id'] ?? 0, 1), $uid]); break;
            case 'cart_add':
                $a->settings->requireModule('cart'); $a->settings->requireModule('shop');
                $item=$a->content->get(Input::integer($input['item_id']??0,1),$user);
                \Chengyu\Services\Cart::put($item,Input::integer($input['variant_id']??0),Input::integer($input['quantity']??1,1,10),$a->db);
                $response['message']=tr('Added to cart.');break;
            case 'cart_remove':
                $a->settings->requireModule('cart');\Chengyu\Services\Cart::remove(Input::integer($input['item_id']??0,1),Input::integer($input['variant_id']??0));break;
            case 'cart_checkout':
                $a->settings->requireModule('cart');$a->settings->requireModule('shop');
                $a->commerce->checkout($uid,array_values(\Chengyu\Services\Cart::lines()),Input::required($input['intent']??'',32),Input::integer($input['address_id']??0),'',isset($input['ceiling_balance']) ? ['balance'=>$input['ceiling_balance'],'points'=>$input['ceiling_points']??-1,'tokens'=>$input['ceiling_tokens']??-1] : null);
                $_SESSION['cart']=[];$response['redirect']=$a->url('orders');$response['message']=tr('All cart purchases completed.');break;
            case 'order_receive':$a->commerce->receive($uid,Input::integer($input['order_id']??0,1));break;
            case 'task_claim':$a->tasks->claim($uid,Input::integer($input['task_id']??0,1));$response['message']=tr('Task reward claimed.');break;
            default: throw new Problem('Unknown action.', 404);
        }
        return $response;
    }
    private function admin(string $action, array $input, array $response): array
    {
        $a = $this->app;
        $permission = in_array($action,['admin_moderate','admin_review'],true) ? 'moderate' : (in_array($action,['admin_content','admin_revision'],true) ? 'content' : ($action==='admin_comment_pin'?'moderate':'manage'));
        $actor = $a->auth->requirePermission($permission); $uid = (int)$actor['id'];
        switch ($action) {
            case 'admin_badge':$a->badges->save($uid,$input);break;
            case 'admin_badge_grant':
                $raw=Input::text($input['expires_at']??'',40);$expires=$raw===''?0:strtotime($raw);if($expires===false){throw new Problem('Invalid date.');}
                $a->badges->grant($uid,Input::integer($input['user_id']??0,1),Input::integer($input['badge_id']??0,1),(int)$expires);break;
            case 'admin_badge_revoke':$a->badges->revoke($uid,Input::integer($input['user_id']??0,1),Input::integer($input['badge_id']??0,1));break;
            case 'admin_snapshot_capture':$a->configuration->capture($uid,Input::required($input['label']??'',100));break;
            case 'admin_snapshot_import':
                $a->auth->prove(Input::passwordValue($input['current_password']??''),Input::text($input['factor_code']??'',32));
                $file=$_FILES['archive']??[];
                if(($file['error']??1)!==UPLOAD_ERR_OK || ($file['size']??0)>400000 || !is_uploaded_file((string)($file['tmp_name']??''))){throw new Problem('Select an encrypted settings archive smaller than 400 KB.');}
                $id=$a->configuration->import($uid,(string)file_get_contents($file['tmp_name']),Input::required($input['label']??'',100));$response['redirect']=$a->adminUrl('configuration',['preview'=>$id]);break;
            case 'admin_snapshot_restore':
                $a->auth->prove(Input::passwordValue($input['current_password']??''),Input::text($input['factor_code']??'',32));
                $a->configuration->restore($uid,Input::integer($input['id']??0,1),Input::required($input['revision']??'',64));$response['redirect']=$a->adminUrl('configuration');break;
            case 'admin_snapshot_export':
                $a->auth->prove(Input::passwordValue($input['current_password']??''),Input::text($input['factor_code']??'',32));
                $id=Input::integer($input['id']??0,1);$archive=$a->configuration->export($uid,$id);
                header('Content-Type: application/octet-stream');header('Cache-Control: private, no-store');header('Content-Disposition: attachment; filename="chengyu-settings-'. $id .'.cysettings"');echo $archive;exit;
            case 'admin_pricing':
                $a->pricing->save($uid,Input::integer($input['content_id']??0,1),$input);break;
            case 'admin_diagnostics':
                $a->activity->rate('diagnostics',(string)$uid,12,3600);
                $report=\Chengyu\Core\Diagnostics::run($a);$a->activity->audit($uid,'system.diagnostics','capability-report');
                if(($input['download']??'')==='1') {
                    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
                    header('Content-Disposition: attachment; filename="chengyu-capability-report.json"');
                    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
                }
                $_SESSION['capability_report']=$report;$response['redirect']=$a->adminUrl('system');break;
            case 'admin_motion':
                $changes=[];$schema=$a->settings->schema();
                foreach (\Chengyu\Core\Motion::KEYS as $key) { if (isset($input[$key])) { $changes[$key]=$input[$key]; } elseif($schema[$key][2]==='bool') { $changes[$key]='0'; } }
                $a->settings->savePartial('appearance',$changes);$a->activity->audit($uid,'settings.motion','appearance');break;
            case 'admin_variant':$a->inventory->save($uid,$input);break;
            case 'admin_catalog':$a->catalog->save($uid,Input::required($input['entity']??'',40),$input);break;
            case 'admin_task':$a->tasks->save($uid,$input);break;
            case 'admin_layout':$a->layout->save($uid,Input::required($input['slot']??'',64),Input::required($input['document']??'',100000),Input::integer($input['revision']??0));break;
            case 'admin_revision':$a->content->restore($actor,Input::integer($input['content_id']??0,1),Input::integer($input['revision_id']??0,1));break;
            case 'admin_comment_pin':
                $a->admin->pinComment($uid,Input::integer($input['id']??0,1),!empty($input['pinned']));break;
            case 'admin_invitation':
                $expires=strtotime(Input::required($input['expires_at']??'',40));if(!$expires){throw new Problem('Invalid date.');}
                $_SESSION['issued_invitations']=$a->invitations->issue($uid,Input::integer($input['count']??1,1,100),Input::integer($input['max_uses']??1,1,1000),$expires,Input::text($input['label']??'',100));
                $response['message']=tr('Codes generated. Copy them now; plaintext will not be shown again.');break;
            case 'admin_invitation_disable':$a->invitations->disable($uid,Input::integer($input['id']??0,1));break;
            case 'admin_settings':
                $group = Input::required($input['group'] ?? '', 40); $a->settings->saveGroup($group, $input);
                $a->activity->audit($uid, 'settings.updated', $group); break;
            case 'admin_entity':
                $entity = Input::required($input['entity'] ?? '', 40); $a->admin->entity($uid, $entity, $input); break;
            case 'admin_content':
                $id = $a->content->save($actor, $input, true); $response['redirect'] = $a->adminUrl('edit_content', ['id' => $id]); break;
            case 'admin_user': $a->admin->user($uid, Input::integer($input['id'] ?? 0, 1), $input); break;
            case 'admin_wallet':
                $a->activity->rate('admin-finance', (string)$uid, 30, 3600); $a->auth->verifyPassword(Input::passwordValue($input['current_password'] ?? ''));
                $target = Input::integer($input['user_id'] ?? 0, 1); $currency = Input::choice($input['currency'] ?? '', Wallet::CURRENCIES);
                $raw = Input::required($input['delta'] ?? '', 20); if (!preg_match('/^[+-]?[0-9]+(?:\.[0-9]{1,2})?$/D', $raw)) { throw new Problem('Invalid amount.'); } $negative = substr($raw, 0, 1) === '-'; $raw = ltrim($raw, '+-');
                $amount = \Chengyu\Services\Wallet::isMoney($currency) ? Input::cents($raw) : Input::integer($raw, 1, 100000000);
                $reason = Input::required($input['reason'] ?? '', 180); $intent = Wallet::intent((string)($input['intent'] ?? ''));
                $a->db->transaction(function () use ($a, $uid, $target, $currency, $negative, $amount, $reason, $intent): void {
                    $a->wallet->adjust($target, $currency, $negative ? -$amount : $amount, 'admin:' . $uid . ':' . $intent, 'Admin adjustment: ' . $reason);
                    $a->activity->audit($uid, 'wallet.adjusted', $target . ':' . $currency . ':' . ($negative ? '-' : '+') . $amount);
                }); break;
            case 'admin_review': $a->reviews->moderate($uid,Input::integer($input['id']??0,1),Input::choice($input['status']??'', ['approved','rejected']));break;
            case 'admin_circle_member':
                $until=Input::text($input['granted_until']??'',40);$until=$until===''?0:strtotime($until);
                if ($until===false) {throw new Problem('Invalid date.');}
                $a->categories->manageMember($uid,Input::integer($input['category_id']??0,1),Input::integer($input['user_id']??0,1),!empty($input['blocked']),$until);break;
            case 'admin_bounty_release': $a->bounties->release($a->auth->requireUser(),Input::integer($input['content_id']??0,1));break;
            case 'admin_moderate': $a->admin->moderation($uid, (string)($input['entity'] ?? ''), Input::integer($input['id'] ?? 0, 1), (string)($input['status'] ?? '')); break;
            case 'admin_vouchers':
                $kind = Input::choice($input['kind'] ?? '', ['balance', 'points', 'tokens', 'membership']);
                $amount = $kind === 'balance' ? Input::cents($input['amount'] ?? '') : Input::integer($input['amount'] ?? 0, 1, 100000000);
                $expires = strtotime(Input::required($input['expires_at'] ?? '', 40)); if (!$expires) { throw new Problem('Invalid date.'); }
                $_SESSION['issued_codes'] = $a->commerce->vouchers($uid, $kind, $amount, Input::integer($input['count'] ?? 1, 1, 100), $expires, (string)($input['label'] ?? ''));
                $response['message'] = tr('Codes generated. Copy them now; plaintext will not be shown again.'); break;
            case 'admin_stock': $a->commerce->importStock($uid, Input::integer($input['content_id'] ?? 0, 1), Input::required($input['codes'] ?? '', 500000),Input::integer($input['variant_id']??0)); break;
            case 'admin_refund':
                $a->auth->verifyPassword(Input::passwordValue($input['current_password'] ?? ''));
                $a->commerce->refund($uid, Input::integer($input['id'] ?? 0, 1), ($input['decision'] ?? '') === 'approve'); break;
            case 'admin_ship': $a->commerce->ship($uid, Input::integer($input['id'] ?? 0, 1), (string)($input['tracking'] ?? '')); break;
            case 'admin_withdrawal':
                $a->auth->verifyPassword(Input::passwordValue($input['current_password'] ?? ''));
                $a->commerce->reviewWithdrawal($uid, Input::integer($input['id'] ?? 0, 1), ($input['decision'] ?? '') === 'paid', (string)($input['note'] ?? '')); break;
            case 'admin_cleanup': $a->admin->cleanup(); $a->activity->audit($uid, 'system.cleanup', 'maintenance'); break;
            case 'admin_mail_test':
                $a->activity->rate('smtp-test', (string)$uid, 3, 3600); $a->mailer->send($actor['email'], tr('Mail delivery test'), tr('Your SMTP connection is working.')); break;
            case 'admin_export':
                $a->auth->verifyPassword(Input::passwordValue($input['current_password'] ?? ''));
                $entity = Input::choice($input['entity'] ?? '', ['orders', 'ledger', 'users']); $a->activity->audit($uid, 'data.exported', $entity);
                $a->admin->export($entity); exit;
            default: throw new Problem('Unknown action.', 404);
        }
        return $response;
    }
}
