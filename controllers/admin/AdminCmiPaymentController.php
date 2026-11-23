<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use Cmi\PrestaShop\Admin\OrderPanel;
use Cmi\PrestaShop\Payment\Amounts;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Runs the actions of the order page's CMI panel: Confirm, Void, Refund, Check CMI.
 * Hidden from the menu; protected by the back-office token and the employee's right to edit orders.
 *
 * @property Cmi $module
 */
class AdminCmiPaymentController extends ModuleAdminController
{
    private const ACTIONS = ['confirm', 'void', 'refund', 'check'];

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Whoever may edit orders may manage their CMI payments. The parent check comes first:
     * it validates the back-office token, which protects these actions against CSRF.
     */
    public function checkAccess()
    {
        if (!parent::checkAccess()) {
            return false;
        }
        $employee = $this->context->employee;
        if (!$employee || !$employee->id) {
            return false;
        }

        return (bool) $employee->isSuperAdmin()
            || Access::isGranted('ROLE_MOD_TAB_ADMINORDERS_UPDATE', (int) $employee->id_profile);
    }

    public function postProcess()
    {
        if ($this->ajax) {
            return;
        }
        $action = (string) Tools::getValue('cmi_action');
        $transaction = OrderPanel::findTransaction((int) Tools::getValue('id_cmi_transaction'));
        if (!Tools::isSubmit('cmi_action') || !in_array($action, self::ACTIONS, true) || !$transaction) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders'));
        }
        if (!$this->canManageShop((int) $transaction->id_shop)) {
            OrderPanel::setFlash($this->context, false, $this->trans('You cannot manage the orders of this shop.', [], 'Modules.Cmi.Admin'));
            $this->redirectBack((int) $transaction->id_order);
        }

        $manager = $this->module->services()->manager();
        switch ($action) {
            case 'confirm':
                $result = $manager->confirm($transaction);
                break;
            case 'void':
                $result = $manager->void($transaction);
                break;
            case 'refund':
                $type = (string) Tools::getValue('refund_type');
                $amount = $type === 'partial' ? Amounts::parse(Tools::getValue('refund_amount')) : null;
                if ($type === 'partial' && $amount <= 0) {
                    OrderPanel::setFlash($this->context, false, $this->trans('Enter a valid refund amount.', [], 'Modules.Cmi.Admin'));
                    $this->redirectBack((int) $transaction->id_order);
                }
                $reason = substr(trim(strip_tags((string) Tools::getValue('refund_reason'))), 0, 255);
                $result = $manager->refund($transaction, $amount, $reason);
                break;
            default:
                $result = $manager->check($transaction);
        }
        OrderPanel::setFlash($this->context, $result->success, $result->message);
        $this->redirectBack((int) $transaction->id_order);
    }

    /** Asked when the merchant clicks Confirm: how old the pre-authorization is. */
    public function displayAjaxPreauthAge()
    {
        if (!$this->checkToken()) {
            http_response_code(403);
            $this->ajaxRender('{"days":0}');

            return;
        }
        $transaction = OrderPanel::findTransaction((int) Tools::getValue('id_cmi_transaction'));
        $days = $transaction && $this->canManageShop((int) $transaction->id_shop)
            ? $this->module->services()->manager()->preauthWarningDays($transaction)
            : 0;
        header('Content-Type: application/json');
        $this->ajaxRender((string) json_encode(['days' => $days]));
    }

    /**
     * The CMI column of the orders list: for each order shown, its CMI payment and the actions
     * possible now, and the result of the last action made from the list.
     */
    public function displayAjaxListActions()
    {
        header('Content-Type: application/json');
        if (!$this->checkToken()) {
            http_response_code(403);
            $this->ajaxRender('{"orders":{}}');

            return;
        }
        $panel = new OrderPanel($this->module, $this->module->services(), $this->context);
        $orders = [];
        $ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', (string) Tools::getValue('ids'))))), 0, 300);
        foreach ($ids as $idOrder) {
            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name || !$this->canManageShop((int) $order->id_shop)) {
                continue;
            }
            $entry = $panel->listEntry($order);
            if ($entry !== null) {
                $orders[$idOrder] = $entry;
            }
        }
        $this->ajaxRender((string) json_encode(['orders' => (object) $orders, 'flash' => $panel->flash()]));
    }

    private function canManageShop(int $idShop): bool
    {
        if (!Shop::isFeatureActive() || $this->context->employee->isSuperAdmin()) {
            return true;
        }

        return in_array($idShop, array_map('intval', $this->context->employee->getAssociatedShops()), true);
    }

    /** Back to where the action was made: the orders list or the order's page. */
    private function redirectBack(int $idOrder): void
    {
        if (Tools::getValue('back') === 'list') {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders'));
        }
        $this->redirectToOrder($idOrder);
    }

    private function redirectToOrder(int $idOrder): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => $idOrder, 'vieworder' => 1]));
    }
}
