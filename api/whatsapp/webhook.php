<?php
/**
 * Webhook endpoint that Meta calls for incoming WhatsApp messages.
 *
 * Install: this file must be reachable at
 *     <osTicket base>/api/whatsapp/webhook.php
 *
 * Since osTicket ships with an 'api/' directory, you have three options:
 *
 *  1. (recommended) Create a folder 'api/whatsapp/' in your osTicket
 *     install root and place THIS file there. Nothing else lives in /api
 *     that would conflict.
 *
 *  2. Place this anywhere else and configure the Meta webhook URL to
 *     match.
 *
 *  3. If you deploy the plugin as a PHAR, drop this file manually at
 *     <osTicket>/api/whatsapp/webhook.php — PHAR plugins can't register
 *     new top-level HTTP routes.
 *
 * Meta sends two kinds of requests:
 *
 *   GET  ?hub.mode=subscribe&hub.challenge=...&hub.verify_token=...
 *        -> we echo hub.challenge if verify_token matches config
 *
 *   POST JSON payload (subscription events)
 *        -> we parse, hand to WhatsAppHandler, always respond 200 OK
 *           within ~5 seconds or Meta retries
 */

// Boot osTicket. Adjust the relative path if you place this file elsewhere.
require_once dirname(__FILE__) . '/../../main.inc.php';

// And pull in the plugin classes manually — the plugin might not be
// bootstrapped on a pure webhook request.
$pluginDir = INCLUDE_DIR . 'plugins/whatsapp/';
if (!file_exists($pluginDir . 'whatsapp.php')) {
    // Maybe packaged as phar
    $pharPath = INCLUDE_DIR . 'plugins/whatsapp.phar';
    if (file_exists($pharPath)) {
        require_once 'phar://' . $pharPath . '/whatsapp.php';
    }
} else {
    require_once $pluginDir . 'whatsapp.php';
}

// Fetch the plugin instance config.
$config = WhatsAppPlugin::activeConfig();
if (!$config) {
    http_response_code(503);
    echo 'WhatsApp plugin not enabled';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ------------------------------------------------------------------
// GET: verification handshake
// ------------------------------------------------------------------
if ($method === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    // Some gateways pass the dots instead of underscores
    if (!$mode)      { $mode      = $_GET['hub.mode']         ?? ''; }
    if (!$token)     { $token     = $_GET['hub.verify_token'] ?? ''; }
    if (!$challenge) { $challenge = $_GET['hub.challenge']    ?? ''; }

    $expected = trim((string) $config->get('verify_token'));
    if ($mode === 'subscribe' && hash_equals($expected, (string) $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// ------------------------------------------------------------------
// POST: event delivery
// ------------------------------------------------------------------
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method not allowed';
    exit;
}

$raw = file_get_contents('php://input');

// --- Optional HMAC signature check ----
$secret = (string) $config->get('app_secret');
if ($secret !== '') {
    $sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (stripos($sigHeader, 'sha256=') === 0) {
        $sigHeader = substr($sigHeader, 7);
    }
    $expected = hash_hmac('sha256', $raw, $secret);
    if (!$sigHeader || !hash_equals($expected, $sigHeader)) {
        error_log('[whatsapp] bad X-Hub-Signature-256');
        http_response_code(401);
        echo 'bad signature';
        exit;
    }
}

// --- ACK Meta first, process after ----
// Meta expects a 200 within a few seconds. Flush the response then keep
// working. If your PHP-FPM / webserver doesn't support connection close,
// the alternative is to queue the payload and have cron dispatch it.
ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Best-effort for mod_php
    @ob_end_flush();
    @flush();
}

// --- Decode + dispatch ----
$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['entry'])) {
    exit;
}

try {
    $handler = new WhatsAppHandler($config);
    foreach ($payload['entry'] as $entry) {
        if (empty($entry['changes'])) {
            continue;
        }
        foreach ($entry['changes'] as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }
            $value = $change['value'] ?? array();
            if (!is_array($value)) {
                continue;
            }
            $handler->handleIncoming($value);
        }
    }
} catch (Throwable $e) {
    error_log('[whatsapp] webhook dispatch error: ' . $e->getMessage()
        . "\n" . $e->getTraceAsString());
}
