# osTicket WhatsApp Plugin

A plugin for **osTicket 1.18.3** that turns WhatsApp into a full
customer-support channel. End users can:

- **Create tickets** by simply sending a message to your WhatsApp
  Business number.
- **Add comments** to their open ticket — every follow-up message gets
  appended as a user-reply on their most recent open ticket.
- **Track tickets** with the `status`, `list`, and `#<id>` commands.

Agents reply from the osTicket admin panel as they normally would; their
response is pushed back to the user on WhatsApp automatically.

## Requirements

- osTicket **1.18.x** (tested on 1.18.3)
- PHP **8.2 – 8.4** with `ext-curl` and `ext-json`
- HTTPS-reachable osTicket install (Meta will not call a plain-HTTP
  webhook)
- A Meta for Developers app with the **WhatsApp Business Platform /
  Cloud API** product enabled
- A WhatsApp Business phone number (test number is fine for development)

## File layout

```
include/plugins/whatsapp/
├── plugin.php                # manifest (osTicket discovers this)
├── config.php                # admin form
├── whatsapp.php              # plugin class + signal wiring
├── lib/
│   ├── WhatsAppClient.php    # outbound Graph API calls
│   └── WhatsAppHandler.php   # inbound -> osTicket tickets/comments
└── README.md

api/whatsapp/
└── webhook.php               # HTTP endpoint Meta calls
```

## Install

1. **Copy plugin files** — place the `whatsapp/` folder in
   `include/plugins/` of your osTicket install:

   ```
   cp -r whatsapp /path/to/osticket/include/plugins/
   ```

2. **Copy the webhook** — create an `api/whatsapp/` folder at the
   install root and drop `webhook.php` in it:

   ```
   mkdir -p /path/to/osticket/api/whatsapp
   cp whatsapp/api/whatsapp/webhook.php /path/to/osticket/api/whatsapp/
   ```

3. **Install in osTicket** — log in as admin, go to
   **Admin Panel → Manage → Plugins**, click **Add New Plugin**, then
   **Install** next to "WhatsApp Channel".

4. **Create a plugin instance**: click the plugin, then **Add New
   Instance**, and fill in:

   | Field | Where to find it |
   | --- | --- |
   | Phone Number ID | Meta App → WhatsApp → API Setup |
   | Access Token | Meta App → WhatsApp → API Setup (use a *permanent* system-user token for production) |
   | Graph API Version | Default `v21.0` works |
   | Webhook Verify Token | Any string you pick; you'll enter the same string in Meta |
   | App Secret | Meta App → Settings → Basic (optional; enables HMAC verification) |
   | Default Department / Topic / Priority | Where WhatsApp tickets land |
   | Synthetic Email Domain | Leave as `whatsapp.local` unless you have a reason |

   Save, then **enable** the instance.

5. **Configure the Meta webhook**:

   - Meta App → WhatsApp → Configuration → Webhook
   - **Callback URL**: `https://your-osticket.example.com/api/whatsapp/webhook.php`
   - **Verify Token**: the exact same string you put in the plugin config
   - Subscribe to the **`messages`** webhook field (at minimum)

6. **Send a test message** to your WhatsApp Business number. Check
   **Tickets** in osTicket — a new ticket should appear. The user should
   receive a confirmation on WhatsApp.

## How it works

### Inbound (WhatsApp → osTicket)

```
WhatsApp user  ─(1)──►  Meta Cloud API  ─(2)──►  /api/whatsapp/webhook.php
                                                            │
                                                            ▼
                                                   WhatsAppHandler
                                                            │
                          ┌─────── no user exists? ─── User::fromVars()
                          │
                          ├─── open ticket exists? ─── Ticket::postMessage()
                          │
                          └─── otherwise ──────────── Ticket::create()
```

The user is looked up by the synthetic e-mail address
`<phone>@whatsapp.local`. The address is created on first contact, so
nothing needs to be pre-provisioned.

### Outbound (osTicket → WhatsApp)

The plugin subscribes to three osTicket signals:

- `ticket.created` — optional confirmation to user
- `threadentry.created` — agent **Response** entries (`type=R`) are
  forwarded; internal Notes and the user's own Messages are not.
- `model.updated` on a `Ticket` — status changes are forwarded.

Outbound formatting uses WhatsApp's markdown (`*bold*`, `_italic_`).

### Commands recognised in an inbound message

| Message | Action |
| --- | --- |
| `status` / `estado` | Show the user's most recent open ticket & status |
| `list` / `tickets` | List the user's 5 most recent tickets |
| `help` / `menu` | Show the command list |
| `#123 <text>` | Add `<text>` as a comment to ticket **#123** (only if the user owns that ticket) |
| *anything else* | Append to current open ticket, or create a new one |

## Notes / limitations

- **Media**: images, audio, video, and documents are acknowledged but
  not yet downloaded into osTicket as attachments. Extending
  `WhatsAppHandler::extractBody()` to call `GET /{media-id}` and
  attaching via osTicket's `Attachment` class is a straightforward next
  step.
- **Templates**: outbound messages use plain text — fine for customer
  service replies. If you need to contact a user outside the 24-hour
  customer-service window, extend `WhatsAppClient` to send
  [message templates](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-message-templates).
- **Multiple instances**: the plugin supports osTicket 1.18 plugin
  instances, so you can run more than one WhatsApp number. Each
  instance has its own config row and webhook URL can be
  `webhook.php?instance=<id>` if you add that dispatch logic (the
  current `activeConfig()` picks the first enabled instance).
- **Rate limits**: Meta Cloud API enforces tiered per-second limits;
  `WhatsAppClient` does not retry on 429. If you expect bursts, wrap
  `WhatsAppClient::post()` with an exponential backoff or push to a
  queue.

## Troubleshooting

- **Webhook verification fails**: ensure the Verify Token in Meta is
  byte-for-byte identical to the one in the plugin config.
- **Messages not arriving in osTicket**: check your PHP error log.
  Every failure path in this plugin writes to `error_log()` with a
  `[whatsapp]` prefix.
- **Agent replies not reaching WhatsApp**: confirm the user's email in
  osTicket is `<phone>@<your email_domain>`. If you renamed the domain
  after creating users, the plugin won't recognise pre-existing users.

## License

GPL-2.0 (matches osTicket's licence).
