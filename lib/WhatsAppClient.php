<?php
/**
 * Thin wrapper around the WhatsApp Cloud (Graph) API — send messages
 * and mark them read.
 *
 *   POST https://graph.facebook.com/{version}/{phone_number_id}/messages
 *
 * Uses cURL (shipped with every osTicket install).
 */
class WhatsAppClient
{
    /** @var PluginConfig */
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * Send a plain text message. WhatsApp accepts *bold*, _italic_,
     * ~strike~ and ```mono``` markdown in the body.
     *
     * @param string $to    Recipient in E.164 form without '+'.
     *                      e.g. '5511987654321'
     * @param string $body  Message body (max 4096 chars).
     * @return array|false  Decoded Meta response, or false on failure.
     */
    public function sendText($to, $body)
    {
        if ($body === '' || $body === null) {
            return false;
        }
        // WhatsApp hard limit
        if (strlen($body) > 4096) {
            $body = substr($body, 0, 4093) . '...';
        }

        $payload = array(
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => self::normalizePhone($to),
            'type'              => 'text',
            'text'              => array(
                'preview_url' => false,
                'body'        => $body,
            ),
        );

        return $this->post($payload);
    }

    /**
     * Mark an inbound message as read. Useful UX — user sees blue ticks
     * as soon as osTicket accepts the message.
     */
    public function markRead($messageId)
    {
        $payload = array(
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId,
        );
        return $this->post($payload);
    }

    /* ------------------------------------------------------------------
     * Internal
     * ---------------------------------------------------------------- */

    private function post(array $payload)
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->config->get('api_version') ?: 'v21.0',
            $this->config->get('phone_number_id')
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . trim(
                    (string) $this->config->get('access_token')),
                'Content-Type: application/json',
            ),
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ));

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('[whatsapp] cURL error: ' . $err);
            return false;
        }
        if ($code < 200 || $code >= 300) {
            error_log(sprintf(
                '[whatsapp] Meta returned HTTP %d: %s', $code, $raw));
            return false;
        }

        return json_decode($raw, true);
    }

    /**
     * Normalise a phone number: digits only, no '+'.
     */
    public static function normalizePhone($phone)
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }
}
