<?php
declare(strict_types=1);
namespace Chengyu\Core;
use Chengyu\App;

final class RollbackProbe extends \RuntimeException {}
/** Administrator-only capability evidence. No secrets, paths, emails or keys are exported. */
final class Diagnostics
{
    public static function run(App $app): array
    {
        $checks=[];
        $add=static function(string $name,string $state,string $detail)use(&$checks):void {$checks[]=['check'=>$name,'state'=>$state,'detail'=>$detail];};
        $add('PHP runtime',version_compare(PHP_VERSION,'7.4.0','>=') && PHP_INT_SIZE===8?'pass':'fail',PHP_VERSION.' / '.(PHP_INT_SIZE*8).'-bit');
        $add('Native PDO driver',$app->db->native()?'pass':'warning',$app->db->native()?$app->db->driver():'Test adapter; not native PDO evidence');
        foreach (['openssl','fileinfo','session','json','hash'] as $extension) {$ok=extension_loaded($extension);$add('Extension '.$extension,$ok?'pass':'fail',$ok?'available':'missing');}
        try {
            $plain=bin2hex(random_bytes(24));$ok=hash_equals($plain,$app->crypto->open($app->crypto->seal($plain)));
            $add('Authenticated encryption',$ok?'pass':'fail','AES-256-GCM round trip');
            $hash=password_hash($plain,PASSWORD_DEFAULT);$ok=password_verify($plain,$hash);
            $add('Password hashing',$ok?'pass':'fail','Password hash and verification');
        } catch (\Throwable $e) {$add('Cryptographic primitives','fail','Local cryptographic operation failed');}
        try {
            $unicode=json_decode('"\u5c9b\u5c7f\u2713"',true,4,JSON_THROW_ON_ERROR);
            $result=$app->db->one('SELECT ? AS sample,? AS amount',[$unicode,9000000000001]);
            $ok=$result && $result['sample']===$unicode && (string)$result['amount']==='9000000000001';
            $add('Prepared statement and integer range',$ok?'pass':'fail','Bound UTF-8 and a 64-bit integer');
        } catch (\Throwable $e) {$add('Prepared statement and integer range','fail','Database binding failed');}
        $bucket='diagnostic:'.bin2hex(random_bytes(16));$rolled=false;
        try {
            try {
                $app->db->transaction(static function(Database $db)use($bucket):void {
                    $db->insert('cy_rate_limits',['bucket'=>$bucket,'hits'=>1,'expires_at'=>time()+60]);
                    throw new RollbackProbe('Expected rollback probe');
                });
            } catch (RollbackProbe $e) {$rolled=true;}
            $left=(int)$app->db->value('SELECT COUNT(*) FROM cy_rate_limits WHERE bucket=?',[$bucket]);
            $add('Real database rollback',$rolled && $left===0?'pass':'fail','Temporary probe row must not survive rollback');
        } catch (\Throwable $e) {$add('Real database rollback','fail','Rollback probe did not complete');}
        finally {try {$app->db->execute('DELETE FROM cy_rate_limits WHERE bucket=?',[$bucket]);}catch(\Throwable $ignored){}}
        if ($app->db->driver()==='mysql') {
            try {
                $tables=$app->db->all("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ? ESCAPE '!'",['cy!_%']);
                $ok=count($tables)>0;foreach($tables as $table){$ok=$ok && strtoupper((string)$table['ENGINE'])==='INNODB';}
                $add('Transactional application tables',$ok?'pass':'fail','All application tables must use InnoDB');
            } catch (\Throwable $e) {$add('Transactional application tables','warning','Host denied table-engine inspection; verify in the panel');}
        }
        $add('Private storage writable',is_writable($app->config['storage'])?'pass':'fail','Filesystem write capability; public access protection must be checked separately');
        $add('HTTPS configured',strpos($app->config['url'],'https://')===0?'pass':'warning','Configured URL only; not a certificate issuance or TLS network test');
        $add('Web-server directory protection','manual','Check /app/, /storage/ and uploaded files using your actual hosting URL');
        $add('External payment, email and certificate services','manual','Requires your merchant, mailbox and host acceptance tests');
        return ['application'=>'Chengyu '.App::VERSION,'generated_at'=>gmdate(DATE_ATOM),'php'=>PHP_VERSION,'database'=>$app->db->driver(),'native_pdo'=>$app->db->native(),'checks'=>$checks];
    }
}
