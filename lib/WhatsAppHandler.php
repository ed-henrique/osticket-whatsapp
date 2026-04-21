<?php
/**
 * Turns incoming WhatsApp webhook payloads into osTicket actions:
 *
 *   - new ticket if the user has no open tickets
 *   - a message (thread entry) on the most-recent open ticket otherwise
 *   - a canned status response when user types a command (e.g. "status")
 *
 * This class is deliberately decoupled from HTTP so it can be exercised
 * from unit tests with a fake payload.
 */
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.user.php';
require_once INCLUDE_DIR . 'class.thread.php';
require_once INCLUDE_DIR . 'class.format.php';

class WhatsAppHandler
{
    /** @var PluginConfig */
    private $config;
    /** @var WhatsAppClient */
    private $client;

    /**
     * Command keywords -> handler method. Matched case-insensitively on
     * the *trimmed, whole* message body.
     */
    private static $commands = array(
        'status'   => 'cmdStatus',
        'estado'   => 'cmdStatus',
        'situação' => 'cmdStatus',
        'situacao' => 'cmdStatus',
        'list'     => 'cmdList',
        'tickets'  => 'cmdList',
        'help'     => 'cmdHelp',
        'ajuda'    => 'cmdHelp',
        'menu'     => 'cmdHelp',
    );

    public function __construct($config)
    {
        $this->config = $config;
        $this->client = new WhatsAppClient($config);
    }

    /**
     * Entry point. $value is the 'value' field of a single
     * messages webhook change.
     *
     * Structure (truncated):
     *   {
     *     "messaging_product": "whatsapp",
     *     "metadata": { "phone_number_id": "..." },
     *     "contacts": [ { "profile": {"name":"..."}, "wa_id": "..." } ],
     *     "messages": [ { "from":"...", "id":"...", "timestamp":"...",
     *                     "type":"text", "text":{"body":"..."} } ]
     *   }
     */
    public function handleIncoming(array $value)
    {
        if (empty($value['messages'])) {
            // delivery / read status update — ignore
            return;
        }

        $profile = isset($value['contacts'][0]['profile'])
            ? $value['contacts'][0]['profile'] : array();

        foreach ($value['messages'] as $msg) {
            try {
                $this->handleOneMessage($msg, $profile);
            } catch (Exception $e) {
                error_log('[whatsapp] message handler error: '
                    . $e->getMessage());
            }
        }
    }

    /* ------------------------------------------------------------------
     * One message
     * ---------------------------------------------------------------- */

    private function handleOneMessage(array $msg, array $profile)
    {
        $from = isset($msg['from']) ? WhatsAppClient::normalizePhone(
            $msg['from']) : '';
        if (!$from) {
            return;
        }

        // Tell WhatsApp we've got it (blue ticks). Best-effort.
        if (!empty($msg['id'])) {
            $this->client->markRead($msg['id']);
        }

        $type = isset($msg['type']) ? $msg['type'] : 'unsupported';
        $body = $this->extractBody($msg, $type);

        if ($body === null) {
            // Unsupported media (audio/location/etc). Let the user know.
            $this->client->sendText($from,
                "Sorry, we can't process that type of message yet. "
                . "Please send text.");
            return;
        }

        // --- Command routing -------------------------------------------------
        $trimmed = trim(mb_strtolower($body));
        if (isset(self::$commands[$trimmed])) {
            $method = self::$commands[$trimmed];
            $this->$method($from, $profile);
            return;
        }

        // --- Comment-on-existing-ticket shortcut:  "#123 body..." -----------
        if (preg_match('/^#?(\d{3,12})\s+(.+)$/s', $body, $m)) {
            $ticket = Ticket::lookupByNumber($m[1]);
            if ($ticket && $this->ticketBelongsTo($ticket, $from)) {
                $this->addReplyTo($ticket, $m[2], $from);
                return;
            }
            // fall through and treat as a normal message (we don't leak
            // the existence of someone else's ticket)
        }

        // --- Default path: append to open ticket, else create new -----------
        $ticket = $this->findOpenTicketForPhone($from);
        if ($ticket) {
            $this->addReplyTo($ticket, $body, $from);
            return;
        }

        $this->createTicketFrom($from, $profile, $body);
    }

    /* ------------------------------------------------------------------
     * Commands
     * ---------------------------------------------------------------- */

    private function cmdHelp($from, array $profile)
    {
        $this->client->sendText($from,
            "Available commands:\n\n"
            . "*status* — show status of your most recent open ticket\n"
            . "*list* — list your 5 most recent tickets\n"
            . "*#<num> <text>* — add a comment to ticket <num>\n"
            . "Any other message opens a new ticket or adds to your "
            . "current one.");
    }

    private function cmdStatus($from, array $profile)
    {
        $ticket = $this->findOpenTicketForPhone($from);
        if (!$ticket) {
            // Try latest closed one
            $ticket = $this->findLatestTicketForPhone($from);
        }
        if (!$ticket) {
            $this->client->sendText($from,
                "You don't have any tickets yet. Send a message describing "
                . "your issue to open one.");
            return;
        }
        $this->client->sendText($from, sprintf(
            "Ticket *#%s*\nSubject: %s\nStatus: *%s*\nLast update: %s",
            $ticket->getNumber(),
            $ticket->getSubject(),
            (string) $ticket->getStatus(),
            Format::datetime($ticket->getLastUpdate())
        ));
    }

    private function cmdList($from, array $profile)
    {
        $user = $this->findUserByPhone($from);
        if (!$user) {
            $this->client->sendText($from,
                "You don't have any tickets yet.");
            return;
        }

        $tickets = Ticket::objects()
            ->filter(array('user_id' => $user->getId()))
            ->order_by('-created')
            ->limit(5);

        $lines = array();
        foreach ($tickets as $t) {
            $lines[] = sprintf('#%s — %s (%s)',
                $t->getNumber(),
                self::truncate($t->getSubject(), 40),
                (string) $t->getStatus());
        }
        if (!$lines) {
            $this->client->sendText($from, "You don't have any tickets yet.");
            return;
        }
        $this->client->sendText($from,
            "Your recent tickets:\n\n" . implode("\n", $lines));
    }

    /* ------------------------------------------------------------------
     * Ticket + User lookups / creation
     * ---------------------------------------------------------------- */

    /**
     * Extract a printable body from a message regardless of type.
     * Returns null if the message type is unsupported.
     */
    private function extractBody(array $msg, $type)
    {
        switch ($type) {
            case 'text':
                return isset($msg['text']['body'])
                    ? (string) $msg['text']['body'] : '';
            case 'button':
                return isset($msg['button']['text'])
                    ? (string) $msg['button']['text'] : '';
            case 'interactive':
                if (isset($msg['interactive']['button_reply']['title'])) {
                    return (string) $msg['interactive']
                        ['button_reply']['title'];
                }
                if (isset($msg['interactive']['list_reply']['title'])) {
                    return (string) $msg['interactive']
                        ['list_reply']['title'];
                }
                return '';
            case 'image':
            case 'video':
            case 'audio':
            case 'document':
            case 'sticker':
            case 'location':
            case 'contacts':
            default:
                // We don't download media here — would need a second API call
                // to /{media-id} and attachment handling. Out of scope.
                return null;
        }
    }

    /**
     * Find an osTicket user that corresponds to this WhatsApp phone
     * number, or create one on first contact.
     *
     * Lookup order (first match wins):
     *
     *   1. Our synthetic email <phone>@whatsapp.local — fastest path,
     *      hits the User.emails index. Used for returning WhatsApp-only
     *      users we've created before.
     *
     *   2. Existing osTicket user whose Contact-Information "phone"
     *      field resolves to the same number (we compare digits-only
     *      and match on the trailing 10 digits, so "+55 (11) 98765-4321"
     *      and "5511987654321" are treated as the same number).
     *
     *   3. Create a fresh user with the synthetic email.
     *
     * Step 2 is what keeps us from creating a ghost account for a
     * customer who already exists in your help desk.
     */
    private function findOrCreateUser($phone, array $profile)
    {
        // --- 1. synthetic email ---
        $user = $this->lookupBySyntheticEmail($phone);
        if ($user) {
            return $user;
        }

        // --- 2. existing user by phone on Contact Info form ---
        $user = $this->lookupByContactPhone($phone);
        if ($user) {
            return $user;
        }

        // --- 3. create a new user ---
        return $this->createSyntheticUser($phone, $profile);
    }

    /**
     * Used by every ticket-tracking helper ("status", "list", comment
     * shortcuts). Unlike findOrCreateUser() this is read-only and must
     * return null for an unknown number.
     */
    private function findUserByPhone($phone)
    {
        $user = $this->lookupBySyntheticEmail($phone);
        if ($user) {
            return $user;
        }
        return $this->lookupByContactPhone($phone);
    }

    /* ------------------------------------------------------------------
     * User lookup internals
     * ---------------------------------------------------------------- */

    private function lookupBySyntheticEmail($phone)
    {
        $email = $phone . '@' . $this->emailDomain();
        return User::lookupByEmail($email);
    }

    /**
     * Find an existing osTicket user by the "phone" field on their
     * Contact Information form entry.
     *
     * form_entry          -> one row per (object_type='U', object_id=user_id)
     *  form_entry_values  -> one row per field (identified by form_field.name)
     *
     * We normalise both sides to digits-only and match the trailing 10
     * characters, so formatting differences (+, spaces, parens, dashes,
     * trunk prefixes) don't cause misses. 10 digits is the universal
     * minimum that uniquely identifies a mobile number in most
     * countries (Brazil uses 10–11).
     *
     * If your user base is multi-country and collisions on the last 10
     * digits are a worry, make this method stricter (e.g. compare 11
     * digits, or require an exact equality).
     */
    private function lookupByContactPhone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) < 8) {
            return null;
        }
        // Match last 10 digits, or the full string if it's shorter
        $needle = substr($digits, -10);

        try {
            $sql = 'SELECT fe.object_id AS user_id
                    FROM ' . TABLE_PREFIX . 'form_entry fe
                    JOIN ' . TABLE_PREFIX . 'form_entry_values fev
                      ON fev.entry_id = fe.id
                    JOIN ' . TABLE_PREFIX . 'form_field ff
                      ON ff.id = fev.field_id
                    WHERE fe.object_type = \'U\'
                      AND ff.name = \'phone\'
                      AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                            COALESCE(fev.value, \'\'),
                              \'+\', \'\'),
                              \' \', \'\'),
                              \'-\', \'\'),
                              \'(\', \'\'),
                              \')\', \'\'),
                              \'.\', \'\')
                          LIKE ?
                    ORDER BY fe.updated DESC
                    LIMIT 1';

            // LIKE '%<last 10 digits>' — trailing match only
            $like = '%' . $needle;

            if (!class_exists('DbEngine')) {
                require_once INCLUDE_DIR . 'class.orm.php';
            }

            // db_query doesn't support prepared statements; escape the
            // needle (digits only, so already safe, but belt and braces)
            $like = db_real_escape($like, true);
            $sql  = str_replace('?', $like, $sql);

            $res = db_query($sql);
            if ($res && ($row = db_fetch_array($res))) {
                return User::lookup((int) $row['user_id']);
            }
        } catch (Throwable $e) {
            error_log('[whatsapp] contact-phone lookup: '
                . $e->getMessage());
        }
        return null;
    }

    /**
     * Last-resort path: create a brand-new user with our synthetic
     * email. Used for numbers that don't match any existing user.
     */
    private function createSyntheticUser($phone, array $profile)
    {
        $email = $phone . '@' . $this->emailDomain();
        $name  = !empty($profile['name']) ? $profile['name'] : $phone;

        $vars = array(
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        );
        $user = User::fromVars($vars, true);   // true = create if missing
        if (!$user) {
            // Race / form-validation edge case — try one more lookup
            $user = User::lookupByEmail($email);
        }
        return $user;
    }

    private function findOpenTicketForPhone($phone)
    {
        $user = $this->findUserByPhone($phone);
        if (!$user) {
            return null;
        }
        // Get the newest non-closed ticket owned by this user.
        $t = Ticket::objects()
            ->filter(array(
                'user_id'        => $user->getId(),
                'status__state'  => 'open',
            ))
            ->order_by('-created')
            ->limit(1);
        foreach ($t as $ticket) {
            return $ticket;
        }
        return null;
    }

    private function findLatestTicketForPhone($phone)
    {
        $user = $this->findUserByPhone($phone);
        if (!$user) {
            return null;
        }
        $t = Ticket::objects()
            ->filter(array('user_id' => $user->getId()))
            ->order_by('-created')
            ->limit(1);
        foreach ($t as $ticket) {
            return $ticket;
        }
        return null;
    }

    /**
     * True if the ticket's owner can be identified as this WhatsApp
     * sender, via any of: remembered phone stamp, synthetic email, or
     * matching Contact-Info phone.
     *
     * Used to gate the "#123 some text" shortcut so users can only
     * comment on tickets they actually own.
     */
    private function ticketBelongsTo(Ticket $ticket, $phone)
    {
        $owner = $ticket->getOwner();
        if (!$owner) {
            return false;
        }

        $normFrom = WhatsAppClient::normalizePhone($phone);

        // 1. Remembered whatsapp_phone stamp — fastest, most reliable
        $stamp = self::getRememberedPhone($owner);
        if ($stamp && self::phonesMatch($stamp, $normFrom)) {
            return true;
        }

        // 2. Synthetic <phone>@whatsapp.local email
        $email = (string) $owner->getEmail();
        if (strpos($email, '@') !== false) {
            $local  = substr($email, 0, strpos($email, '@'));
            $domain = substr($email, strpos($email, '@') + 1);
            if ($domain === $this->emailDomain()
                && self::phonesMatch($local, $normFrom)
            ) {
                return true;
            }
        }

        // 3. Contact-info phone on the user (pre-existing osTicket user)
        $match = $this->lookupByContactPhone($phone);
        return $match && $match->getId() === $owner->getId();
    }

    /**
     * Loose phone equality: digits only, trailing 10-char match.
     */
    public static function phonesMatch($a, $b)
    {
        $a = preg_replace('/\D+/', '', (string) $a);
        $b = preg_replace('/\D+/', '', (string) $b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $tail = 10;
        return substr($a, -$tail) === substr($b, -$tail);
    }

    /**
     * Create a new ticket with $body as the initial message.
     */
    private function createTicketFrom($phone, array $profile, $body)
    {
        $user = $this->findOrCreateUser($phone, $profile);
        if (!$user) {
            error_log('[whatsapp] could not create user for ' . $phone);
            return;
        }

        // Stamp the WhatsApp phone on the user's account so outbound
        // signals know to push replies back to this number, even for
        // pre-existing users whose email isn't @whatsapp.local.
        self::rememberWhatsAppPhone($user, $phone);

        $subject = self::truncate(
            preg_replace('/\s+/', ' ', trim($body)),
            60
        );
        if ($subject === '') {
            $subject = 'WhatsApp message from ' . $phone;
        }

        $vars = array(
            'source'   => 'API',
            'name'     => $user->getName(),
            'email'    => (string) $user->getEmail(),
            'phone'    => $phone,
            'subject'  => $subject,
            'message'  => 'data:text/plain,' . rawurlencode($body),
            'ip'       => '0.0.0.0',
            'deptId'   => (int) $this->config->get('default_dept_id'),
            'topicId'  => (int) $this->config->get('default_topic_id'),
            'userId'   => $user->getId(),
            'flags'    => 0,
            'autorespond' => false,
        );

        $pid = (int) $this->config->get('default_priority_id');
        if ($pid > 0) {
            $vars['priorityId'] = $pid;
        }

        $errors = array();
        $ticket = Ticket::create($vars, $errors, 'API', false, false);

        if (!$ticket) {
            error_log('[whatsapp] Ticket::create failed: '
                . print_r($errors, true));
            $this->client->sendText($phone,
                "Sorry, we couldn't open your ticket. Please try again.");
            return;
        }
        // The ticket.created signal fires in Ticket::create — that's what
        // sends the confirmation message. Nothing more to do here.
    }

    /**
     * Add an incoming WhatsApp message as a user-message on an existing
     * ticket. This mirrors what the web client portal does.
     *
     * $phone is the WhatsApp number the inbound message came from; we
     * re-stamp it on the user's account so outbound signals know this
     * user is a WhatsApp user even if they were already in the system.
     */
    private function addReplyTo(Ticket $ticket, $body, $phone = null)
    {
        if ($phone && $ticket->getOwner()) {
            self::rememberWhatsAppPhone($ticket->getOwner(), $phone);
        }

        $vars = array(
            'body'    => $body,
            'mid'     => null,
            'userId'  => $ticket->getOwnerId(),
        );

        $errors = array();
        // postMessage() is what the client portal + email pipe use.
        // $origin = 'API' so internal messaging logic treats it as user-sent.
        $entry = $ticket->postMessage($vars, 'API', false);

        if (!$entry) {
            error_log('[whatsapp] postMessage failed: '
                . print_r($errors, true));
        }
    }

    /* ------------------------------------------------------------------
     * WhatsApp phone stamp on the user account.
     *
     * osTicket's UserAccount has a free-form 'extra' JSON blob. We store
     * the user's WhatsApp phone there under 'whatsapp_phone'. This is
     * the marker that makes getWhatsAppPhone() in whatsapp.php return a
     * non-null value for THIS user, which is what unlocks outbound
     * notifications.
     *
     * If the user has no account (rare — only agent-created users may
     * skip having one) we fall back to no-op. Everything still works;
     * we just won't push outbound messages to that specific user.
     * ---------------------------------------------------------------- */

    public static function rememberWhatsAppPhone($user, $phone)
    {
        if (!$user || !$phone) {
            return;
        }
        $account = method_exists($user, 'getAccount')
            ? $user->getAccount() : null;
        if (!$account) {
            // Create an account shell if missing so we have somewhere to
            // store the attribute. UserAccount::register creates one
            // without authentication credentials.
            try {
                $account = UserAccount::register($user, array());
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$account) {
            return;
        }
        try {
            $account->setExtraAttr('whatsapp_phone',
                WhatsAppClient::normalizePhone($phone));
            $account->save();
        } catch (Throwable $e) {
            error_log('[whatsapp] rememberWhatsAppPhone: '
                . $e->getMessage());
        }
    }

    public static function getRememberedPhone($user)
    {
        if (!$user) {
            return null;
        }
        $account = method_exists($user, 'getAccount')
            ? $user->getAccount() : null;
        if (!$account) {
            return null;
        }
        try {
            $val = $account->getExtraAttr('whatsapp_phone');
            return $val ? (string) $val : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------
     * Small utilities
     * ---------------------------------------------------------------- */

    private function emailDomain()
    {
        $d = trim((string) $this->config->get('email_domain'));
        return $d !== '' ? $d : 'whatsapp.local';
    }

    private static function truncate($s, $max)
    {
        $s = (string) $s;
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
