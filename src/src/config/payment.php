<?php
/**
 * UniPortal – Payment configuration
 * Non-secret settings come from SystemConfig (Admin editable).
 * Provider credentials and webhook secrets come only from .env / process env.
 */
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../services/SystemConfig.php';

function payment_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $cfg = [
        'provider'        => env_str('PAYMENT_PROVIDER', SystemConfig::get('payment_provider', 'sepay')),
        'bank_bin'        => env_str('PAYMENT_BANK_BIN', SystemConfig::get('payment_bank_bin', '970432')),
        'bank_code'       => env_str('PAYMENT_BANK_CODE', SystemConfig::get('payment_bank_code', 'VPBank')),
        'bank_name'       => env_str('PAYMENT_BANK_NAME', SystemConfig::get('payment_bank_name', 'Ngân hàng TMCP Việt Nam Thịnh Vượng')),
        'account_number'  => env_str('PAYMENT_ACCOUNT_NUMBER', SystemConfig::get('payment_account_number', '123456789')),
        'account_holder'  => env_str('PAYMENT_ACCOUNT_HOLDER', SystemConfig::get('payment_account_holder', 'VNUIS ACADEMIC')),
        'ttl_minutes'     => max(5, SystemConfig::getInt('payment_request_ttl_minutes', 1440)),
        'overpay_policy'  => SystemConfig::get('payment_overpayment_policy', 'review'),
        'partial_allowed' => SystemConfig::get('payment_partial_allowed', '1') === '1',
        'min_transfer'    => max(1, SystemConfig::getInt('payment_min_transfer_vnd', 1000)),
        'webhook_enabled' => SystemConfig::get('payment_webhook_enabled', '0') === '1',
        'test_mode'       => env_bool('PAYMENT_TEST_MODE', false),
        'api_key'         => env_str('SEPAY_API_KEY'),
        'webhook_secret'  => env_str('SEPAY_WEBHOOK_SECRET'),
        'auth_mode'       => env_str('SEPAY_WEBHOOK_AUTH_MODE', 'apikey'),
        'test_webhook_key'=> env_str('PAYMENT_TEST_WEBHOOK_KEY'),
        'gateway'         => env_str('PAYMENT_GATEWAY', 'SePay'),
    ];

    $cfg['live_ready'] = $cfg['webhook_enabled']
        && ($cfg['api_key'] !== '' || $cfg['webhook_secret'] !== '');
    $cfg['test_ready'] = $cfg['test_mode'] && $cfg['test_webhook_key'] !== '';
    $cfg['webhook_ready'] = $cfg['live_ready'] || $cfg['test_ready'];

    $issues = [];
    if ($cfg['account_number'] === '' || $cfg['bank_bin'] === '') {
        $issues[] = 'Chưa cấu hình số tài khoản/BIN ngân hàng nhận tiền.';
    }
    if (!$cfg['webhook_ready']) {
        $issues[] = 'Chưa cấu hình khóa webhook: đặt SEPAY_API_KEY hoặc SEPAY_WEBHOOK_SECRET trong .env và bật payment_webhook_enabled.';
    }
    if (!$cfg['test_mode'] && $cfg['webhook_enabled'] && $cfg['api_key'] === '') {
        $issues[] = 'Live mode cần SEPAY_API_KEY (Authorization: Apikey …).';
    }
    $cfg['issues'] = $issues;

    return $cfg;
}

/** Human-readable configuration checklist for the Admin payment page. */
function payment_config_issues(): array {
    return payment_config()['issues'];
}
