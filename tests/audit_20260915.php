<?php
declare(strict_types=1);

use Chengyu\Core\Input;

test('secret settings preserve whitespace after encrypted database reload', static function () use ($a): void {
    $before = $a->settings->get('smtp_password');
    $secret = "  Isolated-Smtp-123 \t";
    try {
        $a->settings->savePartial('email', ['smtp_password' => $secret]);
        $stored = (string)$a->db->value('SELECT value FROM cy_settings WHERE name=?', ['smtp_password']);
        truth(strpos($stored, 'Isolated-Smtp-123') === false);
        $a->settings->reload();
        same($secret, $a->settings->get('smtp_password'));
    } finally { settings('email', ['smtp_password' => $before, 'clear_smtp_password' => $before === '' ? '1' : '0']); }
});

test('blank secret retains credentials and explicit clear removes them', static function () use ($a): void {
    $before = $a->settings->get('smtp_password');
    try {
        $a->settings->savePartial('email', ['smtp_password' => 'Isolated-Smtp-123']);
        $a->settings->savePartial('email', ['smtp_password' => '']);
        $a->settings->reload();
        same('Isolated-Smtp-123', $a->settings->get('smtp_password'));
        settings('email', ['clear_smtp_password' => '1']);
        $a->settings->reload();
        same('', $a->settings->get('smtp_password'));
    } finally { settings('email', ['smtp_password' => $before, 'clear_smtp_password' => $before === '' ? '1' : '0']); }
});

test('secret validation rejects malformed values without changing saved credentials', static function () use ($a): void {
    $before = $a->settings->get('smtp_password');
    foreach ([['array'], "bad\0value", "\xff", str_repeat('x', 8193)] as $invalid) {
        reject(static function () use ($a, $invalid): void { $a->settings->savePartial('email', ['smtp_password' => $invalid]); });
        $a->settings->reload();
        same($before, $a->settings->get('smtp_password'));
    }
});

test('credential text preserves boundary bytes and enforces the full untrimmed length', static function (): void {
    same('  Db-123  ', Input::text('  Db-123  ', 500, false));
    reject(static function (): void { Input::text(' 123 ', 4, false); });
    same('Article title', Input::text('  Article title  '));
});
