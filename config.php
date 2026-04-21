<?php
/**
 * Configuration form shown in Admin Panel -> Manage -> Plugins -> WhatsApp.
 * All values are persisted per-plugin-instance (osTicket 1.18 feature),
 * so you can run more than one WhatsApp number if you want.
 */
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class WhatsAppPluginConfig extends PluginConfig
{
    /**
     * Translation compatibility helper (osTicket < 1.9.4 safety).
     */
    public function translate()
    {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function ($x) { return $x; },
                function ($x, $y, $n) { return $n != 1 ? $y : $x; },
            );
        }
        return Plugin::translate('whatsapp');
    }

    public function getOptions()
    {
        list($__, $_N) = self::translate();

        return array(
            // ---------- API credentials ----------
            'api_section' => new SectionBreakField(array(
                'label' => $__('WhatsApp Cloud API Credentials'),
                'hint'  => $__('Get these from your Meta for Developers '
                    . 'app -> WhatsApp -> API Setup.'),
            )),
            'phone_number_id' => new TextboxField(array(
                'label' => $__('Phone Number ID'),
                'required' => true,
                'configuration' => array('size' => 40, 'length' => 64),
                'hint'  => $__('Numeric ID shown next to your test/production '
                    . 'WhatsApp number in Meta App dashboard.'),
            )),
            'access_token' => new TextareaField(array(
                'label' => $__('Access Token'),
                'required' => true,
                'configuration' => array('html' => false, 'rows' => 3,
                    'cols' => 60),
                'hint'  => $__('Permanent system-user access token. '
                    . 'Temporary tokens expire in 24h.'),
            )),
            'api_version' => new TextboxField(array(
                'label' => $__('Graph API Version'),
                'default' => 'v21.0',
                'configuration' => array('size' => 10, 'length' => 10),
            )),

            // ---------- Webhook ----------
            'hook_section' => new SectionBreakField(array(
                'label' => $__('Incoming Webhook'),
                'hint'  => $__('Point Meta webhook to '
                    . '<your-osticket>/api/whatsapp/webhook.php'),
            )),
            'verify_token' => new TextboxField(array(
                'label' => $__('Webhook Verify Token'),
                'required' => true,
                'configuration' => array('size' => 40, 'length' => 128),
                'hint'  => $__('Arbitrary string — must match what you '
                    . 'enter in Meta webhook setup.'),
            )),
            'app_secret' => new PasswordField(array(
                'label' => $__('App Secret (for X-Hub-Signature-256)'),
                'required' => false,
                'configuration' => array('size' => 40, 'length' => 128),
                'hint'  => $__('Optional but recommended: verifies incoming '
                    . 'webhook requests actually came from Meta.'),
            )),

            // ---------- Ticket defaults ----------
            'ticket_section' => new SectionBreakField(array(
                'label' => $__('Ticket Defaults'),
            )),
            'default_dept_id' => new ChoiceField(array(
                'label' => $__('Default Department'),
                'required' => true,
                'choices' => self::getDepartments(),
                'hint'  => $__('Department used for tickets created from '
                    . 'WhatsApp messages.'),
            )),
            'default_topic_id' => new ChoiceField(array(
                'label' => $__('Default Help Topic'),
                'required' => false,
                'choices' => array('' => '— none —') + self::getTopics(),
            )),
            'default_priority_id' => new ChoiceField(array(
                'label' => $__('Default Priority'),
                'required' => false,
                'choices' => array('' => '— default —')
                    + self::getPriorities(),
            )),
            'email_domain' => new TextboxField(array(
                'label' => $__('Synthetic Email Domain'),
                'default' => 'whatsapp.local',
                'configuration' => array('size' => 40, 'length' => 128),
                'hint'  => $__('WhatsApp users do not have an email. '
                    . 'osTicket still requires one, so we synthesise '
                    . '<phone>@<domain>.'),
            )),

            // ---------- Outbound notifications ----------
            'out_section' => new SectionBreakField(array(
                'label' => $__('Outbound Notifications'),
            )),
            'notify_on_reply' => new BooleanField(array(
                'label' => $__('Send agent replies to WhatsApp'),
                'default' => true,
            )),
            'notify_on_status' => new BooleanField(array(
                'label' => $__('Send status changes to WhatsApp'),
                'default' => true,
            )),
            'notify_on_create' => new BooleanField(array(
                'label' => $__('Send creation confirmation to WhatsApp'),
                'default' => true,
            )),
            'reply_template' => new TextareaField(array(
                'label' => $__('Agent reply template'),
                'default' => "Ticket #%{number}\n\n%{body}\n\n"
                    . "Reply to this message to add a comment.",
                'configuration' => array('html' => false, 'rows' => 4,
                    'cols' => 60),
            )),
        );
    }

    /* ------------------------------------------------------------------
     * Populate dropdowns from the osTicket DB.
     *
     * These are called from getOptions(), which runs on EVERY request
     * once the plugin is enabled — including client-portal and API
     * requests where the global $cfg isn't initialised yet. Some of
     * these osTicket helpers dereference $cfg internally (e.g.
     * Topic::getHelpTopics calls $cfg->getTopicSortMode()), so we
     * defensively fall back to an empty array + catch any fallout.
     * The dropdowns will be populated as soon as an admin opens the
     * plugin config page, which is the only place they're actually
     * displayed.
     * ---------------------------------------------------------------- */

    private static function getDepartments()
    {
        if (!self::osticketReady()) {
            return array();
        }
        try {
            $out = array();
            foreach (Dept::getDepartments() as $id => $name) {
                $out[$id] = $name;
            }
            return $out;
        } catch (Throwable $e) {
            return array();
        }
    }

    private static function getTopics()
    {
        if (!self::osticketReady()) {
            return array();
        }
        try {
            $out = array();
            foreach (Topic::getHelpTopics() as $id => $name) {
                $out[$id] = $name;
            }
            return $out;
        } catch (Throwable $e) {
            return array();
        }
    }

    private static function getPriorities()
    {
        if (!self::osticketReady()) {
            return array();
        }
        try {
            $out = array();
            foreach (Priority::getPriorities() as $id => $name) {
                $out[$id] = $name;
            }
            return $out;
        } catch (Throwable $e) {
            return array();
        }
    }

    /**
     * True when osTicket is initialised enough to query config-dependent
     * helpers safely. Admin pages set the $cfg global; client / API
     * entrypoints do not, at the point where plugin bootstrap runs.
     */
    private static function osticketReady()
    {
        global $cfg;
        return isset($cfg) && is_object($cfg);
    }
}
