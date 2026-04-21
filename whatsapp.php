<?php
/**
 * Main plugin class for the WhatsApp channel.
 *
 * Responsibilities
 *  - Bootstrap the plugin (bootstrap() is called by osTicket on every
 *    request once the plugin is enabled).
 *  - Subscribe to osTicket signals so we can notify the WhatsApp user
 *    when an agent replies, when the ticket is created, or when status
 *    changes.
 *
 * The heavy lifting (HTTP calls, signature verification, ticket lookups,
 * new-ticket creation from incoming webhooks) lives in lib/WhatsAppClient,
 * lib/WhatsAppHandler and api/whatsapp/webhook.php.
 */
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.thread.php';

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/lib/WhatsAppClient.php';
require_once dirname(__FILE__) . '/lib/WhatsAppHandler.php';

class WhatsAppPlugin extends Plugin
{
    public $config_class = 'WhatsAppPluginConfig';

    /**
     * osTicket calls bootstrap() once per request after plugin is loaded
     * and enabled. This is where we hook signals.
     *
     * Important: bootstrap runs VERY early in osTicket::start(), before
     * the global $cfg is initialised on client-portal and API requests.
     * So we must not touch $cfg (or anything that touches $cfg like
     * Ticket/Dept/Topic/Priority helpers, or $this->getConfig()) in
     * here. We only register closures; the closures fetch config
     * lazily when they actually fire, which always happens later in the
     * request lifecycle when $cfg is available.
     */
    public function bootstrap()
    {
        $self = $this;

        // Outbound: agent replies -> WhatsApp
        Signal::connect(
            'threadentry.created',
            function ($entry) use ($self) {
                try {
                    $self->onThreadEntry($entry, $self->getConfig());
                } catch (Exception $e) {
                    error_log('[whatsapp] threadentry handler: '
                        . $e->getMessage());
                }
            }
        );

        // Outbound: ticket created -> confirmation to WhatsApp user
        Signal::connect(
            'ticket.created',
            function ($ticket) use ($self) {
                try {
                    $self->onTicketCreated($ticket, $self->getConfig());
                } catch (Exception $e) {
                    error_log('[whatsapp] ticket.created handler: '
                        . $e->getMessage());
                }
            }
        );

        // Outbound: model updated (catch status changes on Ticket)
        Signal::connect(
            'model.updated',
            function ($model, $data = null) use ($self) {
                if (!($model instanceof Ticket)) {
                    return;
                }
                try {
                    $self->onTicketUpdated($model, $data,
                        $self->getConfig());
                } catch (Exception $e) {
                    error_log('[whatsapp] model.updated handler: '
                        . $e->getMessage());
                }
            }
        );
    }

    /* ------------------------------------------------------------------
     * Signal handlers
     * ---------------------------------------------------------------- */

    /**
     * A new thread entry (message/response/note) was created. We only
     * forward agent responses — not user messages (they originated from
     * WhatsApp in the first place), not internal notes, not system events.
     */
    public function onThreadEntry(ThreadEntry $entry, $config)
    {
        if (!$config || !$config->get('notify_on_reply')) {
            return;
        }

        // Only "Response" entries (R), skip Message (M) and Note (N).
        if ($entry->getType() !== 'R') {
            return;
        }

        $ticket = self::resolveTicket($entry);
        if (!$ticket) {
            return;
        }

        $phone = self::getWhatsAppPhone($ticket);
        if (!$phone) {
            return; // ticket wasn't opened via WhatsApp
        }

        $template = $config->get('reply_template');
        $body = self::renderTemplate($template, array(
            'number' => $ticket->getNumber(),
            'body'   => self::plainText($entry->getBody()),
            'status' => (string) $ticket->getStatus(),
            'subject' => $ticket->getSubject(),
        ));

        $client = new WhatsAppClient($config);
        $client->sendText($phone, $body);
    }

    /**
     * Ticket was just created — send acknowledgement if it came from
     * WhatsApp and confirmations are enabled.
     */
    public function onTicketCreated(Ticket $ticket, $config)
    {
        if (!$config || !$config->get('notify_on_create')) {
            return;
        }

        $phone = self::getWhatsAppPhone($ticket);
        if (!$phone) {
            return;
        }

        $msg = sprintf(
            "Hi! We received your request.\n\n"
            . "Your ticket number is *#%s*.\n"
            . "Reply to this chat anytime to add information.\n"
            . "Send *status* to check the current state of your ticket.",
            $ticket->getNumber()
        );

        $client = new WhatsAppClient($config);
        $client->sendText($phone, $msg);
    }

    /**
     * Catch status changes. model.updated fires for lots of reasons, so
     * we filter by looking at the 'dirty' field set.
     */
    public function onTicketUpdated(Ticket $ticket, $data, $config)
    {
        if (!$config || !$config->get('notify_on_status')) {
            return;
        }

        // $data, when provided, is the dirty array from Ticket::save().
        // We only care if status_id changed.
        if (!is_array($data) || !isset($data['dirty']['status_id'])) {
            return;
        }

        $phone = self::getWhatsAppPhone($ticket);
        if (!$phone) {
            return;
        }

        $status = (string) $ticket->getStatus();
        $msg = sprintf(
            "Ticket *#%s* status changed to *%s*.",
            $ticket->getNumber(),
            $status
        );

        $client = new WhatsAppClient($config);
        $client->sendText($phone, $msg);
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------- */

    /**
     * Given a ThreadEntry, climb up to its Ticket. In 1.18 a ThreadEntry
     * belongs to a Thread which belongs to an ObjectModel — we only care
     * when that object is a Ticket.
     */
    private static function resolveTicket(ThreadEntry $entry)
    {
        $thread = $entry->getThread();
        if (!$thread) {
            return null;
        }
        $obj = $thread->getObject();
        return ($obj instanceof Ticket) ? $obj : null;
    }

    /**
     * Returns the WhatsApp phone number for the ticket owner, or null if
     * the ticket wasn't opened via WhatsApp.
     *
     * We recognise a WhatsApp user via (in order of preference):
     *   1. The 'whatsapp_phone' stamp on their UserAccount extra blob —
     *      set by the handler on every inbound message. This works for
     *      pre-existing users whose email isn't on our synthetic domain.
     *   2. Fallback: an email address on the configured synthetic domain
     *      (used by users created by the plugin itself).
     */
    private static function getWhatsAppPhone(Ticket $ticket)
    {
        $user = $ticket->getOwner();
        if (!$user) {
            return null;
        }

        // 1. UserAccount extra attribute — preferred
        if (class_exists('WhatsAppHandler')) {
            $stamp = WhatsAppHandler::getRememberedPhone($user);
            if ($stamp) {
                return $stamp;
            }
        }

        // 2. Synthetic email fallback
        $email = $user->getEmail();
        $emailStr = $email ? (string) $email : '';
        if (strpos($emailStr, '@') !== false) {
            $domain = strtolower(substr($emailStr,
                strpos($emailStr, '@') + 1));
            if (strpos($domain, 'whatsapp') === 0
                || $domain === 'whatsapp.local'
            ) {
                return substr($emailStr, 0, strpos($emailStr, '@'));
            }
        }
        return null;
    }

    /**
     * Very small {{mustache}}-style replacer for notification templates.
     */
    public static function renderTemplate($tpl, array $vars)
    {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('%{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }

    /**
     * Strip HTML, convert entities, collapse whitespace. WhatsApp
     * messages are plain text (with *bold* / _italic_ markdown).
     */
    public static function plainText($html)
    {
        $txt = Format::html2text((string) $html, 120);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
        return trim($txt);
    }

    /**
     * Convenience: find the enabled instance's config from anywhere.
     * Used by webhook.php which lives outside the plugin class.
     *
     * The real osTicket 1.18 API is:
     *   - PluginManager::allActive()            -> iterable of Plugin
     *   - Plugin->getActiveInstances()          -> iterable of PluginInstance
     *   - PluginInstance->getConfig()           -> PluginConfig (our subclass)
     *
     * If multiple WhatsApp instances are enabled we just pick the first;
     * a future enhancement could route based on phone_number_id in the
     * webhook payload to the matching instance.
     */
    public static function activeConfig()
    {
        if (!class_exists('PluginManager')) {
            return null;
        }

        // Gather plugins from whichever enumeration API the running
        // osTicket exposes. allActive() is the 1.17+ way.
        $plugins = array();
        try {
            if (method_exists('PluginManager', 'allActive')) {
                foreach (PluginManager::allActive() as $p) {
                    $plugins[] = $p;
                }
            } elseif (method_exists('PluginManager', 'allInstalled')) {
                foreach (PluginManager::allInstalled() as $p) {
                    if (method_exists($p, 'isActive') && !$p->isActive()) {
                        continue;
                    }
                    $plugins[] = $p;
                }
            }
        } catch (Throwable $e) {
            error_log('[whatsapp] activeConfig enumeration: '
                . $e->getMessage());
            return null;
        }

        foreach ($plugins as $p) {
            if (!($p instanceof Plugin)) {
                continue;
            }
            // Only interested in our own plugin
            if (!(method_exists($p, 'getImpl') && $p->getImpl() instanceof self)
                && !($p instanceof self)
                && strcasecmp(get_class($p), 'WhatsAppPlugin') !== 0
            ) {
                // Not us — check the impl class name via info array as a
                // fallback (Plugin wraps WhatsAppPlugin in some versions).
                $info = method_exists($p, 'getInfo') ? $p->getInfo() : null;
                if (!is_array($info)
                    || stripos((string) ($info['plugin'] ?? ''),
                        'WhatsAppPlugin') === false
                ) {
                    continue;
                }
            }

            // Pick the first active instance
            $instances = method_exists($p, 'getActiveInstances')
                ? $p->getActiveInstances()
                : (method_exists($p, 'getInstances')
                    ? $p->getInstances() : array());

            foreach ($instances as $inst) {
                if (!$inst) { continue; }
                try {
                    // PluginInstance::getConfig() (via Plugin::getConfig)
                    $cfg = method_exists($inst, 'getConfig')
                        ? $inst->getConfig()
                        : null;
                    if ($cfg instanceof PluginConfig) {
                        return $cfg;
                    }
                } catch (Throwable $e) {
                    error_log('[whatsapp] instance getConfig: '
                        . $e->getMessage());
                }
            }
        }
        return null;
    }
}
