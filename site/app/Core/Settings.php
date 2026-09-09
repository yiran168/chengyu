<?php
declare(strict_types=1);
namespace Chengyu\Core;
final class Settings
{
    private Database $db;
    private Crypto $crypto;
    private array $schema;
    private array $values = [];
    public function __construct(Database $db, Crypto $crypto)
    {
        $this->db = $db; $this->crypto = $crypto;
        $this->schema = array_merge(require dirname(__DIR__) . '/settings.php', require dirname(__DIR__) . '/settings016.php', require dirname(__DIR__) . '/settings017.php', require dirname(__DIR__) . '/settings018.php', require dirname(__DIR__) . '/settings019.php', require dirname(__DIR__) . '/settings020.php');
        $this->reload();
    }
    public function reload(): void
    {
        $this->values=[];
        foreach ($this->schema as $key => $field) { $this->values[$key] = $field[3]; }
        foreach ($this->db->all('SELECT name,value FROM cy_settings') as $row) {
            if (isset($this->schema[$row['name']])) { $this->values[$row['name']] = json_decode($row['value'], true); }
        }
    }
    public function schema(): array { return $this->schema; }
    public function get(string $key, $default = null)
    {
        $value = $this->values[$key] ?? $default;
        if (($this->schema[$key][2] ?? '') === 'secret' && is_string($value) && $value !== '') { return $this->crypto->open($value); }
        return $value;
    }
    public function enabled(string $module): bool { return (bool)$this->get($module . '_enabled', false); }
    public function requireModule(string $module): void
    {
        if (!$this->enabled($module)) { throw new Problem('This module is disabled.', 403); }
    }
    public function saveGroup(string $group, array $input): void
    {
        $updates = [];
        foreach ($this->schema as $key => $field) {
            if ($field[0] !== $group) { continue; }
            [$section, $label, $type, $default] = $field;
            if ($type === 'bool') { $value = isset($input[$key]) && (string)$input[$key] === '1'; }
            elseif ($type === 'int') { $value = Input::integer($input[$key] ?? $default, $field[5], $field[6]); }
            elseif ($type === 'select') { $value = Input::choice($input[$key] ?? $default, $field[4]); }
            elseif ($type === 'url') { $value = Input::url($input[$key] ?? ''); }
            elseif ($type === 'color') {
                $value = Input::text($input[$key] ?? '', 7);
                if ($value !== '' && !preg_match('/^#[a-fA-F0-9]{6}$/D', $value)) { throw new Problem('Use a six-digit hexadecimal color.'); }
            } elseif ($type === 'sections') {
                $value = Input::text($input[$key] ?? '', 100);
                $sections = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
                if (array_diff($sections, $field[4])) { throw new Problem('Unknown home section.'); }
                $value = implode(',', $sections);
            } elseif ($type === 'secret') {
                $plain = Input::text($input[$key] ?? '', 8192);
                if (!empty($input['clear_' . $key])) { $value = ''; }
                elseif ($plain === '') { continue; }
                else { $value = $this->crypto->seal($plain); }
            } else { $value = Input::text($input[$key] ?? $default, $type === 'textarea' ? 4000 : 255); }
            $updates[$key] = $value;
        }
        if (!$updates) { throw new Problem('Unknown settings section.'); }
        $effective = array_merge($this->values, $updates);
        if($group==='discovery'){
            if(!preg_match('/^cy_[a-z0-9_-]{1,60}$/D',(string)$effective['meili_index'])){throw new Problem('Use a dedicated index name beginning with cy_.');}
            if($effective['meili_endpoint']!==''){
                HttpClient::endpoint($effective['meili_endpoint']);
                if(parse_url($effective['meili_endpoint'],PHP_URL_QUERY)!==null){throw new Problem('The search endpoint must not contain a query string.');}
            }
            if($effective['search_engine']==='meilisearch' && (!$effective['meili_consent'] || $effective['meili_endpoint']==='' || $effective['meili_key']==='')){throw new Problem('Configure the search endpoint, API key and public-data consent first.');}
        }

        if (($effective['email_registration_required'] ?? false) && !($effective['smtp_enabled'] ?? false)) { throw new Problem('Configure and enable SMTP before requiring email verification.'); }
        if ($group === 'email' && !empty($effective['smtp_enabled'])) {
            if (!filter_var($effective['smtp_from'], FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9.\-]+$/D', $effective['smtp_host'])) { throw new Problem('Invalid SMTP configuration.'); }
        }
        if ($group === 'commerce' && $updates['topup_min'] > $updates['topup_max']) { throw new Problem('Minimum top-up exceeds maximum.'); }
        if (in_array($group, ['epay', 'codepay'], true) && !empty($updates[$group . '_enabled'])) {
            $endpoint = $updates[$group . '_endpoint'];
            if (strpos($endpoint, 'https://') !== 0 || parse_url($endpoint, PHP_URL_QUERY) || parse_url($endpoint, PHP_URL_FRAGMENT)) {
                throw new Problem('Payment endpoint must be HTTPS without a query or fragment.');
            }
            if ($updates[$group . '_merchant'] === '' || (($updates[$group . '_secret'] ?? $this->values[$group . '_secret']) === '')) {
                throw new Problem('Merchant ID and signing key are required.');
            }
        }
        $this->db->transaction(function () use ($updates): void {
            $this->db->one('SELECT name FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            if(isset($updates['store_currency']) && $updates['store_currency']!==$this->moneyCurrencyLocked() && ((int)$this->db->value('SELECT COUNT(*) FROM cy_orders')>0 || (int)$this->db->value("SELECT COUNT(*) FROM cy_ledger WHERE currency IN ('balance','commission','earnings')")>0)){throw new Problem('Store currency cannot change after financial activity. No implicit currency conversion is performed.',409);}
            foreach ($updates as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($this->db->one('SELECT name FROM cy_settings WHERE name=?', [$key])) {
                    $this->db->execute('UPDATE cy_settings SET value=? WHERE name=?', [$encoded, $key]);
                } else { $this->db->insert('cy_settings', ['name' => $key, 'value' => $encoded]); }
            }
        });
        $this->values = array_merge($this->values, $updates);
        if(in_array($group,['discovery','modules'],true)){\Chengyu\Services\RemoteSearch::enqueueAll($this->db);}
    }
    /** Caller owns a transaction. Serializes currency setup against first money activity. */
    public function moneyCurrencyLocked(): string
    {
        $this->db->one('SELECT name FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
        $stored=$this->db->value('SELECT value FROM cy_settings WHERE name=?',['store_currency']);
        return ($stored===null || $stored===false) ? 'CNY' : (string)json_decode($stored,true,8,JSON_THROW_ON_ERROR);
    }
    public function assertMoneyCurrency(): void
    {if($this->moneyCurrencyLocked()!==$this->get('store_currency')){throw new Problem('Store currency changed. Reload before creating financial activity.',409);}}
    public function savePartial(string $group, array $changes): void
    {
        $input = [];
        foreach ($this->schema as $key => $field) {
            if ($field[0] !== $group) { continue; }
            $input[$key] = $field[2] === 'secret' ? '' : ($field[2] === 'bool' ? ($this->get($key) ? '1' : '0') : $this->get($key));
        }
        foreach ($changes as $key => $value) {
            if (!isset($this->schema[$key]) || $this->schema[$key][0] !== $group) { throw new Problem('Unknown settings field.'); }
            $input[$key] = $value;
        }
        $this->saveGroup($group, $input);
    }
    public function seed(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset($this->schema[$key])) {
                $this->db->insert('cy_settings', ['name' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
        }
        $this->values = array_merge($this->values, $values);
    }
}
