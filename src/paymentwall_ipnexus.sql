INSERT INTO `nexus_gateways` (`g_id`, `g_key`, `g_name`, `g_settings`, `g_testmode`, `g_position`, `g_payout`) 
VALUES (
    NULL
    , 'paymentwall'
    , 'Paymentwall'
    , 'a:5:{s:7:\"api_key\";a:1:{s:4:\"type\";s:9:\"formInput\";}s:13:\"api_secretkey\";a:1:{s:4:\"type\";s:9:\"formInput\";}s:11:\"widget_code\";a:1:{s:4:\"type\";s:9:\"formInput\";}s:9:\"test_mode\";a:1:{s:4:\"type\";s:12:\"formCheckbox\";}s:11:\"success_url\";a:1:{s:4:\"type\";s:9:\"formInput\";}}'
    , 1
    , 1
    , 0
);

REPLACE INTO `core_sys_lang_words` (`word_id`, `lang_id`, `word_app`, `word_pack`, `word_key`, `word_default`, `word_custom`, `word_default_version`, `word_custom_version`, `word_js`) VALUES 
(NULL, 1, 'nexus', 'public_gateways',  'gateway_title_paymentwall_api_key', 'API Key',  '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_desc_paymentwall_api_key', 'Project key of your project, can be found inside of your Paymentwall Merchant Account in the Project settings section. ', '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_title_paymentwall_api_secretkey', 'API Secret key', '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_desc_paymentwall_api_secretkey', 'Secret key of your project, can be found inside of your Paymentwall Merchant Account in the Project settings section.', '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_title_paymentwall_widget_code', 'Widget Code', '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_desc_paymentwall_widget_code', 'e.g. p1 or p1_1, can be found inside of your Paymentwall Merchant account in the Widgets section.', '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_title_paymentwall_success_url', 'Success Url', '', '34014', NULL, 0),
(NULL, 1, 'nexus', 'public_gateways',  'gateway_title_paymentwall_test_mode', 'Test Mode', '', '34014', NULL, 0);