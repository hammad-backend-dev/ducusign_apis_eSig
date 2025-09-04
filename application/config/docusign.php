<?php
defined('BASEPATH') or exit('No direct script access allowed');

$config['integration_key'] = 'f1c78f6c-80a5-4807-b888-2c0f5fdaa91c';
$config['user_id'] = 'a5c0cb8c-2d8e-4f94-9096-a479eebba219';
$config['account_id'] = '316658b0-2144-4375-9f68-df517234f3ef';
$config['rsa_private_key_path'] = APPPATH . 'keys/private_pkcs8.pem';
$config['expires_in'] = 3600;
$config['base_path'] = 'https://demo.docusign.net/restapi';
$config['auth_server'] = 'account-d.docusign.com';
$config['scope'] = 'signature';
