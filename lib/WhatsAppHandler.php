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
            if ($ticket && $this->ownerPhone($ticket) === $from) {
                $this->addReplyTo($ticket, $m[2]);
                return;
            }
            // fall through and treat as a normal message (we don't leak
            // the existence of someone else's ticket)
        }

        // --- Default path: append to open ticket, else create new -----------
        $ticket = $this->findOpenTicketForPhone($from);
        if ($ticket) {
            $this->addReplyTo($ticket, $body);
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
     * User lookup: by our synthetic e-mail <phone>@<domain>. We also
     * create the user on first contact.
     */
    private function findOrCreateUser($phone, array $profile)
    {
        $email  = $phone . '@' . $this->emailDomain();
        $user   = User::lookupByEmail($email);
        if ($user) {
            return $user;
        }

        $name = !empty($profile['name']) ? $profile['name'] : $phone;

        // UserForm is the 'contact info' form; populate its fields.
        $vars = array(
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        );
        $errors = array();
        $user = User::fromVars($vars, true);  // true = create if missing
        if (!$user) {
            // Last-resort fallback: direct create
            $user = User::lookupByEmail($email);
        }
        return $user;
    }

    private function findUserByPhone($phone)
    {
        $email = $phone . '@' . $this->emailDomain();
        return User::lookupByEmail($email);
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

    private function ownerPhone(Ticket $ticket)
    {
        $email = $ticket->getOwner()
            ? (string) $ticket->getOwner()->getEmail() : '';
        if (strpos($email, '@') === false) {
            return null;
        }
        $local  = substr($email, 0, strpos($email, '@'));
        $domain = substr($email, strpos($email, '@') + 1);
        return ($domain === $this->emailDomain()) ? $local : null;
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

        $subject = self::truncate(
            preg_replace('/\s+/', ' ', trim($body)),
            60
        );
        if ($subject === '') {
            $subject = 'WhatsApp message from ' . $phone;
        }

        $vars = array(
            'source'   => 'API', // closest built-in; the synth domain flags WA
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
     */
    private function addReplyTo(Ticket $ticket, $body)
    {
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
