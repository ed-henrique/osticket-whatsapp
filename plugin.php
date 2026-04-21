<?php
/**
 * WhatsApp plugin for osTicket 1.18.3
 *
 * Registers the plugin with osTicket. osTicket reads this file to get
 * metadata (id, version, name, etc.) and to know which class to instantiate
 * as the plugin.
 */
return array(
    'id'          => 'whatsapp:plugin',          // unique, not translated
    'version'     => '1.0.0',
    'ost_version' => '1.18',                     // target osTicket version
    'name'        => /* @trans */ 'WhatsApp Channel',
    'author'      => 'Your Name',
    'description' => /* @trans */
        'Lets end-users create tickets, reply to them and track their '
        . 'status through WhatsApp (Meta Cloud API).',
    'url'         => 'https://github.com/your-org/osticket-whatsapp',
    'plugin'      => 'whatsapp.php:WhatsAppPlugin',
);
