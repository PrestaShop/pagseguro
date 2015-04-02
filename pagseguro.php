<?php
/**
 * 2007-2013 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2014 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

include_once dirname(__FILE__) . '/features/PagSeguroLibrary/PagSeguroLibrary.php';
include_once dirname(__FILE__) . '/features/modules/pagsegurofactoryinstallmodule.php';
include_once dirname(__FILE__) . '/features/util/encryptionIdPagSeguro.php';

if (! defined('_PS_VERSION_')) {
    exit();
}


class PagSeguro extends PaymentModule {

    private $modulo;

    protected $errors = array();

    public $context;

    private $pageId = '1';

    public function __construct() {

        $this->name = 'pagseguro';
        $this->tab = 'payments_gateways';
        $this->version = '2.0';
        $this->author = 'PagSeguro Internet LTDA.';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Pagseguro');
        $this->description = $this->l('Receba pagamentos por cartão de crédito, transferência bancária e boleto.');
        $this->confirmUninstall = $this->l('Tem certeza que deseja remover este módulo?');

        if (version_compare(_PS_VERSION_, '1.5.0.2', '<')) {
            include_once (dirname(__FILE__) . '/backward_compatibility/backward.php');
        }

        $this->setContext();

        $this->modulo = PagSeguroFactoryInstallModule::createModule(_PS_VERSION_);

    }

    public function install() {

        if (version_compare(PagSeguroLibrary::getVersion(), '2.1.8', '<=')) {
            if (! $this->validatePagSeguroRequirements()) {
                return false;
            }
        }
        if (! $this->validatePagSeguroId()) {
            return  false;
        }

        if (! $this->validateOrderMessage()) {
            return false;
        }
        if (! $this->generatePagSeguroOrderStatus()) {
            return false;
        }
        if (! $this->createTables()) {
            return false;
        }
        if (! $this->modulo->installConfiguration()) {
            return false;
        }
        if (! parent::install() or
            ! $this->registerHook('payment') or
            ! $this->registerHook('paymentReturn') or
            ! Configuration::updateValue('PAGSEGURO_EMAIL', '') or
            ! Configuration::updateValue('PAGSEGURO_TOKEN', '') or
            ! Configuration::updateValue('PAGSEGURO_URL_REDIRECT', '') or
            ! Configuration::updateValue('PAGSEGURO_NOTIFICATION_URL', '') or
            ! Configuration::updateValue('PAGSEGURO_CHARSET', PagSeguroConfig::getData('application', 'charset')) or
            ! Configuration::updateValue('PAGSEGURO_LOG_ACTIVE', PagSeguroConfig::getData('log', 'active')) or
            ! Configuration::updateValue('PAGSEGURO_RECOVERY_ACTIVE', false) or
            ! Configuration::updateValue('PAGSEGURO_CHECKOUT', false) or
            ! Configuration::updateValue(
                'PAGSEGURO_LOG_FILELOCATION',
                PagSeguroConfig::getData('log', 'fileLocation')
            )) {
            return false;
        }

        return true;

    }

    public function uninstall() {

        if (! $this->uninstallOrderMessage()) {
            return false;
        }

        if (! $this->modulo->uninstallConfiguration()) {
            return false;
        }

        if (! Configuration::deleteByName('PAGSEGURO_EMAIL')
        or ! Configuration::deleteByName('PAGSEGURO_TOKEN')
        or ! Configuration::deleteByName('PAGSEGURO_URL_REDIRECT')
        or ! Configuration::deleteByName('PAGSEGURO_NOTIFICATION_URL')
        or ! Configuration::deleteByName('PAGSEGURO_CHARSET')
        or ! Configuration::deleteByName('PAGSEGURO_LOG_ACTIVE')
        or ! Configuration::deleteByName('PAGSEGURO_RECOVERY_ACTIVE')
        or ! Configuration::deleteByName('PAGSEGURO_LOG_FILELOCATION')
        or ! Configuration::deleteByName('PS_OS_PAGSEGURO')
        or ! Configuration::deleteByName('PAGSEGURO_CHECKOUT')
        or ! parent::uninstall()) {
            return false;
        }

        return true;
    }


    public function getNotificationUrl() {
        return $this->modulo->getNotificationUrl();
    }

    public function getDefaultRedirectionUrl() {
        return $this->modulo->getDefaultRedirectionUrl();
    }

    public function getJsBehavior() {
        return $this->modulo->getJsBehaviors();
    }

    public function getCssDisplay() {
        return $this->modulo->getCssDisplay();
    }


    public static function returnIdCurrency($value = 'BRL') {
        $sql = 'SELECT `id_currency`
        FROM `' . _DB_PREFIX_ . 'currency`
        WHERE `deleted` = 0
        AND `iso_code` = "' . $value . '"';

        $id_currency = (Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));
        return empty($id_currency) ? 0 : $id_currency[0]['id_currency'];
    }

    public function hookPayment($params) {

        $token = Configuration::get('PAGSEGURO_EMAIL');
        $email = Configuration::get('PAGSEGURO_TOKEN');

        if (!$token || !$email) {
            return false;
        }

        $this->modulo->paymentConfiguration($params);

        $bootstrap = version_compare(_PS_VERSION_, '1.6.0.1', '>');
        $this->addToView('hasBootstrap', $bootstrap);

        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', '/views/templates/hook/payment.tpl');
    }

    public function hookPaymentReturn($params) {
        $this->modulo->returnPaymentConfiguration($params);
        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', '/views/templates/hook/payment_return.tpl');
    }

    /***
     * PrestaShop getContent function steps:
     *
     *  1) Validate and save post confuguration data
     *  2) Route and show current virtual page
     *
     */
    public function getContent() {
        $this->verifyPost();
        return $this->pageRouter();
    }


    /*
    *   Validate and save post confuguration data
    */
    private function verifyPost() {
        if ($this->postValidation()) {
            $this->savePostData();
        }
    }

    /***
     * Shorthand to assign on Smarty
     */
    private function addToView($key, $value) {
        if ($this->context->smarty) {
            $this->context->smarty->assign($key, $value);
        }
    }

    /***
     * Realize post validations according with PagSeguro standards
     * case any inconsistence, return false and assign to view context on $errors array
     */
    private function postValidation() {

        $valid = true;
        $errors = Array();

        if (Tools::isSubmit('pagseguroModuleSubmit')) {

            /** E-mail validation */
            $email = Tools::getValue('pagseguroEmail');
            if ($email) {
                if (Tools::strlen($email) > 60) {
                    $errors[] = $this->invalidFieldSizeMessage('E-MAIL');
                } elseif (! Validate::isEmail($email)) {
                    $errors[] = $this->invalidMailMessage('E-MAIL');
                }
            }

            /** Token validation */
            $token = Tools::getValue('pagseguroToken');
            if ($token && Tools::strlen($token) != 32) {
                $errors[] = $this->invalidFieldSizeMessage('TOKEN');
            }

            /** URL redirect validation */
            $redirectUrl = Tools::getValue('pagseguroRedirectUrl');
            if ($redirectUrl && !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                $errors[] = $this->invalidUrlMessage('URL DE REDIRECIONAMENTO');
            }

            /** Notification url validation */
            $notificationUrl = Tools::getValue('pagseguroNotificationUrl');
            if ($notificationUrl && !filter_var($notificationUrl, FILTER_VALIDATE_URL)) {
                $errors[] = $this->invalidUrlMessage('URL DE NOTIFICAÇÃO');
            }

            /** Charset validation */
            $charset = Tools::getValue('pagseguroCharset');
            if ($charset && !array_key_exists($charset, Util::getCharsetOptions())) {
                $errors[] = $this->invalidValueMessage('CHARSET');
            }

            /** Log validation */
            $logActive = Tools::getValue('pagseguroLogActive');
            if ($logActive && !array_key_exists($logActive, Util::getActive())) {
                $errors[] = $this->invalidValueMessage('LOG');
            }

            /** Recovery validation */
            $recoveryActive = Tools::getValue('pagseguroRecoveryActive');
            if ($recoveryActive && !array_key_exists($recoveryActive, Util::getActive())) {
                $errors[] = $this->invalidValueMessage('Listar transações abandonadas');
            }

            if (count($errors) > 0) {
                $valid = false;
            }

        }

        $this->addToView('errors', $errors);

        return $valid;

    }

    /*
    *   Validation error messages
    */
    private function missedCurrencyMessage() {return sprintf($this->l('Verifique se a moeda REAL está instalada e ativada.')); }
    private function invalidMailMessage($field) {return sprintf($this->l('O campo %s deve ser conter um email válido.'), $field); }
    private function invalidFieldSizeMessage($field) {return sprintf($this->l('O campo %s está com um tamanho inválido'), $field); }
    private function invalidValueMessage($field) {return sprintf($this->l('O campo %s contém um valor inválido.'), $field); }
    private function invalidUrlMessage($field) {return sprintf($this->l('O campo %s deve conter uma url válida.'), $field); }

    /***
     * Save configuration post data
     */
    private function savePostData() {

        if (Tools::isSubmit('pagseguroModuleSubmit')) {

            $charsets = Util::getCharsetOptions();

            $updateData = Array(
                'pagseguroEmail'            => 'PAGSEGURO_EMAIL',
                'pagseguroToken'            => 'PAGSEGURO_TOKEN',
                'pagseguroRedirectUrl'      => 'PAGSEGURO_URL_REDIRECT',
                'pagseguroNotificationUrl'  => 'PAGSEGURO_NOTIFICATION_URL',
                'pagseguroCharset'          => 'PAGSEGURO_CHARSET',
                'pagseguroCheckout'         => 'PAGSEGURO_CHECKOUT',
                'pagseguroLogActive'        => 'PAGSEGURO_LOG_ACTIVE',
                'pagseguroLogFileLocation'  => 'PAGSEGURO_LOG_FILELOCATION',
                'pagseguroRecoveryActive'   => 'PAGSEGURO_RECOVERY_ACTIVE'
            );

            foreach ($updateData as $postIndex => $configIndex) {
                if (isset($_POST[$postIndex])) {
                    Configuration::updateValue($configIndex, Tools::getValue($postIndex));
                }
            }

            /** Verify if log file exists, case not try create */
            if (Tools::getValue('pagseguroLogActive')) {
                $this->verifyLogFile(Tools::getValue('pagseguro_log_dir'));
            }

            $this->addToView('success', true);

        }

    }

    private function prepareAdminToken() {
        $adminToken = Tools::getAdminTokenLite('AdminOrders');
        $this->addToView('adminToken', $adminToken);
        $this->addToView('urlAdminOrder', $_SERVER['SCRIPT_NAME'].'?tab=AdminOrders');
    }

    private function applyDefaultViewData() {
        $this->addToView('module_dir', _PS_MODULE_DIR_ . 'pagseguro/');
        $this->addToView('moduleVersion', $this->version);
        $this->addToView('action_post', Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']));
        $this->addToView('cssFileVersion', $this->getCssDisplay());
        $this->prepareAdminToken();
    }

    /***
     * Route virtual page
     */
    private function pageRouter() {

        $this->applyDefaultViewData();

        $pages = array(
            'config' => array(
                'id' => 1,
                'title' => $this->l('Configuração'),
                'content' => $this->getConfigurationPageHtml(),
                'hasForm' => true,
                'selected' => ($this->pageId == '1')
           ),
            'conciliation' => array(
                'id' => 2,
                'title' => $this->l('Conciliação'),
                'content' => $this->getConciliationPageHtml(),
                'hasForm' => false,
                'selected' => ($this->pageId == '2')
            ),
            'abandoned' => array(
                'id' => 3,
                'title' => $this->l('Abandonadas'),
                'content' => $this->getAbandonedPageHtml(),
                'hasForm' => false,
                'selected' => ($this->pageId == '3')
            ),
            'requirements' => array(
                'id' => 4,
                'title' => $this->l('Requisitos'),
                'content' => $this->getRequirementsPageHtml(),
                'hasForm' => false,
                'selected' => ($this->pageId == '4')
            )
        );

        $this->addToView('pages', $pages);

        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', 'views/templates/admin/main.tpl');

    }

    private function getConfigurationPageHtml() {

        $this->addToView('pageTitle', $this->l('Configuração'));

        $this->addToView('email', Tools::safeOutput(Configuration::get('PAGSEGURO_EMAIL')));
        $this->addToView('token', Tools::safeOutput(Configuration::get('PAGSEGURO_TOKEN')));
        $this->addToView('notificationUrl', $this->getNotificationUrl());
        $this->addToView('redirectUrl', $this->getDefaultRedirectionUrl());

        $charsetOptions = Util::getCharsetOptions();
        $this->addToView('charsetKeys', array_keys($charsetOptions));
        $this->addToView('charsetValues', array_values($charsetOptions));
        $this->addToView('charsetSelected', Configuration::get('PAGSEGURO_CHARSET'));

        $checkoutOptions = Util::getTypeCheckout();
        $this->addToView('checkoutKeys', array_keys($checkoutOptions));
        $this->addToView('checkoutValues', array_values($checkoutOptions));
        $this->addToView('checkoutSelected', Configuration::get('PAGSEGURO_CHECKOUT'));

        $activeOptions = Util::getActive();
        $this->addToView('logActiveKeys', array_keys($activeOptions));
        $this->addToView('logActiveValues', array_values($activeOptions));
        $this->addToView('logActiveSelected', Configuration::get('PAGSEGURO_LOG_ACTIVE'));
        $this->addToView('logFileLocation', Tools::safeOutput(Configuration::get('PAGSEGURO_LOG_FILELOCATION')));

        $this->addToView('recoveryActiveKeys', array_keys($activeOptions));
        $this->addToView('recoveryActiveValues', array_values($activeOptions));
        $this->addToView('recoveryActiveSelected', Configuration::get('PAGSEGURO_RECOVERY_ACTIVE'));

        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', '/views/templates/admin/settings.tpl');

    }

    private function getConciliationPageHtml() {

        $this->addToView('pageTitle', $this->l('Conciliação'));

        if ( Configuration::get('PAGSEGURO_EMAIL') && Configuration::get('PAGSEGURO_TOKEN') ) {
            $this->addToView('hasCredentials', true);
            $conciliationSearch = Util::getDaysSearch();
            $this->addToView('conciliationSearchKeys', array_keys($conciliationSearch));
            $this->addToView('conciliationSearchValues', array_values($conciliationSearch));
        }

        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', '/views/templates/admin/conciliation.tpl');

    }

    private function getAbandonedPageHtml() {

        $this->addToView('pageTitle', 'Transações Abandonadas');

        $recoveryActive = Configuration::get('PAGSEGURO_RECOVERY_ACTIVE');

        if ($recoveryActive) {

            $this->addToView('recoveryActive', true);

            $daysToRecoveryOptions = Util::getDaysRecovery();
            $this->addToView('daysToRecoveryKeys', array_values($daysToRecoveryOptions));
            $this->addToView('daysToRecoveryValues', array_values($daysToRecoveryOptions));
        }

        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', '/views/templates/admin/abandoned.tpl');

    }


    private function getRequirementsPageHtml() {

        $this->addToView('pageTitle', $this->l('Requisitos'));

        $requirements = array();

        $validation = PagSeguroConfig::validateRequirements();

        foreach ($validation as $key => $value) {
            if (Tools::strlen($value) == 0) {
                $requirements[$key][0] = true;
                $requirements[$key][1] = null;
            } else {
                $requirements[$key][0] = false;
                $requirements[$key][1] = $value;
            }
        }

        $currency = self::returnIdCurrency();

        /** Currency validation */
        if ($currency) {
            $requirements['moeda'][0] = true;
            $requirements['moeda'][1] = $this->l('Moeda REAL instalada.');
        } else {
            $requirements['moeda'][0] = false;
            $requirements['moeda'][1] = $this->missedCurrencyMessage();
        }

        $requirements['curl'][1] 	= (is_null($requirements['curl'][1])        ? $this->l('Biblioteca cURL instalada.') : $requirements['curl'][1]);
        $requirements['dom'][1] 	= (is_null($requirements['dom'][1])         ? $this->l('DOM XML instalado.') : $requirements['dom'][1]);
        $requirements['spl'][1] 	= (is_null($requirements['spl'][1])         ? $this->l('Biblioteca padrão do PHP(SPL) instalada.') : $requirements['spl'][1]);
        $requirements['version'][1]     = (is_null($requirements['version'][1])     ? $this->l('Versão do PHP superior à 5.3.3.') : $requirements['version'][1]);

        $this->addToView('requirements', $requirements);

        return $this->display(__PS_BASE_URI__ . 'modules/pagseguro', '/views/templates/admin/requirements.tpl');

    }

    private function setContext() {
        $this->context = Context::getContext();
    }

    private function validatePagSeguroRequirements() {
        $condional = true;

        foreach (PagSeguroConfig::validateRequirements() as $value) {
            if (! Tools::isEmpty($value)) {
                $condional = false;
                $this->errors[] = Tools::displayError($value);
            }
        }

        if (! $condional) {
            $this->html = $this->displayError(implode('<br />', $this->errors));
        }

        return $condional;
    }

    private function createTables() {

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pagseguro_order` (
                `id` int(11) unsigned NOT NULL auto_increment,
                `id_transaction` varchar(255) NOT NULL,
                `id_order` int(10) unsigned NOT NULL,
                PRIMARY KEY  (`id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8  auto_increment=1;
        ';

        if (Db::getInstance()->Execute($sql)) {
            return $this->alterTables();
        }

        return false;
    }

    private function alterTables() {

        $sql = '
            SELECT COUNT(*) AS hascol
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE column_name   = \'send_recovery\'
                AND table_name      = \'' . _DB_PREFIX_ . 'pagseguro_order\'
                AND table_schema    = \'' . _DB_NAME_ . '\'
                LIMIT 0, 1
        ';

        if ($hasColQuery = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS($sql)) {

            if (!(int)$hasColQuery[0]['hascol']) {
                return Db::getInstance()->Execute('
                    ALTER TABLE `' . _DB_PREFIX_ . 'pagseguro_order` ADD COLUMN
                    `send_recovery` int(10) unsigned NOT NULL default 0
                ');
            }

            return true;

        }

        return false;

    }

    private function validatePagSeguroId() {
        $id = Configuration::get('PAGSEGURO_ID');
        if (empty($id)) {
            $id = EncryptionIdPagSeguro::idRandomGenerator();
            return Configuration::updateValue('PAGSEGURO_ID', $id);
        }
        return true;
    }

    private function validateOrderMessage() {

        $orderMensagem = new OrderMessage();

        foreach (Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $orderMensagem->name[$idLang] = "cart recovery pagseguro";
            $orderMensagem->message[$idLang] = $this->l('Verificamos que você não concluiu sua compra. Clique no link abaixo para dar prosseguimento.');
        }

        $orderMensagem->date_add = date('now');
        $orderMensagem->save();

        return Configuration::updateValue('PAGSEGURO_MESSAGE_ORDER_ID', $orderMensagem->id);
    }

    private function uninstallOrderMessage() {

        $orders = array();
        $sql = "SELECT `id_order_message` as id FROM `"._DB_PREFIX_."order_message_lang` WHERE `name` = 'cart recovery pagseguro'";
        $result = (Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));

        if ($result) {

            $bool = false;
            foreach ($result as $order_message) {

                if (!$bool) {

                    $orders[] = $order_message['id'];
                    $bool = true;
                } else {

                    if ( array_search($order_message['id'], $orders) === false){
                        $orders[] = $order_message['id'];
                    }
                }
            }

            for($i = 0; $i < count($orders) ;$i++){

                $sql = "DELETE FROM `"._DB_PREFIX_."order_message` WHERE `id_order_message` = '".$orders[$i]."'";
                Db::getInstance()->execute($sql);
            }

            for($i = 0; $i < count($result) ;$i++){
                $id = $result[$i]['id'];
                $sql = "DELETE FROM `"._DB_PREFIX_."order_message_lang` WHERE `id_order_message` = '".$id."'";
                Db::getInstance()->execute($sql);
            }
            return true;
        }
        return false;
    }

    private function generatePagSeguroOrderStatus() {

        $orders_added = true;
        $name_state = null;
        $image = _PS_ROOT_DIR_ . '/modules/pagseguro/logo.gif';

        foreach (Util::getCustomOrderStatusPagSeguro() as $key => $statusPagSeguro) {

            $order_state = new OrderState();
            $order_state->module_name = 'pagseguro';
            $order_state->send_email = $statusPagSeguro['send_email'];
            $order_state->color = '#95D061';
            $order_state->hidden = $statusPagSeguro['hidden'];
            $order_state->delivery = $statusPagSeguro['delivery'];
            $order_state->logable = $statusPagSeguro['logable'];
            $order_state->invoice = $statusPagSeguro['invoice'];

            if (version_compare(_PS_VERSION_, '1.5', '>')) {
                $order_state->unremovable = $statusPagSeguro['unremovable'];
                $order_state->shipped = $statusPagSeguro['shipped'];
                $order_state->paid = $statusPagSeguro['paid'];
            }

            $order_state->name = array();
            $order_state->template = array();
            $continue = false;

            foreach (Language::getLanguages(false) as $language) {

                $list_states = $this->findOrderStates($language['id_lang']);

                $continue = $this->checkIfOrderStatusExists(
                    $language['id_lang'],
                    $statusPagSeguro['name'],
                    $list_states
                );

                if ($continue) {
                    $order_state->name[(int) $language['id_lang']] = $statusPagSeguro['name'];
                    $order_state->template[$language['id_lang']] = $statusPagSeguro['template'];
                }

                if ($key == 'WAITING_PAYMENT' or $key == 'IN_ANALYSIS') {

                    $this->copyMailTo($statusPagSeguro['template'], $language['iso_code'], 'html');
                    $this->copyMailTo($statusPagSeguro['template'], $language['iso_code'], 'txt');
                }

            }

            if ($continue) {

                if ($order_state->add()) {

                    $file = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.gif';
                    copy($image, $file);

                }
            }

            if ($key == 'INITIATED') {
                $name_state = $statusPagSeguro['name'];
            }
        }

        Configuration::updateValue('PS_OS_PAGSEGURO', $this->returnIdOrderByStatusPagSeguro($name_state));

        return $orders_added;
    }

    private function copyMailTo($name, $lang, $ext) {

        $template = _PS_MAIL_DIR_.$lang.'/'.$name.'.'.$ext;

        if (! file_exists($template)) {

            $templateToCopy = _PS_ROOT_DIR_ . '/modules/pagseguro/mails/' . $name .'.'. $ext;
            copy($templateToCopy, $template);

        }
    }

    private function findOrderStates($lang_id) {

        $sql = 'SELECT DISTINCT osl.`id_lang`, osl.`name`
            FROM `' . _DB_PREFIX_ . 'order_state` os
            INNER JOIN `' .
             _DB_PREFIX_ . 'order_state_lang` osl ON (os.`id_order_state` = osl.`id_order_state`)
            WHERE osl.`id_lang` = '."$lang_id".' AND osl.`name` in ("Iniciado","Aguardando pagamento",
            "Em análise", "Paga","Disponível","Em disputa","Devolvida","Cancelada") AND os.`id_order_state` <> 6';

        return (Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));
    }

    private function returnIdOrderByStatusPagSeguro($nome_status) {

        $isDeleted = version_compare(_PS_VERSION_, '1.5', '<') ? '' : 'WHERE deleted = 0';

        $sql = 'SELECT distinct os.`id_order_state`
            FROM `' . _DB_PREFIX_ . 'order_state` os
            INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
            ON (os.`id_order_state` = osl.`id_order_state` AND osl.`name` = \'' .
             pSQL($nome_status) . '\')' . $isDeleted;

        $id_order_state = (Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql));

        return $id_order_state[0]['id_order_state'];
    }

    private function checkIfOrderStatusExists($id_lang, $status_name, $list_states) {

        if (Tools::isEmpty($list_states) or empty($list_states) or ! isset($list_states)) {
            return true;
        }

        $save = true;
        foreach ($list_states as $state) {

            if ($state['id_lang'] == $id_lang && $state['name'] == $status_name) {
                $save = false;
                break;
            }
        }

        return $save;
    }

    /***
     * Verify if PagSeguro log file exists.
     * Case log file not exists, try create
     * else create PagSeguro.log into PagseguroLibrary folder into module
     */
    private function verifyLogFile($file) {
        $file = _PS_ROOT_DIR_ . $file;
        try {
        	if (is_file($file)) {
	            $handle = @fopen($file, 'a');
	            if (is_resource($handle)){
	            	fclose($handle);
	            }
        	}
        } catch (Exception $e) {
            die($e->getMessage());
        }
    }

    private function _whichVersion() {
        if(version_compare(_PS_VERSION_, '1.6.0.1', ">=")){
            $version = '6';
        } else if(version_compare(_PS_VERSION_, '1.5.0.1', "<")){
            $version = '4';
        } else {
            $version = '5';
        }
        return $version;
    }

}
